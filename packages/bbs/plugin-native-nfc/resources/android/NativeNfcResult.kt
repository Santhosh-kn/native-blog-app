package com.bbs.plugins.native_nfc

import org.json.JSONObject
import java.math.BigDecimal

internal data class NativeNfcResult(
    val id: String,
    val operation: String,
    val status: String,
    val errorCode: String?,
    val createdAtMs: Long,
    val completedAtMs: Long?,
    val consumed: Boolean
) {

    fun complete(
        terminalStatus: String,
        error: String? = null,
        now: Long
    ): NativeNfcResult {
        require(terminalStatus in NativeNfcContract.TERMINAL_STATUSES)
        require(now >= createdAtMs && now > 0L)

        val updated = copy(
            status = terminalStatus,
            errorCode = error,
            completedAtMs = now
        )

        require(updated.isValidStored())
        return updated
    }

    fun metadata(): Map<String, Any> {
        val result = mutableMapOf<String, Any>(
            "id" to id,
            "operation" to operation,
            "status" to status,
            "consumed" to consumed,
            "created_at_ms" to createdAtMs
        )

        errorCode?.let { result["error_code"] = it }
        completedAtMs?.let { result["completed_at_ms"] = it }

        return result
    }

    fun eventMetadata(): Map<String, Any> {
        val result = mutableMapOf<String, Any>(
            "id" to id,
            "operation" to operation,
            "status" to status,
            "consumed" to consumed,
            "createdAtMs" to createdAtMs
        )

        errorCode?.let { result["errorCode"] = it }
        completedAtMs?.let { result["completedAtMs"] = it }

        return result
    }
    fun toStoredJson(): JSONObject = JSONObject().apply {
        put("id", id)
        put("operation", operation)
        put("status", status)
        put("errorCode", errorCode ?: JSONObject.NULL)
        put("createdAtMs", createdAtMs)
        put("completedAtMs", completedAtMs ?: JSONObject.NULL)
        put("consumed", consumed)
    }

    fun isValidStored(): Boolean {
        if (!NativeNfcContract.UUID_V4_REGEX.matches(id)) return false

        if (operation !in OPERATIONS) return false

        if (status !in NativeNfcContract.TERMINAL_STATUSES &&
            status != NativeNfcContract.STATUS_PENDING
        ) return false

        if (createdAtMs <= 0L) return false

        if (status == NativeNfcContract.STATUS_PENDING) {
            return completedAtMs == null &&
                errorCode == null &&
                !consumed
        }

        if (completedAtMs == null || completedAtMs < createdAtMs) {
            return false
        }

        if (consumed && status != NativeNfcContract.STATUS_READ) {
            return false
        }

        return when (status) {
            NativeNfcContract.STATUS_READ ->
                operation == NativeNfcContract.OP_START_SCAN &&
                    errorCode == null

            NativeNfcContract.STATUS_WRITTEN ->
                operation == NativeNfcContract.OP_START_WRITE &&
                    errorCode == null

            NativeNfcContract.STATUS_CANCELLED ->
                errorCode == null && !consumed

            NativeNfcContract.STATUS_FAILED ->
                errorCode in NativeNfcContract.FAILURE_ERROR_CODES && !consumed

            NativeNfcContract.STATUS_UNKNOWN ->
                errorCode in setOf(
                    NativeNfcContract.ERROR_OPERATION_INTERRUPTED,
                    NativeNfcContract.ERROR_UNKNOWN
                ) && !consumed

            else -> false
        }
    }

    companion object {

        private val OPERATIONS = setOf(
            NativeNfcContract.OP_START_SCAN,
            NativeNfcContract.OP_START_WRITE
        )

        fun pending(
            id: String,
            operation: String,
            now: Long
        ): NativeNfcResult {
            require(operation in OPERATIONS)
            require(NativeNfcContract.UUID_V4_REGEX.matches(id))
            require(now > 0L)

            return NativeNfcResult(
                id = id,
                operation = operation,
                status = NativeNfcContract.STATUS_PENDING,
                errorCode = null,
                createdAtMs = now,
                completedAtMs = null,
                consumed = false
            )
        }

        fun notFound(id: String): NativeNfcResult =
            NativeNfcResult(
                id = id,
                operation = "",
                status = NativeNfcContract.STATUS_NOT_FOUND,
                errorCode = null,
                createdAtMs = 0L,
                completedAtMs = null,
                consumed = false
            )

        fun fromStoredJson(json: JSONObject): NativeNfcResult? {
            return try {
                val expectedKeys = setOf(
                    "id",
                    "operation",
                    "status",
                    "errorCode",
                    "createdAtMs",
                    "completedAtMs",
                    "consumed"
                )

                if (json.keys().asSequence().toSet() != expectedKeys) {
                    return null
                }

                val id = json.opt("id") as? String ?: return null
                val operation = json.opt("operation") as? String ?: return null
                val status = json.opt("status") as? String ?: return null

                val errorValue = json.opt("errorCode")
                val errorCode = if (errorValue === JSONObject.NULL) {
                    null
                } else {
                    errorValue as? String ?: return null
                }

                val createdAtMs = readLong(json.opt("createdAtMs"))
                    ?: return null

                val completedValue = json.opt("completedAtMs")
                val completedAtMs = if (completedValue === JSONObject.NULL) {
                    null
                } else {
                    readLong(completedValue) ?: return null
                }

                val consumed = json.opt("consumed") as? Boolean
                    ?: return null

                val result = NativeNfcResult(
                    id = id,
                    operation = operation,
                    status = status,
                    errorCode = errorCode,
                    createdAtMs = createdAtMs,
                    completedAtMs = completedAtMs,
                    consumed = consumed
                )

                result.takeIf { it.isValidStored() }
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
}
