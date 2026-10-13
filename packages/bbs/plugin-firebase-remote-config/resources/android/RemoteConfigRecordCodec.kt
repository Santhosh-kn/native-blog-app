package com.bbs.plugins.firebase_remote_config

import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import org.json.JSONArray
import org.json.JSONObject
import org.json.JSONTokener
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

internal data class RemoteConfigStoredRecord(val fingerprint: String, val result: RemoteConfigOperationResult)

/** Owned canonical format. Corrupt or unsupported history is never silently discarded. */
internal object RemoteConfigRecordCodec {
    const val MAX_RECORDS = 50
    const val MAX_STORAGE_BYTES = 65536
    private val fingerprintPattern = Regex("[0-9a-f]{64}")
    private val resultKeys = setOf("id", "operation", "accepted", "status", "terminal", "success",
        "fetched", "activated", "startedAtMs", "finishedAtMs", "throttleEndTimeMs", "errorCode")

    fun encode(records: List<RemoteConfigStoredRecord>): ByteArray {
        require(records.size <= MAX_RECORDS)
        require(records.map { it.result.id }.toSet().size == records.size)
        require(records.count { !it.result.terminal } <= 1)
        val entries = JSONArray()
        for (record in records) {
            require(fingerprintPattern.matches(record.fingerprint))
            require(decodeResult(record.result.toBridgeMap()) == record.result)
            entries.put(JSONObject().put("fingerprint", record.fingerprint)
                .put("result", JSONObject(record.result.toBridgeMap())))
        }
        val bytes = JSONObject().put("version", 1).put("records", entries).toString().toByteArray(Charsets.UTF_8)
        require(bytes.size <= MAX_STORAGE_BYTES)
        return bytes
    }

    fun decode(bytes: ByteArray): List<RemoteConfigStoredRecord> {
        try {
            require(bytes.isNotEmpty() && bytes.size <= MAX_STORAGE_BYTES)
            val text = Charsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(bytes)).toString()
            require(shallowJson(text)) // Bound nesting before Android's recursive JSON parser.
            val tokener = JSONTokener(text)
            val raw = tokener.nextValue()
            require(tokener.nextClean() == '\u0000')
            val root = requireNotNull(Contract.objectMap(raw))
            require(root.keys == setOf("version", "records") && Contract.integer(root["version"]) == 1L)
            val array = root["records"] as? JSONArray ?: throw IllegalArgumentException()
            require(array.length() <= MAX_RECORDS)
            val records = List(array.length()) { index ->
                val entry = requireNotNull(Contract.objectMap(array.get(index)))
                require(entry.keys == setOf("fingerprint", "result"))
                val fingerprint = entry["fingerprint"] as? String ?: throw IllegalArgumentException()
                require(fingerprintPattern.matches(fingerprint))
                val result = decodeResult(entry["result"]) ?: throw IllegalArgumentException()
                RemoteConfigStoredRecord(fingerprint, result)
            }
            // Our format is canonical: rejects duplicate keys, alternate number types,
            // trailing input and lenient JSONTokener syntax without accepting ambiguity.
            require(encode(records).contentEquals(bytes))
            return records
        } catch (_: Exception) {
            throw RemoteConfigStorageException()
        }
    }

    fun decodeResult(raw: Any?): RemoteConfigOperationResult? = try {
        val fields = requireNotNull(Contract.objectMap(raw))
        require(fields.keys == resultKeys)
        val id = requireNotNull(Contract.requestId(fields["id"]))
        val operation = (fields["operation"] as? String)?.takeIf { it in Contract.OPERATIONS }
            ?: throw IllegalArgumentException()
        // Only accepted records are durable; rejections and not_found are query results.
        require(fields["accepted"] == true)
        val status = fields["status"] as? String ?: throw IllegalArgumentException()
        require(status in setOf("pending", "running", "succeeded", "unchanged", "failed", "timed_out", "interrupted"))
        val terminal = fields["terminal"] as? Boolean ?: throw IllegalArgumentException()
        val success = fields["success"] as? Boolean ?: throw IllegalArgumentException()
        val fetched = nullableBoolean(fields["fetched"])
        val activated = nullableBoolean(fields["activated"])
        val started = timestamp(fields["startedAtMs"])
        val finished = timestamp(fields["finishedAtMs"])
        val throttle = timestamp(fields["throttleEndTimeMs"])
        val error = fields["errorCode"]
        require(error == null || error is String && error in Contract.ERRORS)
        val active = status in setOf("pending", "running")
        val successful = status in setOf("succeeded", "unchanged")
        require(terminal == !active && success == successful)
        require(if (active || successful) error == null else error != null)
        require(if (active) finished == null else finished != null)
        require(if (status == "pending") started == null && fetched == null else started != null)
        require(started == null || finished == null || finished >= started)
        require(throttle == null || error == Contract.THROTTLED)
        require(status != "timed_out" || error == Contract.OPERATION_TIMEOUT)
        require(status != "interrupted" || error == Contract.PROCESS_INTERRUPTED)
        require(status != "unchanged" || operation in setOf("Activate", "FetchAndActivate"))
        require(!active || activated == null && (fetched == null || operation == "FetchAndActivate" && fetched))
        when (operation) {
            "Configure", "SetDefaults" -> require(fetched == null && activated == null)
            "Fetch" -> require(activated == null && (fetched == null || fetched))
            "Activate" -> require(fetched == null)
            "FetchAndActivate" -> require(fetched == null || fetched)
        }
        if (successful) when (operation) {
            "Fetch" -> require(fetched == true)
            "Activate" -> require(activated == (status == "succeeded"))
            "FetchAndActivate" -> require(fetched == true && activated == (status == "succeeded"))
        }
        require(successful || activated == null)
        RemoteConfigOperationResult(id, operation, true, status, terminal, success, fetched, activated,
            started, finished, throttle, error as? String)
    } catch (_: Exception) { null }

    private fun nullableBoolean(value: Any?): Boolean? {
        require(value == null || value is Boolean)
        return value as? Boolean
    }

    private fun timestamp(value: Any?): Long? {
        if (value == null) return null
        return requireNotNull(Contract.integer(value)?.takeIf { it > 0 })
    }

    private fun shallowJson(text: String): Boolean {
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
                '{', '[' -> { depth++; if (depth > 4) return false }
                '}', ']' -> { depth--; if (depth < 0) return false }
            }
        }
        return depth == 0 && !quoted
    }
}
