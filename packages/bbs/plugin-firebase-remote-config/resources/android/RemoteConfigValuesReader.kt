package com.bbs.plugins.firebase_remote_config

import com.google.firebase.remoteconfig.FirebaseRemoteConfig as FirebaseSdk
import org.json.JSONObject
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

/** Convert the exact SDK-selected value, so the returned source describes that value. */
internal object RemoteConfigValuesReader {
    fun read(values: Map<String, String>, sdk: RemoteConfigSdk, ready: Boolean, defaultsReady: Boolean): Map<String, Any> {
        if (!ready) return failure(Contract.NOT_INITIALIZED)
        if (!defaultsReady) return failure(Contract.DEFAULTS_NOT_READY)
        when (val parsed = RemoteConfigRequest.parse("GetValues", mapOf("values" to values))) {
            is RemoteConfigRequestParseResult.Invalid -> return failure(parsed.code)
            is RemoteConfigRequestParseResult.Valid -> Unit
        }
        val result = linkedMapOf<String, Any>()
        try {
            for ((key, type) in values) {
                val raw = sdk.value(key)
                val source = when (raw.source) {
                    FirebaseSdk.VALUE_SOURCE_REMOTE -> "remote"
                    FirebaseSdk.VALUE_SOURCE_DEFAULT -> "default"
                    FirebaseSdk.VALUE_SOURCE_STATIC -> "static"
                    else -> return failure(Contract.INVALID_NATIVE_RESPONSE)
                }
                val value = Contract.typedValue(type, raw.convert(type)) ?: return failure(Contract.INVALID_VALUE)
                result[key] = mapOf("type" to type, "value" to value, "source" to source)
            }
        } catch (_: IllegalArgumentException) { return failure(Contract.INVALID_VALUE) }
        catch (_: LinkageError) { return failure(Contract.NATIVE_UNAVAILABLE) }
        catch (_: OutOfMemoryError) { return failure(Contract.LIMIT_EXCEEDED) }
        catch (_: Exception) { return failure(Contract.NATIVE_UNAVAILABLE) }
        val reply = mapOf("success" to true, "values" to result, "errorCode" to JSONObject.NULL)
        return if (Contract.boundedResponse(reply)) reply else failure(Contract.LIMIT_EXCEEDED)
    }

    fun failure(code: String): Map<String, Any> {
        require(code in Contract.ERRORS)
        return mapOf("success" to false, "values" to JSONObject(), "errorCode" to code)
    }
}
