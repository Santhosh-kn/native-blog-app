package com.bbs.plugins.native_calendar

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import org.json.JSONTokener
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import java.util.UUID
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

internal data class NativeCalendarPendingHandle(
    val id: String,
    val token: String,
    val processId: String,
    val phase: String
) {
    override fun toString(): String =
        "NativeCalendarPendingHandle(redacted)"
}

internal data class NativeCalendarPending(
    val handle: NativeCalendarPendingHandle,
    val result: NativeCalendarResult
) {
    override fun toString(): String =
        "NativeCalendarPending(redacted)"
}

internal sealed interface NativeCalendarBeginResult {

    data class Started(
        val pending: NativeCalendarPending
    ) : NativeCalendarBeginResult {
        override fun toString(): String =
            "NativeCalendarBeginResult.Started(redacted)"
    }

    data class Rejected(
        val result: NativeCalendarResult
    ) : NativeCalendarBeginResult {
        override fun toString(): String =
            "NativeCalendarBeginResult.Rejected(redacted)"
    }
}

/**
 * Durable request metadata only.
 *
 * This class does not accept event titles, descriptions, locations, dates,
 * event IDs, recurrence rules, time zones, or complete request objects.
 *
 * A previous process's pending launch becomes unknown. It is never replayed.
 * Cleanup runs on each transaction and must also be requested on app resume.
 * All disk operations must run on the coordinator's worker thread.
 */
