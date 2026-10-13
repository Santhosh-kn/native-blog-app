package com.bbs.plugins.firebase_remote_config

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.concurrent.Executor
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

@RunWith(AndroidJUnit4::class)
class RemoteConfigSdkBoundaryInstrumentedTest {
    private val id = "123e4567-e89b-42d3-a456-426614174000"
    private fun info() = RemoteConfigSdkInfo("no_fetch_yet", null, 43200, 60)

    @Test fun nativeInfoKeepsExplicitNullsAndBooleans() {
        val json = JSONObject(info().toBridgeMap(false, false, null, false))
        assertTrue(json.getBoolean("success"))
        assertFalse(json.getBoolean("ready"))
        assertFalse(json.getBoolean("defaultsReady"))
        assertTrue(json.isNull("lastSuccessfulFetchAtMs"))
        assertTrue(json.isNull("activeOperationId"))
        assertTrue(json.isNull("errorCode"))
    }

    @Test fun previousSuccessfulFetchTimestampCanSurviveANewerFailure() {
        val metadata = RemoteConfigSdkInfo("failure", 1000, 43200, 60)
        val json = JSONObject(metadata.toBridgeMap(true, true, null, false))
        assertTrue(json.getBoolean("success"))
        assertEquals(1000L, json.getLong("lastSuccessfulFetchAtMs"))
    }

    @Test fun anActiveOperationRequiresSdkBusy() {
        val json = JSONObject(info().toBridgeMap(true, true, id, false))
        assertEquals(Contract.INVALID_NATIVE_RESPONSE, json.getString("errorCode"))
    }

    @Test fun aLingeringSdkTaskCanOutlastTheTrackedOperation() {
        val json = JSONObject(info().toBridgeMap(true, true, null, true))
        assertTrue(json.getBoolean("success"))
        assertTrue(json.getBoolean("sdkBusy"))
        assertTrue(json.isNull("activeOperationId"))
    }

    @Test fun defaultsCannotBeReadyBeforeSdkInitialization() {
        val json = JSONObject(info().toBridgeMap(false, true, null, false))
        assertEquals(Contract.INVALID_NATIVE_RESPONSE, json.getString("errorCode"))
    }

    @Test fun successfulFetchRequiresItsTimestamp() {
        val json = JSONObject(RemoteConfigSdkInfo("success", null, 43200, 60).toBridgeMap(true, true, null, false))
        assertEquals(Contract.INVALID_NATIVE_RESPONSE, json.getString("errorCode"))
    }

    @Test fun unknownFetchStatusIsNotExposed() {
        val json = JSONObject(RemoteConfigSdkInfo("raw-status", null, 43200, 60).toBridgeMap(true, true, null, false))
        assertEquals(Contract.INVALID_NATIVE_RESPONSE, json.getString("errorCode"))
    }

    @Test fun sdkSettingsRemainWithinPluginBounds() {
        for (metadata in listOf(RemoteConfigSdkInfo("no_fetch_yet", null, -1, 60),
            RemoteConfigSdkInfo("no_fetch_yet", null, 604801, 60),
            RemoteConfigSdkInfo("no_fetch_yet", null, 43200, 0),
            RemoteConfigSdkInfo("no_fetch_yet", null, 43200, 121))) {
            assertEquals(Contract.INVALID_NATIVE_RESPONSE,
                JSONObject(metadata.toBridgeMap(true, true, null, false)).getString("errorCode"))
        }
    }

    @Test fun sdkFactoryRejectsTheUiThreadBeforeAccessingFirebase() {
        var rejected = false
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            try { FirebaseRemoteConfigSdk.create(Executor { it.run() }) }
            catch (_: IllegalStateException) { rejected = true }
        }
        assertTrue(rejected)
    }

    @Test fun typedDefaultsAreValidatedBeforeSdkCalls() {
        assertNull(FirebaseRemoteConfigSdk.defaultsError(mapOf(
            "text" to RemoteConfigDefault("string", "Welcome"),
            "flag" to RemoteConfigDefault("boolean", false),
            "quality" to RemoteConfigDefault("integer", 80L),
            "scale" to RemoteConfigDefault("decimal", 1.0))))
    }

    @Test fun untypedOrOversizedDefaultsCannotReachSdk() {
        assertEquals(Contract.INVALID_OPTIONS, FirebaseRemoteConfigSdk.defaultsError(emptyMap()))
        assertEquals(Contract.INVALID_KEY, FirebaseRemoteConfigSdk.defaultsError(mapOf("invalid.key" to RemoteConfigDefault("string", "x"))))
        assertEquals(Contract.INVALID_TYPE, FirebaseRemoteConfigSdk.defaultsError(mapOf("text" to RemoteConfigDefault("json", "{}"))))
        assertEquals(Contract.INVALID_VALUE, FirebaseRemoteConfigSdk.defaultsError(mapOf("flag" to RemoteConfigDefault("boolean", "true"))))
        assertEquals(Contract.INVALID_VALUE, FirebaseRemoteConfigSdk.defaultsError(mapOf("count" to RemoteConfigDefault("integer", 80.0))))
        assertEquals(Contract.INVALID_VALUE, FirebaseRemoteConfigSdk.defaultsError(mapOf("text" to RemoteConfigDefault("string", "x".repeat(1025)))))
    }

    @Test fun tooManyDefaultsAreRejected() {
        val defaults = (1..33).associate { "key_$it" to RemoteConfigDefault("integer", 1L) }
        assertEquals(Contract.LIMIT_EXCEEDED, FirebaseRemoteConfigSdk.defaultsError(defaults))
    }

    @Test fun aggregateDefaultsPayloadIsBounded() {
        val defaults = (1..9).associate { "key_$it" to RemoteConfigDefault("string", "x".repeat(1024)) }
        assertEquals(Contract.REQUEST_TOO_LARGE, FirebaseRemoteConfigSdk.defaultsError(defaults))
    }
}
