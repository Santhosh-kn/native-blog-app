package com.bbs.plugins.firebase_remote_config

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.google.firebase.remoteconfig.FirebaseRemoteConfig as FirebaseSdk
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

@RunWith(AndroidJUnit4::class)
class RemoteConfigValuesReaderInstrumentedTest {
    private val sdk = RemoteConfigValueTestSdk()
    private fun read(type: String, value: Any, source: Int = FirebaseSdk.VALUE_SOURCE_REMOTE): JSONObject {
        sdk.values["example"] = RemoteConfigValueTestRaw(source, value)
        return JSONObject(RemoteConfigValuesReader.read(mapOf("example" to type), sdk, true, true))
    }

    @Test fun initializationMustCompleteBeforeReads() {
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("example" to "string"), sdk, false, false))
        assertEquals(Contract.NOT_INITIALIZED, json.getString("errorCode"))
        assertTrue(sdk.calls.isEmpty())
    }

    @Test fun defaultsRegistrationMustCompleteBeforeReads() {
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("example" to "string"), sdk, true, false))
        assertEquals(Contract.DEFAULTS_NOT_READY, json.getString("errorCode"))
        assertTrue(sdk.calls.isEmpty())
    }

    @Test fun unknownSourceCannotBeInventedAsRemote() {
        val json = read("integer", 80L, 999)
        assertFalse(json.getBoolean("success"))
        assertEquals(Contract.INVALID_NATIVE_RESPONSE, json.getString("errorCode"))
        assertEquals(0, json.getJSONObject("values").length())
    }

    @Test fun remoteTypeConversionFailureDoesNotBecomeAFalseBoolean() {
        val raw = RemoteConfigValueTestRaw(FirebaseSdk.VALUE_SOURCE_REMOTE, "invalid")
        raw.conversionFailure = true
        sdk.values["flag"] = raw
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("flag" to "boolean"), sdk, true, true))
        assertEquals(Contract.INVALID_VALUE, json.getString("errorCode"))
        assertEquals(0, json.getJSONObject("values").length())
        assertFalse(json.toString().contains("private parameter detail"))
    }

    @Test fun aBadEntryRejectsTheWholeCollection() {
        sdk.values["first"] = RemoteConfigValueTestRaw(FirebaseSdk.VALUE_SOURCE_DEFAULT, 80L)
        sdk.values["second"] = RemoteConfigValueTestRaw(FirebaseSdk.VALUE_SOURCE_REMOTE, "not an integer")
        val json = JSONObject(RemoteConfigValuesReader.read(linkedMapOf("first" to "integer", "second" to "integer"), sdk, true, true))
        assertFalse(json.getBoolean("success"))
        assertEquals(0, json.getJSONObject("values").length())
    }

    @Test fun integerLimitsAreEnforcedAfterSdkConversion() {
        assertTrue(read("integer", Contract.MAX_SAFE_INTEGER).getBoolean("success"))
        assertTrue(read("integer", -Contract.MAX_SAFE_INTEGER).getBoolean("success"))
        assertEquals(Contract.INVALID_VALUE, read("integer", Contract.MAX_SAFE_INTEGER + 1).getString("errorCode"))
        assertEquals(Contract.INVALID_VALUE, read("integer", -Contract.MAX_SAFE_INTEGER - 1).getString("errorCode"))
    }

    @Test fun decimalsMustRemainFinite() {
        assertEquals(Contract.INVALID_VALUE, read("decimal", Double.NaN).getString("errorCode"))
        assertEquals(Contract.INVALID_VALUE, read("decimal", Double.POSITIVE_INFINITY).getString("errorCode"))
        assertEquals(Contract.INVALID_VALUE, read("decimal", Double.NEGATIVE_INFINITY).getString("errorCode"))
    }

    @Test fun stringsUseUtf8ByteLimits() {
        assertTrue(read("string", "é".repeat(512)).getBoolean("success"))
        assertEquals(Contract.INVALID_VALUE, read("string", "é".repeat(513)).getString("errorCode"))
        assertEquals(Contract.INVALID_VALUE, read("string", "x".repeat(1025)).getString("errorCode"))
    }

    @Test fun invalidSurrogatesAreNotSilentlyReplaced() {
        assertEquals(Contract.INVALID_VALUE, read("string", "\uD800").getString("errorCode"))
    }

    @Test fun unknownReadTypeDoesNotReachSdk() {
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("example" to "json"), sdk, true, true))
        assertEquals(Contract.INVALID_TYPE, json.getString("errorCode"))
        assertTrue(sdk.calls.isEmpty())
    }

    @Test fun invalidKeyDoesNotReachSdk() {
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("invalid.key" to "string"), sdk, true, true))
        assertEquals(Contract.INVALID_KEY, json.getString("errorCode"))
        assertTrue(sdk.calls.isEmpty())
    }

    @Test fun aLargeCombinedReplyIsRejected() {
        val requested = linkedMapOf<String, String>()
        repeat(32) { index ->
            val key = "key_$index"; requested[key] = "string"
            sdk.values[key] = RemoteConfigValueTestRaw(FirebaseSdk.VALUE_SOURCE_REMOTE, "x".repeat(1024))
        }
        val json = JSONObject(RemoteConfigValuesReader.read(requested, sdk, true, true))
        assertEquals(Contract.LIMIT_EXCEEDED, json.getString("errorCode"))
        assertEquals(0, json.getJSONObject("values").length())
    }

    @Test fun sdkExceptionsBecomeControlledErrors() {
        sdk.exception = IllegalStateException("private diagnostic")
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("example" to "string"), sdk, true, true))
        assertEquals(Contract.NATIVE_UNAVAILABLE, json.getString("errorCode"))
        assertFalse(json.toString().contains("private diagnostic"))
    }

    @Test fun sdkLinkageFailureIsControlled() {
        sdk.exception = LinkageError("private class detail")
        val json = JSONObject(RemoteConfigValuesReader.read(mapOf("example" to "string"), sdk, true, true))
        assertEquals(Contract.NATIVE_UNAVAILABLE, json.getString("errorCode"))
    }
}