internal class NativeCalendarStore(
    private val stateFile: NativeCalendarStateFile,
    private val clock: () -> Long = { System.currentTimeMillis() },
    private val processId: String = CURRENT_PROCESS_ID
) {

    constructor(context: Context) : this(
        NativeCalendarAtomicStateFile.forContext(context)
    )

    init {
        require(Contract.requestId(processId) == processId) {
            "Native Calendar process identity is invalid."
        }
    }

    override fun toString(): String =
        "NativeCalendarStore(private)"

    fun begin(
        id: String,
        operation: String,
        target: String
    ): NativeCalendarBeginResult {
        if (Contract.requestId(id) != id) {
            return NativeCalendarBeginResult.Rejected(
                NativeCalendarResult.failure(
                    id,
                    Contract.INVALID_REQUEST_ID,
                    operation,
                    target
                )
            )
        }

        if (!Contract.isBinding(operation, target)) {
            return NativeCalendarBeginResult.Rejected(
                NativeCalendarResult.failure(
                    id,
                    Contract.INVALID_OPTIONS,
                    operation,
                    target
                )
            )
        }

        return withState { state, now ->
            fun rejected(code: String): NativeCalendarBeginResult =
                NativeCalendarBeginResult.Rejected(
                    NativeCalendarResult.failure(id, code, operation, target)
                )

            if (state.results.any { it.id == id }) {
                return@withState rejected(Contract.REQUEST_ALREADY_EXISTS)
            }

            if (state.active != null) {
                return@withState rejected(Contract.REQUEST_IN_PROGRESS)
            }

            if (state.results.size >= Contract.MAX_STORED_RESULTS) {
                val oldest = state.results.withIndex()
                    .filter { it.value.isTerminal }
                    .minByOrNull { requireNotNull(it.value.completedAtMs) }
                    ?: throw NativeCalendarStorageException()

                state.results.removeAt(oldest.index)
            }

            val result = NativeCalendarResult.pending(
                id = id,
                operation = operation,
                target = target,
                createdAtMs = now
            )

            val handle = NativeCalendarPendingHandle(
                id = id,
                token = UUID.randomUUID().toString(),
                processId = processId,
                phase = PHASE_QUEUED
            )

            state.results.add(result)
            state.active = handle

            // No pending acknowledgment before verified durable storage.
            writeState(state)

            NativeCalendarBeginResult.Started(
                NativeCalendarPending(handle, result)
            )
        }
    }

    fun getStatus(id: String): NativeCalendarResult {
        if (Contract.requestId(id) != id) {
            return NativeCalendarResult.failure(id, Contract.INVALID_REQUEST_ID)
        }

        return withState { state, _ ->
            state.results.firstOrNull { it.id == id }
                ?: NativeCalendarResult.notFound(id)
        }
    }

    fun activePending(): NativeCalendarPending? =
        withState { state, _ ->
            val handle = state.active ?: return@withState null
            val result = state.results.first { it.id == handle.id }

            NativeCalendarPending(handle, result)
        }

    fun markLaunching(
        handle: NativeCalendarPendingHandle
    ): NativeCalendarPendingHandle? =
        withState { state, _ ->
            if (
                !matchesOwnedHandle(state, handle) ||
                handle.phase != PHASE_QUEUED
            ) {
                return@withState null
            }

            val updated = handle.copy(phase = PHASE_LAUNCHING)
            state.active = updated
            writeState(state)

            updated
        }

    fun completeLaunched(
        handle: NativeCalendarPendingHandle
    ): NativeCalendarResult? =
        complete(handle, Contract.STATUS_LAUNCHED, null)

    fun completeFailed(
        handle: NativeCalendarPendingHandle,
        errorCode: Any?
    ): NativeCalendarResult? =
        complete(
            handle,
            Contract.STATUS_FAILED,
            Contract.canonicalErrorCode(errorCode)
        )

    fun interrupt(
        handle: NativeCalendarPendingHandle
    ): NativeCalendarResult? =
        complete(handle, Contract.STATUS_UNKNOWN, Contract.INTERRUPTED)

    /**
     * The coordinator calls this on its serialized worker when it no longer
     * owns a pending launch, including an uncertain storage acknowledgment.
     * A matching in-memory token protects a live operation.
     */
    fun interruptUnowned(ownedToken: String?): NativeCalendarResult? =
        withState { state, now ->
            val active = state.active ?: return@withState null

            if (active.processId == processId && active.token == ownedToken) {
                return@withState null
            }

            interruptActive(state, now).also {
                writeState(state)
            }
        }

    fun purgeExpired() {
        withState { _, _ -> Unit }
    }

    private fun complete(
        handle: NativeCalendarPendingHandle,
        terminalStatus: String,
        errorCode: String?
    ): NativeCalendarResult? =
        withState { state, now ->
            if (!matchesOwnedHandle(state, handle)) {
                return@withState null
            }

            if (
                terminalStatus == Contract.STATUS_LAUNCHED &&
                handle.phase != PHASE_LAUNCHING
            ) {
                return@withState null
            }

            val index = state.results.indexOfFirst { it.id == handle.id }
            check(index >= 0)

            val pending = state.results[index]
            val completedAt = maxOf(now, requireNotNull(pending.createdAtMs))

            val completed = when (terminalStatus) {
                Contract.STATUS_LAUNCHED -> pending.launched(completedAt)
                Contract.STATUS_FAILED -> pending.failed(errorCode, completedAt)
                Contract.STATUS_UNKNOWN -> pending.interrupted(completedAt)
                else -> throw NativeCalendarStorageException()
            }

            state.results[index] = completed
            state.active = null

            // Dispatch completion events only after this verified write.
            writeState(state)

            completed
        }

    private fun matchesOwnedHandle(
        state: State,
        handle: NativeCalendarPendingHandle
    ): Boolean =
        handle.processId == processId &&
            Contract.requestId(handle.id) == handle.id &&
            Contract.requestId(handle.token) == handle.token &&
            state.active == handle

    private fun interruptActive(
        state: State,
        now: Long
    ): NativeCalendarResult {
        val active = requireNotNull(state.active)
        val index = state.results.indexOfFirst { it.id == active.id }
        check(index >= 0)

        val pending = state.results[index]
        val completed = pending.interrupted(
            maxOf(now, requireNotNull(pending.createdAtMs))
        )

        state.results[index] = completed
        state.active = null

        return completed
    }

    private fun <T> withState(action: (State, Long) -> T): T {
        return try {
            stateFile.locked {
                val now = Contract.epochMilliseconds(clock())
                    ?: throw NativeCalendarStorageException()

                val state = readState()

                if (maintain(state, now)) {
                    writeState(state)
                }

                action(state, now)
            }
        } catch (_: Exception) {
            // Never retain or print underlying input, state, or paths.
            throw NativeCalendarStorageException()
        }
    }

    private fun maintain(state: State, now: Long): Boolean {
        var changed = false
        var newlyInterruptedId: String? = null

        state.active?.let { active ->
            val pending = state.results.first { it.id == active.id }

            if (
                active.processId != processId ||
                expired(now, requireNotNull(pending.createdAtMs))
            ) {
                newlyInterruptedId = interruptActive(state, now).id
                changed = true
            }
        }

        val iterator = state.results.listIterator()

        while (iterator.hasNext()) {
            val result = iterator.next()

            // Preserve a newly recovered outcome in this transaction.
            if (
                result.isTerminal &&
                result.id != newlyInterruptedId &&
                expired(now, requireNotNull(result.completedAtMs))
            ) {
                iterator.remove()
                changed = true
            }
        }

        return changed
    }

    private fun expired(now: Long, timestamp: Long): Boolean =
        now < timestamp || now - timestamp >= Contract.METADATA_TTL_MS

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
                ?: throw NativeCalendarStorageException()

            check(parser.nextClean() == '\u0000')

            // This plugin writes compact canonical JSON. Requiring the same
            // form also rejects duplicate keys and JSONTokener extensions.
            check(text == json.toString())
            check(json.keys().asSequence().toSet() == STATE_KEYS)
            check(Contract.integer(json.get("version")) == 1L)

            val records = json.get("results") as? JSONArray
                ?: throw NativeCalendarStorageException()

            check(records.length() <= Contract.MAX_STORED_RESULTS)

            val results = mutableListOf<NativeCalendarResult>()

            for (index in 0 until records.length()) {
                val record = records.get(index) as? JSONObject
                    ?: throw NativeCalendarStorageException()

                results.add(
                    NativeCalendarResult.fromStoredJson(record)
                        ?: throw NativeCalendarStorageException()
                )
            }

            val activeValue = json.get("active")
            val active = if (activeValue === JSONObject.NULL) {
                null
            } else {
                val handleJson = activeValue as? JSONObject
                    ?: throw NativeCalendarStorageException()

                check(handleJson.keys().asSequence().toSet() == HANDLE_KEYS)

                NativeCalendarPendingHandle(
                    id = handleJson.get("id") as? String
                        ?: throw NativeCalendarStorageException(),
                    token = handleJson.get("token") as? String
                        ?: throw NativeCalendarStorageException(),
                    processId = handleJson.get("processId") as? String
                        ?: throw NativeCalendarStorageException(),
                    phase = handleJson.get("phase") as? String
                        ?: throw NativeCalendarStorageException()
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

        for (result in state.results) {
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
            check(encoded.isNotEmpty() && encoded.size <= MAX_STATE_BYTES)
            stateFile.writeVerified(encoded)
        } finally {
            encoded.fill(0)
        }
    }

    private fun isValidState(state: State): Boolean {
        if (
            state.results.size > Contract.MAX_STORED_RESULTS ||
            state.results.any {
                !it.accepted ||
                    !Contract.isBinding(it.operation, it.target) ||
                    (it.status != Contract.STATUS_PENDING && !it.isTerminal)
            } ||
            state.results.map { it.id }.toSet().size != state.results.size
        ) {
            return false
        }

        val pending = state.results.filter {
            it.status == Contract.STATUS_PENDING
        }

        val active = state.active ?: return pending.isEmpty()

        return Contract.requestId(active.id) == active.id &&
            Contract.requestId(active.token) == active.token &&
            Contract.requestId(active.processId) == active.processId &&
            active.phase in setOf(PHASE_QUEUED, PHASE_LAUNCHING) &&
            pending.size == 1 &&
            pending.single().id == active.id
    }

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
                    depth += 1
                    check(depth <= 16)
                }
                '}', ']' -> {
                    depth -= 1
                    check(depth >= 0)
                }
            }
        }

        check(!quoted && depth == 0)
    }

    private class State(
        val results: MutableList<NativeCalendarResult> = mutableListOf(),
        var active: NativeCalendarPendingHandle? = null
    ) {
        override fun toString(): String =
            "NativeCalendarState(private)"
    }

    companion object {

        const val PHASE_QUEUED = "queued"
        const val PHASE_LAUNCHING = "launching"

        private val CURRENT_PROCESS_ID = UUID.randomUUID().toString()

        private val STATE_KEYS = setOf("version", "results", "active")
        private val HANDLE_KEYS = setOf("id", "token", "processId", "phase")

        private const val MAX_STATE_BYTES =
            Contract.MAX_STORED_RESULTS * Contract.MAX_RESULT_BYTES + 4_096
    }
}
