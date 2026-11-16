package com.bbs.plugins.native_nfc

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import java.nio.charset.StandardCharsets
import java.math.BigDecimal
import java.util.UUID

/**
 * Identifies one specific NFC operation.
 */
internal data class NativeNfcOperationHandle(
    val id: String,
    val operation: String,
    internal val token: String,
    internal val processId: String
)

/**
 * Possible outcomes when starting an operation.
 */
internal sealed class NativeNfcBeginResult {

    data class Started(
        val handle: NativeNfcOperationHandle
    ) : NativeNfcBeginResult()

    object Busy : NativeNfcBeginResult()

    object DuplicateId : NativeNfcBeginResult()
}

/**
 * Possible outcomes when consuming a scan result.
 */
internal sealed class NativeNfcConsumeResult {

    data class Consumed(
        val text: String
    ) : NativeNfcConsumeResult()

    object NotFound : NativeNfcConsumeResult()

    object NotReady : NativeNfcConsumeResult()

    object AlreadyConsumed : NativeNfcConsumeResult()

    object Expired : NativeNfcConsumeResult()
}

/**
 * Durable metadata only.
 * NFC text payloads must never be included here.
 */
private data class NativeNfcPersistedState(
    val processId: String,
    val results: LinkedHashMap<String, NativeNfcResult>
)

/**
 * Serializes and validates persisted NFC metadata.
 */
private object NativeNfcStateCodec {

    private const val VERSION = 1L
    private const val MAX_STATE_BYTES = 32 * 1024

    fun encode(state: NativeNfcPersistedState): ByteArray {
        require(
            NativeNfcContract.UUID_V4_REGEX.matches(state.processId)
        )

        require(
            state.results.size <= NativeNfcContract.MAX_STORED_RESULTS
        )

        val resultsArray = JSONArray()

        state.results.forEach { (id, result) ->
            require(id == result.id)
            require(result.isValidStored())

            resultsArray.put(result.toStoredJson())
        }

        val json = JSONObject().apply {
            put("version", VERSION)
            put("processId", state.processId)
            put("results", resultsArray)
        }

        val bytes = json.toString()
            .toByteArray(StandardCharsets.UTF_8)

        require(bytes.isNotEmpty() && bytes.size <= MAX_STATE_BYTES)

        return bytes
    }

