package com.bbs.plugins.firebase_remote_config

import org.json.JSONObject
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

/** Immutable record; the coordinator will persist these before acknowledgement. */
internal data class RemoteConfigOperationResult(
    val id: String?,
    val operation: String?,
    val accepted: Boolean,
    val status: String,
    val terminal: Boolean,
    val success: Boolean,
    val fetched: Boolean? = null,
    val activated: Boolean? = null,
    val startedAtMs: Long? = null,
    val finishedAtMs: Long? = null,
    val throttleEndTimeMs: Long? = null,
    val errorCode: String? = null
) {
    fun toBridgeMap(): Map<String, Any> = mapOf(
        "id" to (id ?: JSONObject.NULL), "operation" to (operation ?: JSONObject.NULL),
        "accepted" to accepted, "status" to status, "terminal" to terminal, "success" to success,
        "fetched" to (fetched ?: JSONObject.NULL), "activated" to (activated ?: JSONObject.NULL),
        "startedAtMs" to (startedAtMs ?: JSONObject.NULL), "finishedAtMs" to (finishedAtMs ?: JSONObject.NULL),
        "throttleEndTimeMs" to (throttleEndTimeMs ?: JSONObject.NULL), "errorCode" to (errorCode ?: JSONObject.NULL)
    )

    fun running(nowMs: Long): RemoteConfigOperationResult {
        require(accepted && !terminal && status == "pending")
        requireTimestamp(nowMs)
        return copy(status = "running", startedAtMs = nowMs)
    }

    fun markFetched(): RemoteConfigOperationResult {
        require(accepted && !terminal && status == "running" && operation == "FetchAndActivate")
        return copy(fetched = true)
    }

    fun succeeded(nowMs: Long, activation: Boolean? = null): RemoteConfigOperationResult {
        require(accepted && !terminal && status == "running" && startedAtMs != null)
        requireTimestamp(nowMs)
        val activates = operation in setOf("Activate", "FetchAndActivate")
        require(if (activates) activation != null else activation == null)
        require(operation != "FetchAndActivate" || fetched == true)
        return copy(status = if (activates && activation == false) "unchanged" else "succeeded",
            terminal = true, success = true, fetched = if (operation == "Fetch") true else fetched,
            activated = activation, finishedAtMs = maxOf(nowMs, requireNotNull(startedAtMs)), errorCode = null)
    }

    fun failed(code: String, nowMs: Long, throttleEndMs: Long? = null): RemoteConfigOperationResult {
        require(accepted && !terminal && status == "running" && startedAtMs != null)
        require(code in Contract.ERRORS)
        requireTimestamp(nowMs)
        if (throttleEndMs != null) { require(code == Contract.THROTTLED); requireTimestamp(throttleEndMs) }
        val state = when (code) {
            Contract.OPERATION_TIMEOUT -> "timed_out"
            Contract.PROCESS_INTERRUPTED -> "interrupted"
            else -> "failed"
        }
        return copy(status = state, terminal = true, success = false,
            activated = null, finishedAtMs = maxOf(nowMs, requireNotNull(startedAtMs)),
            throttleEndTimeMs = throttleEndMs, errorCode = code)
    }

    companion object {
        fun pending(request: RemoteConfigRequest): RemoteConfigOperationResult {
            require(request.operation in Contract.OPERATIONS && Contract.requestId(request.id) != null)
            return RemoteConfigOperationResult(request.id, request.operation, true, "pending", false, false)
        }

        fun rejected(id: Any?, operation: String, code: String): RemoteConfigOperationResult {
            require(operation in Contract.OPERATIONS && code in Contract.ERRORS)
            return RemoteConfigOperationResult(Contract.requestId(id), operation, false, "rejected", true, false, errorCode = code)
        }

        fun notFound(id: String): RemoteConfigOperationResult {
            require(Contract.requestId(id) != null)
            return RemoteConfigOperationResult(id, null, false, "not_found", true, false, errorCode = Contract.RESULT_NOT_FOUND)
        }

        private fun requireTimestamp(value: Long) { require(value in 1..Contract.MAX_SAFE_INTEGER) }
    }
}
