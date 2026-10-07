package com.bbs.plugins.native_media_optimizer

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import org.json.JSONTokener
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import java.util.UUID
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal data class NativeMediaOptimizerHandle(val id: String, val token: String, val processId: String) {
    override fun toString() = "NativeMediaOptimizerHandle(redacted)"
}

internal data class NativeMediaOptimizerRecord(
    val handle: NativeMediaOptimizerHandle,
    val outputDirectory: String?,
    val result: NativeMediaOptimizerResult,
    val createdAtMs: Long
) {
    override fun toString() = "NativeMediaOptimizerRecord(redacted)"
}

internal sealed interface NativeMediaOptimizerBeginResult {
    class Started(val record: NativeMediaOptimizerRecord) : NativeMediaOptimizerBeginResult
    class Rejected(val result: NativeMediaOptimizerResult) : NativeMediaOptimizerBeginResult
}

/** State operations run on the serial state worker, never the Android UI thread. */
internal class NativeMediaOptimizerStore(
    private val stateFile: NativeMediaOptimizerStateFile,
    private val processId: String = CURRENT_PROCESS_ID,
    private val clock: () -> Long = System::currentTimeMillis
) {
    constructor(context: Context) : this(NativeMediaOptimizerAtomicStateFile.forContext(context))
    init { require(Contract.requestId(processId) == processId) }
    override fun toString() = "NativeMediaOptimizerStore(private)"

    fun begin(request: NativeMediaOptimizerRequest, outputDirectory: String?): NativeMediaOptimizerBeginResult = transaction { records ->
        fun rejected(code: String) = NativeMediaOptimizerBeginResult.Rejected(NativeMediaOptimizerResult.rejected(request.id, code, request.operation))
        if (records.any { it.result.id == request.id }) return@transaction rejected(Contract.INVALID_OPTIONS)
        if (records.any { !it.result.isTerminal }) return@transaction rejected(Contract.BUSY)
        if (outputDirectory != null && !validDirectory(outputDirectory)) return@transaction rejected(Contract.OUTPUT_FAILED)
        if (request.operation != "InspectMedia" && outputDirectory == null) return@transaction rejected(Contract.OUTPUT_FAILED)
        if (records.size >= Contract.MAX_RECORDS) {
            // Retain completed files until explicit deletion. Never evict a live output.
            val removable = records.filter { it.result.isTerminal && !it.result.outputAvailable }.minByOrNull { it.createdAtMs }
                ?: return@transaction rejected(Contract.LIMIT_EXCEEDED)
            records.remove(removable)
        }
        val record = NativeMediaOptimizerRecord(
            NativeMediaOptimizerHandle(request.id, UUID.randomUUID().toString(), processId),
            outputDirectory, NativeMediaOptimizerResult.pending(request), clock()
        )
        records.add(record)
        NativeMediaOptimizerBeginResult.Started(record)
    }

    fun getStatus(id: String): NativeMediaOptimizerResult {
        if (Contract.requestId(id) != id) return NativeMediaOptimizerResult.rejected(id, Contract.INVALID_REQUEST_ID)
        return transaction { records -> records.firstOrNull { it.result.id == id }?.result ?: NativeMediaOptimizerResult.rejected(id, Contract.RESULT_NOT_FOUND) }
    }

    fun record(id: String): NativeMediaOptimizerRecord? {
        require(Contract.requestId(id) == id)
        return transaction { records -> records.firstOrNull { it.result.id == id } }
    }

    fun interruptedRecords(): List<NativeMediaOptimizerRecord> = transaction { records -> records.filter { it.result.status == "interrupted" } }

    /** Retain exact ownership for cleanup after a failure, cancellation or process death. */
    fun terminalCleanupRecords(): List<NativeMediaOptimizerRecord> = transaction { records ->
        records.filter { it.result.status in setOf("failed", "cancelled", "interrupted") && it.result.output == null }
    }

    fun update(handle: NativeMediaOptimizerHandle, result: NativeMediaOptimizerResult): Boolean = transaction { records ->
        val index = records.indexOfFirst { it.handle == handle && it.handle.processId == processId }
        if (index < 0) return@transaction false
        val previous = records[index].result
        if (previous.isTerminal || !sameBinding(previous, result) || !result.accepted || result.status == "pending") return@transaction false
        // Once cancellation is durable, late success or ordinary progress cannot win.
        if (previous.status == "cancelling" && result.status !in setOf("cancelling", "cancelled", "failed", "interrupted")) return@transaction false
        if (result.progress != null && previous.progress != null && result.progress < previous.progress) return@transaction false
        if (result.output != null && result.output.path.replace('\\', '/').substringBeforeLast('/') != records[index].outputDirectory?.replace('\\', '/')) return@transaction false
        records[index] = records[index].copy(result = result)
        true
    }

    fun cancel(id: String): NativeMediaOptimizerResult = transaction { records ->
        val index = records.indexOfFirst { it.result.id == id }
        if (index < 0) return@transaction NativeMediaOptimizerResult.rejected(id, Contract.RESULT_NOT_FOUND)
        val previous = records[index].result
        if (previous.isTerminal || previous.status == "cancelling") return@transaction previous
        val result = previous.copy(status = "cancelling", phase = "cancelling")
        records[index] = records[index].copy(result = result)
        result
    }

    fun markOutputUnavailable(id: String): Boolean = transaction { records ->
        val index = records.indexOfFirst { it.result.id == id }
        if (index < 0 || !records[index].result.isTerminal) return@transaction false
        records[index] = records[index].copy(result = records[index].result.copy(outputAvailable = false))
        true
    }

    private fun <T> transaction(action: (MutableList<NativeMediaOptimizerRecord>) -> T): T = stateFile.locked {
        val previous = stateFile.read()
        try {
            val records = decode(previous)
            for (index in records.indices) {
                val record = records[index]
                if (!record.result.isTerminal && record.handle.processId != processId) {
                    records[index] = record.copy(result = record.result.copy(status = "interrupted", phase = "interrupted", errorCode = Contract.PROCESS_INTERRUPTED))
                }
            }
            val result = action(records)
            val bytes = encode(records)
            try {
                if (previous == null || !previous.contentEquals(bytes)) stateFile.write(bytes)
            } finally { bytes.fill(0) }
            result
        } finally { previous?.fill(0) }
    }

    private fun decode(bytes: ByteArray?): MutableList<NativeMediaOptimizerRecord> {
        if (bytes == null) return mutableListOf()
        try {
            check(bytes.size <= Contract.MAX_STATE_BYTES)
            val text = Charsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT).onUnmappableCharacter(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(bytes)).toString()
            check(hasBoundedNesting(text))
            val tokener = JSONTokener(text)
            val json = tokener.nextValue() as? JSONObject ?: throw NativeMediaOptimizerStorageException()
            check(tokener.nextClean() == '\u0000')
            check(json.keys().asSequence().toSet() == setOf("version", "records") && Contract.integer(json.get("version")) == 1L)
            val array = json.get("records") as? JSONArray ?: throw NativeMediaOptimizerStorageException()
            check(array.length() <= Contract.MAX_RECORDS)
            val records = mutableListOf<NativeMediaOptimizerRecord>()
            for (index in 0 until array.length()) {
                val entry = array.get(index) as? JSONObject ?: throw NativeMediaOptimizerStorageException()
                check(entry.keys().asSequence().toSet() == setOf("token", "processId", "outputDirectory", "createdAtMs", "result"))
                val result = NativeMediaOptimizerResult.fromJson(entry.get("result")) ?: throw NativeMediaOptimizerStorageException()
                check(result.accepted && result.id != null)
                val token = Contract.requestId(entry.get("token")) ?: throw NativeMediaOptimizerStorageException()
                val owner = Contract.requestId(entry.get("processId")) ?: throw NativeMediaOptimizerStorageException()
                val rootRaw = entry.get("outputDirectory")
                val root = if (rootRaw === JSONObject.NULL) null else rootRaw as? String ?: throw NativeMediaOptimizerStorageException()
                check(root == null || validDirectory(root))
                check(result.operation == "InspectMedia" || root != null)
                check(result.output == null || result.output.path.replace('\\', '/').substringBeforeLast('/') == root?.replace('\\', '/'))
                val created = Contract.integer(entry.get("createdAtMs"))?.takeIf { it >= 0 } ?: throw NativeMediaOptimizerStorageException()
                records.add(NativeMediaOptimizerRecord(NativeMediaOptimizerHandle(requireNotNull(result.id), token, owner), root, result, created))
            }
            check(records.map { it.handle.id }.toSet().size == records.size)
            check(records.count { !it.result.isTerminal } <= 1)
            return records
        } catch (_: Exception) { throw NativeMediaOptimizerStorageException() }
    }

    private fun encode(records: List<NativeMediaOptimizerRecord>): ByteArray {
        try {
            check(records.size <= Contract.MAX_RECORDS)
            val array = JSONArray()
            records.forEach { record ->
                array.put(JSONObject(linkedMapOf(
                    "token" to record.handle.token, "processId" to record.handle.processId,
                    "outputDirectory" to (record.outputDirectory ?: JSONObject.NULL),
                    "createdAtMs" to record.createdAtMs, "result" to JSONObject(record.result.toBridgeMap())
                )))
            }
            val bytes = JSONObject(linkedMapOf("version" to 1, "records" to array)).toString().toByteArray(Charsets.UTF_8)
            check(bytes.size <= Contract.MAX_STATE_BYTES)
            return bytes
        } catch (_: Exception) { throw NativeMediaOptimizerStorageException() }
    }

    private fun sameBinding(first: NativeMediaOptimizerResult, second: NativeMediaOptimizerResult) = first.id == second.id && first.operation == second.operation && first.sourceDocumentId == second.sourceDocumentId
    private fun validDirectory(value: String) = value.length in 1..4096 && value.startsWith('/') && value.none { it.code < 32 || it.code == 127 || it == '\\' } && value.endsWith("/native-media-optimizer") && value.split('/').none { it == "." || it == ".." }

    private fun hasBoundedNesting(text: String): Boolean {
        var depth = 0
        var quoted = false
        var escaped = false
        for (character in text) {
            if (quoted) {
                if (escaped) escaped = false
                else if (character == '\\') escaped = true
                else if (character == '"') quoted = false
            } else when (character) {
                '"' -> quoted = true
                '{', '[' -> { depth++; if (depth > 12) return false }
                '}', ']' -> { depth--; if (depth < 0) return false }
            }
        }
        return !quoted && depth == 0
    }

    companion object { private val CURRENT_PROCESS_ID = UUID.randomUUID().toString() }
}