    fun decode(bytes: ByteArray): NativeNfcPersistedState? {
        if (bytes.isEmpty() || bytes.size > MAX_STATE_BYTES) {
            return null
        }

        return try {
            val decoder = StandardCharsets.UTF_8
                .newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)

            val jsonText = decoder
                .decode(ByteBuffer.wrap(bytes))
                .toString()

            val json = JSONObject(jsonText)

            val expectedKeys = setOf(
                "version",
                "processId",
                "results"
            )

            if (json.keys().asSequence().toSet() != expectedKeys) {
                return null
            }

            val version = readLong(json.opt("version"))
                ?: return null

            if (version != VERSION) return null

            val processId = json.opt("processId") as? String
                ?: return null

            if (!NativeNfcContract.UUID_V4_REGEX.matches(processId)) {
                return null
            }

            val resultsArray = json.opt("results") as? JSONArray
                ?: return null

            if (resultsArray.length() >
                NativeNfcContract.MAX_STORED_RESULTS
            ) {
                return null
            }

            val results = LinkedHashMap<String, NativeNfcResult>()

            for (index in 0 until resultsArray.length()) {
                val resultJson = resultsArray.optJSONObject(index)
                    ?: return null

                val result = NativeNfcResult.fromStoredJson(resultJson)
                    ?: return null

                if (results.containsKey(result.id)) {
                    return null
                }

                results[result.id] = result
            }

            NativeNfcPersistedState(
                processId = processId,
                results = results
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun readLong(value: Any?): Long? {
        if (value !is Number) return null

        return try {
            BigDecimal(value.toString()).longValueExact()
        } catch (_: Exception) {
            null
        }
    }
}

/**
 * Manages NFC operation state and in-memory payloads.
 */
internal class NativeNfcStore(
    private val stateFile: NativeNfcStateFile,
    private val clock: () -> Long = { System.currentTimeMillis() }
) {

    private val processId = PROCESS_ID

    private var activeHandle: NativeNfcOperationHandle?
        get() = synchronized(PROCESS_LOCK) {
            processActiveHandle
        }
        set(value) {
            synchronized(PROCESS_LOCK) {
                processActiveHandle = value
            }
        }

    private val scanPayloads = PROCESS_SCAN_PAYLOADS

    private val writePayloads = PROCESS_WRITE_PAYLOADS

    /**
     * Loads persisted state and recovers interrupted operations.
     */
    private fun loadState(): NativeNfcPersistedState =
        synchronized(PROCESS_LOCK) {
            stateFile.locked {
                val bytes = stateFile.read()
                    ?: return@locked emptyState()

                val decoded = try {
                    NativeNfcStateCodec.decode(bytes)
                        ?: throw NativeNfcStorageException()
                } finally {
                    bytes.fill(0)
                }

                if (decoded.processId == processId) {
                    return@locked decoded
                }

                val recovered = recoverPreviousProcess(decoded)

                saveStateLocked(recovered)

                recovered
            }
        }

    /**
     * Saves state using a storage transaction.
     */
    private fun saveState(state: NativeNfcPersistedState) {
        synchronized(PROCESS_LOCK) {
            stateFile.locked {
                saveStateLocked(state)
            }
        }
    }

    /**
     * Persists state inside an existing storage transaction.
     */
    private fun saveStateLocked(state: NativeNfcPersistedState) {
        val currentState = state.copy(processId = processId)

        val bytes = try {
            NativeNfcStateCodec.encode(currentState)
        } catch (_: IllegalArgumentException) {
            throw NativeNfcStorageException()
        }

        try {
            stateFile.writeVerified(bytes)
        } finally {
            bytes.fill(0)
        }
    }

    /**
     * Creates an empty state for first use.
     */
    private fun emptyState(): NativeNfcPersistedState =
        NativeNfcPersistedState(
            processId = processId,
            results = LinkedHashMap()
        )

    /**
     * Recovers operations whose in-memory payloads were lost.
     */
    private fun recoverPreviousProcess(
        previous: NativeNfcPersistedState
    ): NativeNfcPersistedState {

        val recoveredResults = LinkedHashMap<String, NativeNfcResult>()

        previous.results.forEach { (id, result) ->

            val payloadWasLost =
                result.status == NativeNfcContract.STATUS_PENDING ||
                    (
                        result.status == NativeNfcContract.STATUS_READ &&
                            !result.consumed
                    )

            val recovered = if (payloadWasLost) {
                val recoveryTime = maxOf(
                    clock().coerceAtLeast(1L),
                    result.createdAtMs
                )

                result.complete(
                    terminalStatus = NativeNfcContract.STATUS_UNKNOWN,
                    error = NativeNfcContract.ERROR_OPERATION_INTERRUPTED,
                    now = recoveryTime
                )
            } else {
                result
            }

            recoveredResults[id] = recovered
        }

        return NativeNfcPersistedState(
            processId = processId,
            results = recoveredResults
        )
    }

    /**
     * Reads and recovers state inside an existing storage transaction.
     */
    private fun readStateLocked(): NativeNfcPersistedState {
        val bytes = stateFile.read()
            ?: return emptyState()

        val decoded = try {
            NativeNfcStateCodec.decode(bytes)
                ?: throw NativeNfcStorageException()
        } finally {
            bytes.fill(0)
        }

        if (decoded.processId == processId) {
            return decoded
        }

        val recovered = recoverPreviousProcess(decoded)

        saveStateLocked(recovered)

        return recovered
    }

    /**
     * Starts a new NFC operation.
     *
     * The pending state is persisted before the operation is
     * registered in memory or its write payload is retained.
     */
    internal fun begin(
        id: String,
        operation: String,
        text: String? = null
    ): NativeNfcBeginResult {

        require(NativeNfcContract.UUID_V4_REGEX.matches(id))

        require(
            operation == NativeNfcContract.OP_START_SCAN ||
                operation == NativeNfcContract.OP_START_WRITE
        )

        when (operation) {
            NativeNfcContract.OP_START_SCAN -> require(text == null)

            NativeNfcContract.OP_START_WRITE -> {
                require(!text.isNullOrBlank())

                val textBytes = text.toByteArray(
                    StandardCharsets.UTF_8
                ).size

                require(textBytes <= NativeNfcContract.MAX_TEXT_BYTES)
            }
        }

        return synchronized(PROCESS_LOCK) {
            stateFile.locked {
                val state = readStateLocked()

                if (state.results.containsKey(id)) {
                    return@locked NativeNfcBeginResult.DuplicateId
                }

                if (
                    processActiveHandle != null ||
                    state.results.values.any {
                        it.status == NativeNfcContract.STATUS_PENDING
                    }
                ) {
                    return@locked NativeNfcBeginResult.Busy
                }

                val updatedResults = LinkedHashMap(state.results)
                var evictedId: String? = null

                if (
                    updatedResults.size >=
                    NativeNfcContract.MAX_STORED_RESULTS
                ) {
                    val oldestTerminal = updatedResults.values
                        .filter {
                            it.status in NativeNfcContract.TERMINAL_STATUSES
                        }
                        .minByOrNull {
                            it.completedAtMs ?: it.createdAtMs
                        }

                    if (oldestTerminal == null) {
                        return@locked NativeNfcBeginResult.Busy
                    }

                    evictedId = oldestTerminal.id
                    updatedResults.remove(oldestTerminal.id)
                }

                val now = clock()

                require(now > 0L)

                val pending = NativeNfcResult.pending(
                    id = id,
                    operation = operation,
                    now = now
                )

                updatedResults[id] = pending

                val updatedState = NativeNfcPersistedState(
                    processId = processId,
                    results = updatedResults
                )

                // Durable persistence must succeed before activation.
                saveStateLocked(updatedState)
                evictedId?.let { removedId ->
                    scanPayloads.remove(removedId)
                    writePayloads.remove(removedId)
                }

                val handle = NativeNfcOperationHandle(
                    id = id,
                    operation = operation,
                    token = UUID.randomUUID().toString(),
                    processId = processId
                )

                if (operation == NativeNfcContract.OP_START_WRITE) {
                    writePayloads[id] = requireNotNull(text)
                }

                processActiveHandle = handle

                NativeNfcBeginResult.Started(handle)
            }
        }
    }

    /**
    * Returns write text only for the currently active operation.
    * The payload is never exposed through status metadata.
    */
    internal fun writeText(
        handle: NativeNfcOperationHandle
    ): String? {
        return synchronized(PROCESS_LOCK) {
            if (handle.processId != processId) {
                return@synchronized null
            }

            val currentHandle = processActiveHandle
                ?: return@synchronized null

            if (
                currentHandle.id != handle.id ||
                currentHandle.token != handle.token ||
                currentHandle.processId != handle.processId ||
                currentHandle.operation != NativeNfcContract.OP_START_WRITE ||
                handle.operation != NativeNfcContract.OP_START_WRITE
            ) {
                return@synchronized null
            }

            writePayloads[handle.id]
        }
    }

    /**
     * Completes an active NFC operation.
     *
     * The terminal result is persisted before in-memory payloads
     * and active-operation state are changed.
     */
    internal fun complete(
        handle: NativeNfcOperationHandle,
        terminalStatus: String,
        error: String? = null,
        scanText: String? = null
    ): NativeNfcResult? {

        require(terminalStatus in NativeNfcContract.TERMINAL_STATUSES)

        return synchronized(PROCESS_LOCK) {
            stateFile.locked {
                if (handle.processId != processId) {
                    return@locked null
                }

                val currentHandle = processActiveHandle
                    ?: return@locked null

                if (
                    currentHandle.id != handle.id ||
                    currentHandle.token != handle.token ||
                    currentHandle.processId != handle.processId ||
                    currentHandle.operation != handle.operation
                ) {
                    return@locked null
                }

                val state = readStateLocked()

                val currentResult = state.results[handle.id]
                    ?: return@locked null

                if (
                    currentResult.status != NativeNfcContract.STATUS_PENDING ||
                    currentResult.operation != handle.operation
                ) {
                    return@locked null
                }

                when (terminalStatus) {
                    NativeNfcContract.STATUS_READ -> {
                        require(handle.operation == NativeNfcContract.OP_START_SCAN)
                        require(scanText != null)
                        require(
                            scanText.toByteArray(StandardCharsets.UTF_8).size <=
                                NativeNfcContract.MAX_TEXT_BYTES
                        )
                        require(error == null)
                    }

                    NativeNfcContract.STATUS_WRITTEN -> {
                        require(handle.operation == NativeNfcContract.OP_START_WRITE)
                        require(writePayloads.containsKey(handle.id))
                        require(scanText == null)
                        require(error == null)
                    }

                    NativeNfcContract.STATUS_CANCELLED -> {
                        require(error == null)
                        require(scanText == null)
                    }

                    NativeNfcContract.STATUS_FAILED -> {
                        require(error in NativeNfcContract.FAILURE_ERROR_CODES)
                        require(scanText == null)
                    }

                    NativeNfcContract.STATUS_UNKNOWN -> {
                        require(error in setOf(
                            NativeNfcContract.ERROR_OPERATION_INTERRUPTED,
                            NativeNfcContract.ERROR_UNKNOWN
                        ))
                        require(scanText == null)
                    }
                }

                val completed = currentResult.complete(
                    terminalStatus = terminalStatus,
                    error = error,
                    now = maxOf(
                        clock().coerceAtLeast(1L),
                        currentResult.createdAtMs
                    )
                )

                val updatedResults = LinkedHashMap(state.results)
                updatedResults[handle.id] = completed

                val updatedState = NativeNfcPersistedState(
                    processId = processId,
                    results = updatedResults
                )

                // Persist before changing in-memory operation state.
                saveStateLocked(updatedState)

                writePayloads.remove(handle.id)
                scanPayloads.remove(handle.id)

                if (terminalStatus == NativeNfcContract.STATUS_READ) {
                    scanPayloads[handle.id] = requireNotNull(scanText)
                }

                processActiveHandle = null

                completed
            }
        }
    }
    /**
     * Returns operation metadata without exposing NFC payloads.
     *
     * Expired terminal results are removed from durable state.
     */
    internal fun getStatus(id: String): Map<String, Any> {
        require(NativeNfcContract.UUID_V4_REGEX.matches(id))

        return synchronized(PROCESS_LOCK) {
            stateFile.locked {
                val state = readStateLocked()
                val now = clock().coerceAtLeast(1L)

                val expiredIds = state.results.values
                    .filter { result ->
                        val completedAt = result.completedAtMs

                        result.status != NativeNfcContract.STATUS_PENDING &&
                            completedAt != null &&
                            now >= completedAt &&
                            now - completedAt >=
                                NativeNfcContract.RESULT_TTL_MILLIS
                    }
                    .map { it.id }

                val results = LinkedHashMap(state.results)

                expiredIds.forEach { results.remove(it) }

                if (expiredIds.isNotEmpty()) {
                    saveStateLocked(
                        NativeNfcPersistedState(
                            processId = processId,
                            results = results
                        )
                    )

                    expiredIds.forEach { expiredId ->
                        scanPayloads.remove(expiredId)
                        writePayloads.remove(expiredId)
                    }
                }

                results[id]?.metadata()
                    ?: NativeNfcResult.notFound(id).metadata()
            }
        }
    }

    /**
     * Cancels the active operation identified by request ID.
     *
     * The operation token is checked by complete(), protecting
     * against a stale cancellation racing with a newer session.
     */
    internal fun stop(id: String): Boolean {
        require(NativeNfcContract.UUID_V4_REGEX.matches(id))

        val handle = synchronized(PROCESS_LOCK) {
            processActiveHandle?.takeIf { it.id == id }
        } ?: return false

        return complete(
            handle = handle,
            terminalStatus = NativeNfcContract.STATUS_CANCELLED
        ) != null
    }
    /**
     * Delivers a scanned text result exactly once.
     *
     * The consumed flag is persisted before the payload is returned.
     */
    internal fun consume(id: String): NativeNfcConsumeResult {
        require(NativeNfcContract.UUID_V4_REGEX.matches(id))

        return synchronized(PROCESS_LOCK) {
            stateFile.locked {
                val state = readStateLocked()

                val result = state.results[id]
                    ?: return@locked NativeNfcConsumeResult.NotFound

                if (result.consumed) {
                    scanPayloads.remove(id)
                    return@locked NativeNfcConsumeResult.AlreadyConsumed
                }

                if (result.status == NativeNfcContract.STATUS_PENDING) {
                    return@locked NativeNfcConsumeResult.NotReady
                }

                val now = clock().coerceAtLeast(1L)
                val completedAt = result.completedAtMs

                if (
                    completedAt != null &&
                    now >= completedAt &&
                    now - completedAt >= NativeNfcContract.RESULT_TTL_MILLIS
                ) {
                    val updatedResults = LinkedHashMap(state.results)
                    updatedResults.remove(id)

                    saveStateLocked(
                        NativeNfcPersistedState(
                            processId = processId,
                            results = updatedResults
                        )
                    )

                    scanPayloads.remove(id)
                    writePayloads.remove(id)

                    return@locked NativeNfcConsumeResult.Expired
                }

                if (result.status != NativeNfcContract.STATUS_READ) {
                    return@locked NativeNfcConsumeResult.NotReady
                }

                val text = scanPayloads[id]

                if (text == null) {
                    val interrupted = result.complete(
                        terminalStatus = NativeNfcContract.STATUS_UNKNOWN,
                        error = NativeNfcContract.ERROR_OPERATION_INTERRUPTED,
                        now = maxOf(now, result.createdAtMs)
                    )

                    val updatedResults = LinkedHashMap(state.results)
                    updatedResults[id] = interrupted

                    saveStateLocked(
                        NativeNfcPersistedState(
                            processId = processId,
                            results = updatedResults
                        )
                    )

                    return@locked NativeNfcConsumeResult.NotReady
                }

                val consumedResult = result.copy(consumed = true)

                check(consumedResult.isValidStored())

                val updatedResults = LinkedHashMap(state.results)
                updatedResults[id] = consumedResult

                saveStateLocked(
                    NativeNfcPersistedState(
                        processId = processId,
                        results = updatedResults
                    )
                )

                // Remove the in-memory payload only after durable consumption.
                scanPayloads.remove(id)

                NativeNfcConsumeResult.Consumed(text)
            }
        }
    }
    override fun toString(): String =
        "NativeNfcStore(private)"

    companion object {

        private val PROCESS_ID = UUID.randomUUID().toString()

        private val PROCESS_LOCK = Any()

        private var processActiveHandle: NativeNfcOperationHandle? = null

        private val PROCESS_SCAN_PAYLOADS =
            LinkedHashMap<String, String>()

        private val PROCESS_WRITE_PAYLOADS =
            LinkedHashMap<String, String>()

        fun forContext(context: Context): NativeNfcStore =
            NativeNfcStore(
                stateFile = NativeNfcAtomicStateFile.forContext(context)
            )
    }
}
