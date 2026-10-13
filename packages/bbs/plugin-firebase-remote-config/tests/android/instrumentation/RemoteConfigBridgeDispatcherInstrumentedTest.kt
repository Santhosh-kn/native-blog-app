package com.bbs.plugins.firebase_remote_config

import android.app.Activity
import android.content.Context
import androidx.fragment.app.FragmentActivity
import androidx.test.platform.app.InstrumentationRegistry
import com.nativephp.mobile.bridge.BridgeFunction
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract
import com.bbs.plugins.firebase_remote_config.RemoteConfigStorageTestFixture as Fixture

internal object RemoteConfigBridgeTestFixture {
    fun onMain(action: () -> Unit) {
        var failure: Throwable? = null
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            try { action() } catch (error: Throwable) { failure = error }
        }
        // Report unexpected main-thread errors to JUnit rather than crashing the app process.
        failure?.let { throw it }
    }
    fun parameters(operation: String): Map<String, Any> = when (operation) {
        "IsAvailable", "GetInfo" -> emptyMap()
        "Configure" -> mapOf("id" to Fixture.id(2), "minimum_fetch_interval_seconds" to 43200, "fetch_timeout_seconds" to 60)
        "SetDefaults" -> mapOf("id" to Fixture.id(2), "defaults" to mapOf("quality" to mapOf("type" to "integer", "value" to 80)))
        "GetValues" -> mapOf("values" to mapOf("quality" to "integer"))
        else -> mapOf("id" to Fixture.id(2))
    }
}

class RemoteConfigBridgeDispatcherInstrumentedTest {
    private fun dispatcher(f: RemoteConfigCoordinatorFixture) = RemoteConfigBridgeDispatcher { f.coordinator }

