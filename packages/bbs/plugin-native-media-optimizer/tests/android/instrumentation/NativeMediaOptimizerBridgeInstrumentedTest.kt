package com.bbs.plugins.native_media_optimizer

import androidx.fragment.app.FragmentActivity
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.UUID
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerBridgeInstrumentedTest {
    @Test fun everyManifestTargetHasTheActivityConstructorAndBridgeInterface() {
        for (name in listOf("IsAvailable", "InspectMedia", "OptimizeImage", "OptimizeVideo", "GenerateThumbnail", "GetStatus", "Cancel", "GetResult", "DeleteOutput")) {
            val target = Class.forName("com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerFunctions\$$name")
            assertNotNull(target.getConstructor(FragmentActivity::class.java))
            assertTrue(com.nativephp.mobile.bridge.BridgeFunction::class.java.isAssignableFrom(target))
        }
    }

    @Test fun queryValidationRejectsPathsUnknownKeysWrongTypesAndOversizedValues() {
        val id = UUID.randomUUID().toString()
        assertNull(NativeMediaOptimizerFunctions.queryError(mapOf("id" to id)))
        assertEquals(Contract.INVALID_OPTIONS, NativeMediaOptimizerFunctions.queryError(mapOf("id" to id, "path" to "/tmp/media")))
        assertEquals(Contract.INVALID_OPTIONS, NativeMediaOptimizerFunctions.queryError(emptyMap<String, Any>()))
        assertEquals(Contract.INVALID_REQUEST_ID, NativeMediaOptimizerFunctions.queryError(mapOf("id" to "../source")))
        assertEquals(Contract.INVALID_REQUEST_ID, NativeMediaOptimizerFunctions.queryError(mapOf("id" to 5)))
        assertEquals(Contract.REQUEST_TOO_LARGE, NativeMediaOptimizerFunctions.queryError(mapOf("id" to "x".repeat(9000))))
    }

    @Test fun availabilityAndDeletionMatchThePhpWireContractWithoutPaths() {
        val availability = NativeMediaOptimizerCapabilities.read()
        assertEquals(setOf("platform", "available", "images", "video", "thumbnails"), availability.keys)
        assertEquals("android", availability["platform"])
        assertEquals(true, availability["images"])
        assertEquals(availability["images"] == true || availability["video"] == true || availability["thumbnails"] == true, availability["available"])
        val id = UUID.randomUUID().toString()
        val deleted = NativeMediaOptimizerCoordinator.deletion(id, true, null)
        assertEquals(setOf("id", "deleted", "errorCode", "errorMessage"), deleted.keys)
        assertSame(JSONObject.NULL, deleted["errorCode"])
        val failed = NativeMediaOptimizerCoordinator.deletion("../bad", false, Contract.INVALID_REQUEST_ID)
        assertSame(JSONObject.NULL, failed["id"])
        assertEquals(Contract.message(Contract.INVALID_REQUEST_ID), failed["errorMessage"])
    }
}
