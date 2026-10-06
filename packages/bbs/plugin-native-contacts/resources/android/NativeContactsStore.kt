package com.bbs.plugins.native_contacts

import android.content.Context
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract
import org.json.JSONArray
import org.json.JSONObject
import org.json.JSONTokener
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import java.util.UUID

internal data class NativeContactsPendingHandle(
    val id: String,
    val token: String,
    val processId: String,
    val phase: String
) {
    override fun toString(): String =
        "NativeContactsPendingHandle(phase=$phase)"
}

internal data class NativeContactsPending(
    val handle: NativeContactsPendingHandle,
    val result: NativeContactsResult
)

internal sealed interface NativeContactsBeginResult {

    data class Started(
        val pending: NativeContactsPending
    ) : NativeContactsBeginResult

    data class Rejected(
        val result: NativeContactsResult
    ) : NativeContactsBeginResult
}

internal sealed interface NativeContactsConsumeResult {

    data class Metadata(
        val result: NativeContactsResult,
        val errorOverride: String? = null
    ) : NativeContactsConsumeResult

    data class Delivered(
        val result: NativeContactsResult,
        val selection: NativeContactsSelection
    ) : NativeContactsConsumeResult {
        override fun toString(): String =
            "NativeContactsConsumeResult.Delivered(redacted)"
    }
}

/**
 * Stores request metadata and short-lived picker selections.
 *
 * Creation inputs and input URIs are never persisted.
 * Status, completion, and restoration methods return metadata only.
 * Only consume() can return a selection, after verified durable redaction.
 *
 * Expiry cleanup runs on every transaction. The coordinator must also invoke
 * purgeExpired() on resume; expiry does not require a successful consume call.
 */