    @Test fun invalidMutationsNeverCreateTheRuntimeOrExposeInputText() {
        var creations = 0
        val bridge = RemoteConfigBridgeDispatcher { creations++; throw AssertionError("Runtime must not be created") }
        val invalid = bridge.execute("SetDefaults", mapOf("id" to "private invalid id", "defaults" to emptyMap<String, Any>()))
        assertEquals("rejected", invalid["status"])
        assertEquals(Contract.INVALID_REQUEST_ID, invalid["errorCode"])
        assertSame(JSONObject.NULL, invalid["id"])
        assertFalse(JSONObject(invalid).toString().contains("private"))
        assertEquals(0, creations)
    }
    @Test fun unknownMethodsNeverCreateTheRuntime() {
        val bridge = RemoteConfigBridgeDispatcher { throw AssertionError() }
        assertEquals(Contract.INVALID_METHOD, bridge.execute("Unknown", emptyMap())["errorCode"])
    }
    @Test fun malformedQueriesUseTheirCorrectFailureShapes() {
        val bridge = RemoteConfigBridgeDispatcher { throw AssertionError() }
        val values = bridge.execute("GetValues", mapOf("values" to mapOf("bad.key" to "string")))
        assertEquals(Contract.INVALID_KEY, values["errorCode"])
        assertTrue((values["values"] as JSONObject).length() == 0)
        assertEquals(setOf("success", "values", "errorCode"), values.keys)
        assertEquals(setOf("success", "errorCode"), bridge.execute("GetInfo", mapOf("extra" to true)).keys)
        assertEquals(setOf("platform", "available", "errorCode"), bridge.execute("IsAvailable", mapOf("extra" to true)).keys)
    }
    @Test fun oversizedInputsAreRejectedBeforeRuntimeCreation() {
        val bridge = RemoteConfigBridgeDispatcher { throw AssertionError() }
        val result = bridge.execute("Fetch", mapOf("id" to Fixture.id(2), "extra" to "x".repeat(8192)))
        assertEquals("rejected", result["status"])
        assertEquals(Contract.REQUEST_TOO_LARGE, result["errorCode"])
    }
    @Test fun availabilityInfoAndValuesDispatchToTheCoordinator() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        val bridge = dispatcher(f)
        assertEquals(true, bridge.execute("IsAvailable", emptyMap())["available"])
        assertEquals(true, bridge.execute("GetInfo", emptyMap())["ready"])
        val values = JSONObject(bridge.execute("GetValues", RemoteConfigBridgeTestFixture.parameters("GetValues")))
        assertTrue(values.getBoolean("success"))
        assertEquals(80, values.getJSONObject("values").getJSONObject("quality").getInt("value"))
        assertEquals("default", values.getJSONObject("values").getJSONObject("quality").getString("source"))
    }
    @Test fun everyMutationDispatchesItsExactOperationAndIdWithoutWaitingForSdk() {
        for (operation in Contract.OPERATIONS) {
            val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
            val result = dispatcher(f).execute(operation, RemoteConfigBridgeTestFixture.parameters(operation))
            assertEquals(operation, result["operation"])
            assertEquals(Fixture.id(2), result["id"])
            assertEquals("pending", result["status"])
            assertEquals(0, f.sdk.fetches)
            assertEquals(0, f.sdk.activations)
        }
    }
    @Test fun pollingReportsNotFoundThenActualCompletionWithTheSameId() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        val bridge = dispatcher(f)
        val input = RemoteConfigBridgeTestFixture.parameters("Fetch")
        assertEquals("not_found", bridge.execute("GetStatus", input)["status"])
        bridge.execute("Fetch", input); f.scheduler.runWorker(); f.fetchSuccess()
        val result = bridge.execute("GetStatus", input)
        assertEquals("succeeded", result["status"])
        assertEquals(Fixture.id(2), result["id"])
        assertEquals(true, result["fetched"])
        assertSame(JSONObject.NULL, result["activated"])
    }
    @Test fun readinessRejectionsRemainTerminalConfirmedRejections() {
        val f = RemoteConfigCoordinatorFixture()
        val result = dispatcher(f).execute("Fetch", RemoteConfigBridgeTestFixture.parameters("Fetch"))
        assertEquals("rejected", result["status"])
        assertEquals(true, result["terminal"])
        assertEquals(Contract.NOT_INITIALIZED, result["errorCode"])
    }
    @Test fun persistenceFailureCannotInventARejectedOrNotFoundJob() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.storage.failNext = true
        val bridge = dispatcher(f)
        val input = RemoteConfigBridgeTestFixture.parameters("Fetch")
        val failed = bridge.execute("Fetch", input)
        assertEquals(setOf("id", "operation", "success", "errorCode"), failed.keys)
        assertEquals(Contract.PERSIST_FAILED, failed["errorCode"])
        assertEquals(Fixture.id(2), failed["id"])
        val poll = bridge.execute("GetStatus", input)
        assertEquals(Contract.PERSIST_FAILED, poll["errorCode"])
        assertSame(JSONObject.NULL, poll["operation"])
        assertFalse(poll.containsKey("status"))
    }
    @Test fun factoryStorageFailureIsControlledForReadMethodsToo() {
        val bridge = RemoteConfigBridgeDispatcher { throw RemoteConfigStorageException() }
        assertEquals(Contract.PERSIST_FAILED, bridge.execute("GetInfo", emptyMap())["errorCode"])
        assertEquals(false, bridge.execute("IsAvailable", emptyMap())["available"])
        assertEquals(Contract.PERSIST_FAILED, bridge.execute("GetValues", RemoteConfigBridgeTestFixture.parameters("GetValues"))["errorCode"])
    }
    @Test fun unexpectedExceptionsLinkageAndMemoryFailuresNeverExposePrivateDetails() {
        for ((failure, code) in listOf(IllegalStateException("private path") to Contract.NATIVE_UNAVAILABLE,
            LinkageError("private path") to Contract.NATIVE_UNAVAILABLE, OutOfMemoryError("private path") to Contract.LIMIT_EXCEEDED)) {
            val bridge = RemoteConfigBridgeDispatcher { throw failure }
            val result = bridge.execute("Fetch", RemoteConfigBridgeTestFixture.parameters("Fetch"))
            assertEquals(code, result["errorCode"])
            assertFalse(JSONObject(result).toString().contains("private"))
            assertEquals(Fixture.id(2), result["id"])
        }
    }
    @Test fun mainThreadCallsDoNotCreateRuntimeOrStartAnySdkWork() {
        var creations = 0
        val bridge = RemoteConfigBridgeDispatcher { creations++; throw AssertionError() }
        val results = mutableListOf<Map<String, Any>>()
        RemoteConfigBridgeTestFixture.onMain {
            for (method in Contract.METHODS) results.add(bridge.execute(method, RemoteConfigBridgeTestFixture.parameters(method)))
        }
        assertEquals(9, results.size)
        for (result in results) assertEquals(Contract.NATIVE_UNAVAILABLE, result["errorCode"])
        assertEquals(0, creations)
    }
    @Test fun publicReflectionTargetsHaveTheRequiredActivityConstructorAndBridgeInterface() {
        for (method in Contract.METHODS) {
            val name = "com.bbs.plugins.firebase_remote_config.FirebaseRemoteConfigFunctions\$" + method
            val type = Class.forName(name)
            assertTrue(BridgeFunction::class.java.isAssignableFrom(type))
            assertNotNull(type.getConstructor(FragmentActivity::class.java))
        }
        assertFalse(FirebaseRemoteConfigFunctions.Function::class.java.declaredFields.any { Activity::class.java.isAssignableFrom(it.type) })
    }
    @Test fun publicBridgeFunctionsActuallyReturnControlledUiThreadResponses() {
        val application = InstrumentationRegistry.getInstrumentation().targetContext.applicationContext
        val results = mutableListOf<Map<String, Any>>()
        RemoteConfigBridgeTestFixture.onMain {
            val activity = object : FragmentActivity() {
                override fun getApplicationContext(): Context = application
            }
            for (method in Contract.METHODS) {
                val type = Class.forName("com.bbs.plugins.firebase_remote_config.FirebaseRemoteConfigFunctions\$" + method)
                val function = type.getConstructor(FragmentActivity::class.java).newInstance(activity) as BridgeFunction
                results.add(function.execute(RemoteConfigBridgeTestFixture.parameters(method)))
            }
        }
        assertEquals(9, results.size)
        for (result in results) assertEquals(Contract.NATIVE_UNAVAILABLE, result["errorCode"])
    }
}
