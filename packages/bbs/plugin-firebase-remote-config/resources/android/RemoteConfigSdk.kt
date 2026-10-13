package com.bbs.plugins.firebase_remote_config

import org.json.JSONObject
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

internal sealed interface RemoteConfigSdkOutcome<out T> {
    data class Success<T>(val value: T) : RemoteConfigSdkOutcome<T>
    data class Failure(val code: String, val throttleEndTimeMs: Long? = null) : RemoteConfigSdkOutcome<Nothing>
}

internal interface RemoteConfigRawValue {
    val source: Int
    fun convert(type: String): Any
}

/** SDK owns its caches. The coordinator will own request IDs, deadlines and replay. */
internal interface RemoteConfigSdk {
    fun initialize(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit)
    fun configure(settings: RemoteConfigSettings, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit)
    fun setDefaults(defaults: Map<String, RemoteConfigDefault>, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit)
    fun fetch(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit)
    fun activate(callback: (RemoteConfigSdkOutcome<Boolean>) -> Unit)
    fun value(key: String): RemoteConfigRawValue
    fun info(): RemoteConfigSdkInfo
}

internal data class RemoteConfigSdkInfo(
    val lastFetchStatus: String,
    val lastSuccessfulFetchAtMs: Long?,
    val minimumFetchIntervalSeconds: Long,
    val fetchTimeoutSeconds: Long
) {
    fun toBridgeMap(ready: Boolean, defaultsReady: Boolean, activeOperationId: String?, sdkBusy: Boolean): Map<String, Any> {
        if (lastFetchStatus !in Contract.FETCH_STATUSES ||
            (lastSuccessfulFetchAtMs != null && lastSuccessfulFetchAtMs !in 1..Contract.MAX_SAFE_INTEGER) ||
            (lastFetchStatus == "success" && lastSuccessfulFetchAtMs == null) ||
            minimumFetchIntervalSeconds !in 0..Contract.MAX_FETCH_INTERVAL_SECONDS ||
            fetchTimeoutSeconds !in 1..Contract.MAX_FETCH_TIMEOUT_SECONDS ||
            (defaultsReady && !ready) ||
            (activeOperationId != null && (Contract.requestId(activeOperationId) == null || !sdkBusy))) {
            return failure(Contract.INVALID_NATIVE_RESPONSE)
        }
        return mapOf("success" to true, "ready" to ready, "defaultsReady" to defaultsReady,
            "lastFetchStatus" to lastFetchStatus, "lastSuccessfulFetchAtMs" to (lastSuccessfulFetchAtMs ?: JSONObject.NULL),
            "minimumFetchIntervalSeconds" to minimumFetchIntervalSeconds, "fetchTimeoutSeconds" to fetchTimeoutSeconds,
            "activeOperationId" to (activeOperationId ?: JSONObject.NULL), "sdkBusy" to sdkBusy, "errorCode" to JSONObject.NULL)
    }

    companion object {
        fun failure(code: String): Map<String, Any> {
            require(code in Contract.ERRORS)
            return mapOf("success" to false, "errorCode" to code)
        }
    }
}