internal class NativeContactsStore(
    private val stateFile: NativeContactsStateFile,
    private val clock: () -> Long = { System.currentTimeMillis() },
    private val processId: String = CURRENT_PROCESS_ID
) {

    constructor(context: Context) : this(
        NativeContactsAtomicStateFile.forContext(context)
    )

    init {
        require(Contract.requestId(processId) == processId)
    }

    override fun toString(): String = "NativeContactsStore(private)"

    fun begin(request: NativeContactsRequest): NativeContactsBeginResult =
        withState { state, now ->
            require(request.operation in Contract.operations)

            fun rejected(code: String): NativeContactsBeginResult =
                NativeContactsBeginResult.Rejected(
                    NativeContactsResult.failed(
                        id = request.id,
                        errorCode = code,
                        operation = request.operation,
                        mode = request.mode
                    )
                )

            if (state.results.any { it.id == request.id }) {
                return@withState rejected(Contract.REQUEST_ID_REUSED)
            }

            if (state.active != null) {
                return@withState rejected(Contract.OPERATION_BUSY)
            }

            // Retain a bounded cache. Never evict an active pending request.
            if (state.results.size >= Contract.MAX_STORED_RESULTS) {
                val oldest = state.results.withIndex()
                    .filter { it.value.status in Contract.terminalStatuses }
                    .minByOrNull { requireNotNull(it.value.completedAtMs) }
                    ?: throw NativeContactsStorageException()

                state.results.removeAt(oldest.index)
            }

            val result = NativeContactsResult.pending(request, now)
            val handle = NativeContactsPendingHandle(
                id = request.id,
                token = UUID.randomUUID().toString(),
                processId = processId,
                phase = PHASE_QUEUED
            )

            state.results.add(result)
            state.active = handle
            writeState(state)

            NativeContactsBeginResult.Started(
                NativeContactsPending(handle, result)
            )
        }

    fun getStatus(id: String): NativeContactsResult =
        withState { state, _ ->
            require(Contract.requestId(id) == id)

            metadataOnly(
                state.results.firstOrNull { it.id == id }
                    ?: NativeContactsResult.notFound(id)
            )
        }

    fun activePending(): NativeContactsPending? =
        withState { state, _ ->
            val handle = state.active ?: return@withState null
            val result = state.results.first { it.id == handle.id }

            NativeContactsPending(handle, metadataOnly(result))
        }

    fun markAwaitingPicker(
        handle: NativeContactsPendingHandle
    ): NativeContactsPendingHandle? =
        markPhase(handle, PHASE_AWAITING_PICKER)

    /**
     * Persist that the picker callback has arrived before reading its URI.
     * A previous process's reading phase is interrupted, never replayed.
     */
    fun markReadingPicker(
        handle: NativeContactsPendingHandle
    ): NativeContactsPendingHandle? =
        withState { state, _ ->
            if (!matchesOwnedHandle(state, handle)) {
                return@withState null
            }

            val active = requireNotNull(state.active)
            if (active.phase != PHASE_AWAITING_PICKER) {
                return@withState null
            }

            val updated = active.copy(phase = PHASE_READING_PICKER)
            state.active = updated
            writeState(state)
            updated
        }

    fun markLaunching(
        handle: NativeContactsPendingHandle
    ): NativeContactsPendingHandle? =
        markPhase(handle, PHASE_LAUNCHING)

    private fun markPhase(
        handle: NativeContactsPendingHandle,
        phase: String
    ): NativeContactsPendingHandle? =
        withState { state, _ ->
            if (!matchesOwnedHandle(state, handle)) {
                return@withState null
            }

            val active = requireNotNull(state.active)
            if (active.phase != PHASE_QUEUED) {
                return@withState null
            }

            val result = state.results.first { it.id == active.id }

            val permitted = when (phase) {
                PHASE_AWAITING_PICKER -> result.operation == Contract.PICK
                PHASE_LAUNCHING -> result.operation != Contract.PICK
                else -> false
            }

            if (!permitted) {
                return@withState null
            }

            val updated = active.copy(phase = phase)
            state.active = updated
            writeState(state)
            updated
        }

    /**
     * Restore only an awaiting picker whose ID and callback token were saved
     * by the Fragment. Never bind a callback to whatever request is active.
     */
    fun restorePicker(
        id: String,
        token: String
    ): NativeContactsPending? =
        withState { state, _ ->
            if (
                Contract.requestId(id) != id ||
                Contract.requestId(token) != token
            ) {
                return@withState null
            }

            val active = state.active ?: return@withState null
            if (
                active.id != id ||
                active.token != token ||
                active.phase != PHASE_AWAITING_PICKER
            ) {
                return@withState null
            }

            val result = state.results.first { it.id == id }
            if (result.operation != Contract.PICK) {
                return@withState null
            }

            val restored = active.copy(processId = processId)

            if (restored != active) {
                state.active = restored
                writeState(state)
            }

            NativeContactsPending(restored, metadataOnly(result))
        }

    /**
     * Call after attempting Fragment restoration. An old process's operation
     * that cannot be restored becomes unknown; it is never replayed.
     */
    fun interruptPreviousProcess(): NativeContactsResult? =
        withState { state, now ->
            val active = state.active ?: return@withState null

            if (active.processId == processId) {
                return@withState null
            }

            val index = state.results.indexOfFirst { it.id == active.id }
            check(index >= 0)

            val interrupted = state.results[index].complete(
                terminalStatus = Contract.STATUS_UNKNOWN,
                error = Contract.OPERATION_INTERRUPTED,
                now = now
            )

            state.results[index] = interrupted
            state.active = null
            writeState(state)
            metadataOnly(interrupted)
        }

    /**
     * A stale callback cannot complete a later request, even if its ID is reused.
     * Persist completion before the caller dispatches a metadata-only event.
     */
    fun complete(
        handle: NativeContactsPendingHandle,
        terminalStatus: String,
        errorCode: String? = null,
        selection: NativeContactsSelection? = null
    ): NativeContactsResult? =
        withState { state, now ->
            if (!matchesOwnedHandle(state, handle)) {
                return@withState null
            }

            val active = requireNotNull(state.active)

            if (
                terminalStatus in setOf(
                    Contract.STATUS_SELECTED,
                    Contract.STATUS_CANCELLED
                ) &&
                active.phase !in setOf(
                    PHASE_AWAITING_PICKER,
                    PHASE_READING_PICKER
                )
            ) {
                return@withState null
            }

            if (
                terminalStatus == Contract.STATUS_LAUNCHED &&
                active.phase != PHASE_LAUNCHING
            ) {
                return@withState null
            }

            val index = state.results.indexOfFirst { it.id == active.id }
            check(index >= 0)

            val completed = state.results[index].complete(
                terminalStatus = terminalStatus,
                error = errorCode,
                selected = selection,
                now = now
            )

            state.results[index] = completed
            state.active = null
            writeState(state)
            metadataOnly(completed)
        }

    fun consume(id: String): NativeContactsConsumeResult =
        withState { state, _ ->
            require(Contract.requestId(id) == id)

            val index = state.results.indexOfFirst { it.id == id }

            if (index < 0) {
                return@withState NativeContactsConsumeResult.Metadata(
                    NativeContactsResult.notFound(id)
                )
            }

            val result = state.results[index]

            if (result.operation != Contract.PICK) {
                return@withState NativeContactsConsumeResult.Metadata(
                    NativeContactsResult.failed(
                        id = id,
                        errorCode = Contract.INVALID_PARAMETERS,
                        operation = result.operation,
                        mode = result.mode
                    )
                )
            }

            if (result.status == Contract.STATUS_PENDING) {
                return@withState NativeContactsConsumeResult.Metadata(
                    metadataOnly(result),
                    Contract.RESULT_NOT_READY
                )
            }

            if (result.status != Contract.STATUS_SELECTED) {
                return@withState NativeContactsConsumeResult.Metadata(
                    metadataOnly(result)
                )
            }

            if (result.consumed) {
                return@withState NativeContactsConsumeResult.Metadata(
                    metadataOnly(result),
                    Contract.RESULT_ALREADY_CONSUMED
                )
            }

            val selected = requireNotNull(result.selection)
            val redacted = result.copy(
                consumed = true,
                selection = null
            )

            state.results[index] = redacted

            // No selection leaves this method if writing, syncing, or verifying
            // the redacted state fails.
            writeState(state)

            NativeContactsConsumeResult.Delivered(
                result = redacted,
                selection = selected
            )
        }

    fun purgeExpired() {
        withState { _, _ -> Unit }
    }

    private fun matchesOwnedHandle(
        state: State,
        handle: NativeContactsPendingHandle
    ): Boolean {
        val active = state.active ?: return false

        return active.id == handle.id &&
            active.token == handle.token &&
            active.processId == processId &&
            handle.processId == processId
    }

    private fun metadataOnly(
        result: NativeContactsResult
    ): NativeContactsResult =
        result.copy(selection = null)

    private fun <T> withState(
        action: (State, Long) -> T
    ): T {
        try {
            return stateFile.locked {
                val now = clock()
                check(now > 0L)

                val state = readState()

                if (expireAndPrune(state, now)) {
                    writeState(state)
                }

                action(state, now)
            }
        } catch (_: Exception) {
            throw NativeContactsStorageException()
        }
    }

    private fun expireAndPrune(
        state: State,
        now: Long
    ): Boolean {
        var changed = false
        val iterator = state.results.listIterator()

        while (iterator.hasNext()) {
            val original = iterator.next()
            var result = original

            if (
                result.status == Contract.STATUS_PENDING &&
                expired(
                    now,
                    requireNotNull(result.createdAtMs),
                    Contract.METADATA_TTL_MS
                )
            ) {
                result = result.complete(
                    terminalStatus = Contract.STATUS_UNKNOWN,
                    error = Contract.OPERATION_INTERRUPTED,
                    now = now
                )
                state.active = null
            }

            if (
                result.status == Contract.STATUS_SELECTED &&
                !result.consumed &&
                expired(
                    now,
                    requireNotNull(result.completedAtMs),
                    Contract.SELECTION_TTL_MS
                )
            ) {
                result = result.expiredSelection()
            }

            if (
                result.status in Contract.terminalStatuses &&
                expired(
                    now,
                    requireNotNull(result.completedAtMs),
                    Contract.METADATA_TTL_MS
                )
            ) {
                iterator.remove()
                changed = true
                continue
            }

            if (result !== original) {
                iterator.set(result)
                changed = true
            }
        }

        return changed
    }

    private fun expired(now: Long, timestamp: Long, ttl: Long): Boolean =
        now < timestamp || now - timestamp >= ttl

    private fun readState(): State {
        val bytes = stateFile.read() ?: return State()

        try {
            check(bytes.isNotEmpty() && bytes.size <= MAX_STATE_BYTES)

            val text = Charsets.UTF_8.newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)
                .decode(ByteBuffer.wrap(bytes))
                .toString()

            checkJsonDepth(text)

            val parser = JSONTokener(text)
            val json = parser.nextValue() as? JSONObject
                ?: throw NativeContactsStorageException()

            check(parser.nextClean() == '\u0000')
            check(json.keys().asSequence().toSet() == STATE_KEYS)
            check(json.opt("version") == 1)

            val records = json.opt("results") as? JSONArray
                ?: throw NativeContactsStorageException()

            check(records.length() <= Contract.MAX_STORED_RESULTS)

            val results = mutableListOf<NativeContactsResult>()

            for (index in 0 until records.length()) {
                val record = records.opt(index) as? JSONObject
                    ?: throw NativeContactsStorageException()

                results.add(
                    NativeContactsResult.fromStoredJson(record)
                        ?: throw NativeContactsStorageException()
                )
            }

            val activeValue = json.opt("active")
            val active = if (activeValue === JSONObject.NULL) {
                null
            } else {
                val handleJson = activeValue as? JSONObject
                    ?: throw NativeContactsStorageException()

                check(
                    handleJson.keys().asSequence().toSet() == HANDLE_KEYS
                )

                NativeContactsPendingHandle(
                    id = handleJson.opt("id") as? String
                        ?: throw NativeContactsStorageException(),
                    token = handleJson.opt("token") as? String
                        ?: throw NativeContactsStorageException(),
                    processId = handleJson.opt("processId") as? String
                        ?: throw NativeContactsStorageException(),
                    phase = handleJson.opt("phase") as? String
                        ?: throw NativeContactsStorageException()
                )
            }

            return State(results, active).also {
                check(isValidState(it))
            }
        } finally {
            bytes.fill(0)
        }
    }

    private fun writeState(state: State) {
        check(isValidState(state))

        val records = JSONArray()

        state.results.forEach { result ->
            val record = result.toStoredJson()
            val encoded = record.toString().toByteArray(Charsets.UTF_8)

            try {
                check(encoded.size <= Contract.MAX_RESULT_BYTES)
            } finally {
                encoded.fill(0)
            }

            records.put(record)
        }

        val activeJson = state.active?.let { handle ->
            JSONObject().apply {
                put("id", handle.id)
                put("token", handle.token)
                put("processId", handle.processId)
                put("phase", handle.phase)
            }
        }

        val json = JSONObject().apply {
            put("version", 1)
            put("results", records)
            put("active", activeJson ?: JSONObject.NULL)
        }

        val encoded = json.toString().toByteArray(Charsets.UTF_8)

        try {
            check(encoded.size <= MAX_STATE_BYTES)
            stateFile.writeVerified(encoded)
        } finally {
            encoded.fill(0)
        }
    }

    private fun isValidState(state: State): Boolean {
        if (
            state.results.size > Contract.MAX_STORED_RESULTS ||
            state.results.any { !it.isValidStored() } ||
            state.results.map { it.id }.toSet().size != state.results.size
        ) {
            return false
        }

        val pending = state.results.filter {
            it.status == Contract.STATUS_PENDING
        }

        val active = state.active
            ?: return pending.isEmpty()

        if (
            Contract.requestId(active.id) != active.id ||
            Contract.requestId(active.token) != active.token ||
            Contract.requestId(active.processId) != active.processId ||
            pending.size != 1 ||
            pending.single().id != active.id
        ) {
            return false
        }

        return when (active.phase) {
            PHASE_QUEUED -> true
            PHASE_AWAITING_PICKER, PHASE_READING_PICKER ->
                pending.single().operation == Contract.PICK
            PHASE_LAUNCHING ->
                pending.single().operation != Contract.PICK
            else -> false
        }
    }

    /**
     * Bound nesting before JSONObject parses potentially damaged private state.
     * Brackets inside quoted strings do not count toward nesting.
     */
    private fun checkJsonDepth(text: String) {
        var depth = 0
        var quoted = false
        var escaped = false

        for (character in text) {
            if (quoted) {
                when {
                    escaped -> escaped = false
                    character == '\\' -> escaped = true
                    character == '"' -> quoted = false
                }
                continue
            }

            when (character) {
                '"' -> quoted = true
                '{', '[' -> {
                    depth++
                    check(depth <= 16)
                }
                '}', ']' -> {
                    depth--
                    check(depth >= 0)
                }
            }
        }

        check(!quoted && depth == 0)
    }

    private class State(
        val results: MutableList<NativeContactsResult> = mutableListOf(),
        var active: NativeContactsPendingHandle? = null
    ) {
        override fun toString(): String = "NativeContactsState(private)"
    }

    companion object {

        const val PHASE_QUEUED = "queued"
        const val PHASE_AWAITING_PICKER = "awaiting_picker"
        const val PHASE_READING_PICKER = "reading_picker"
        const val PHASE_LAUNCHING = "launching"

        private val CURRENT_PROCESS_ID = UUID.randomUUID().toString()

        private val STATE_KEYS = setOf("version", "results", "active")
        private val HANDLE_KEYS = setOf("id", "token", "processId", "phase")

        private const val MAX_STATE_BYTES =
            Contract.MAX_STORED_RESULTS * Contract.MAX_RESULT_BYTES + 4_096
    }
}