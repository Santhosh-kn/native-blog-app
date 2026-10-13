package com.bbs.plugins.firebase_remote_config

import androidx.test.ext.junit.runners.AndroidJUnit4
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

@RunWith(AndroidJUnit4::class)
class RemoteConfigResultInstrumentedTest {
    private val id = "123e4567-e89b-42d3-a456-426614174000"
    private fun pending(operation: String = "Fetch") = RemoteConfigOperationResult.pending(RemoteConfigRequest(operation, id))

    @Test fun pendingRecordIncludesEveryNullableField() {
        val json = JSONObject(pending().toBridgeMap())
        assertEquals(setOf("id", "operation", "accepted", "status", "terminal", "success", "fetched", "activated",
            "startedAtMs", "finishedAtMs", "throttleEndTimeMs", "errorCode"), json.keys().asSequence().toSet())
        for (key in listOf("fetched", "activated", "startedAtMs", "finishedAtMs", "throttleEndTimeMs", "errorCode")) {
            assertTrue(key, json.has(key))
            assertTrue(key, json.isNull(key))
        }
        assertFalse(json.getBoolean("terminal"))
        assertFalse(json.getBoolean("success"))
    }

    @Test fun runningRecordHasAStartTimestampAndNoFinish() {
        val result = pending().running(1000)
        assertEquals("running", result.status)
        assertEquals(1000L, result.startedAtMs)
        assertNull(result.finishedAtMs)
        assertFalse(result.terminal)
    }

    @Test fun fetchSuccessDoesNotClaimActivation() {
        val result = pending().running(1000).succeeded(2000)
        assertEquals("succeeded", result.status)
        assertEquals(true, result.fetched)
        assertNull(result.activated)
        assertTrue(result.success)
        assertTrue(result.terminal)
        assertEquals(2000L, result.finishedAtMs)
    }

    @Test fun activateSuccessDistinguishesNewActivation() {
        val result = pending("Activate").running(1000).succeeded(2000, true)
        assertEquals("succeeded", result.status)
        assertEquals(true, result.activated)
        assertNull(result.fetched)
    }

    @Test fun unchangedActivationIsSuccessfulAndFalseSurvivesJson() {
        val result = pending("Activate").running(1000).succeeded(2000, false)
        val json = JSONObject(result.toBridgeMap())
        assertEquals("unchanged", result.status)
        assertTrue(result.success)
        assertFalse(json.isNull("activated"))
        assertFalse(json.getBoolean("activated"))
        assertTrue(json.isNull("fetched"))
        assertFalse(json.has("data"))
    }

    @Test fun combinedOperationRecordsFetchBeforeActivation() {
        val fetching = pending("FetchAndActivate").running(1000)
        val activating = fetching.markFetched()
        assertEquals("running", activating.status)
        assertEquals(true, activating.fetched)
        assertNull(activating.activated)
        val finished = activating.succeeded(2000, true)
        assertEquals(true, finished.fetched)
        assertEquals(true, finished.activated)
    }

    @Test fun combinedUnchangedIsSuccessful() {
        val result = pending("FetchAndActivate").running(1000).markFetched().succeeded(2000, false)
        assertEquals("unchanged", result.status)
        assertTrue(result.success)
        assertEquals(true, result.fetched)
        assertEquals(false, result.activated)
    }

    @Test fun combinedFailureRetainsCompletedFetchWithoutInventingActivation() {
        val result = pending("FetchAndActivate").running(1000).markFetched().failed(Contract.ACTIVATION_FAILED, 2000)
        assertEquals(true, result.fetched)
        assertNull(result.activated)
        assertFalse(result.success)
        assertEquals("failed", result.status)
    }

    @Test fun configureAndDefaultsSuccessHaveNoNetworkOutcomeFlags() {
        for (operation in listOf("Configure", "SetDefaults")) {
            val result = pending(operation).running(1000).succeeded(2000)
            assertNull(result.fetched)
            assertNull(result.activated)
            assertTrue(result.success)
        }
    }

    @Test fun timeoutIsTerminalWithoutAnActivationClaim() {
        val result = pending("Activate").running(1000).failed(Contract.OPERATION_TIMEOUT, 2000)
        assertEquals("timed_out", result.status)
        assertFalse(result.success)
        assertTrue(result.terminal)
        assertNull(result.activated)
    }

    @Test fun interruptedOperationsHaveTheMatchingCode() {
        val result = pending().running(1000).failed(Contract.PROCESS_INTERRUPTED, 2000)
        assertEquals("interrupted", result.status)
        assertEquals(Contract.PROCESS_INTERRUPTED, result.errorCode)
    }

    @Test fun throttleFailureContainsOnlyControlledMetadata() {
        val result = pending().running(1000).failed(Contract.THROTTLED, 2000, 5000)
        val json = JSONObject(result.toBridgeMap())
        assertEquals(5000L, result.throttleEndTimeMs)
        assertEquals(Contract.THROTTLED, json.getString("errorCode"))
        assertFalse(json.has("errorMessage"))
    }

    @Test fun rejectedRequestWasNotAcceptedOrStarted() {
        val result = RemoteConfigOperationResult.rejected(id, "Fetch", Contract.BUSY)
        assertFalse(result.accepted)
        assertTrue(result.terminal)
        assertFalse(result.success)
        assertNull(result.startedAtMs)
        assertNull(result.finishedAtMs)
    }

    @Test fun invalidRejectedIdIsNotEchoedBack() {
        val result = RemoteConfigOperationResult.rejected("private unexpected input", "Fetch", Contract.INVALID_REQUEST_ID)
        assertNull(result.id)
        assertTrue(JSONObject(result.toBridgeMap()).isNull("id"))
    }

    @Test fun notFoundHasNoOperationOrOutcome() {
        val result = RemoteConfigOperationResult.notFound(id)
        val json = JSONObject(result.toBridgeMap())
        assertEquals("not_found", result.status)
        assertNull(result.operation)
        assertNull(result.fetched)
        assertNull(result.activated)
        assertEquals(Contract.RESULT_NOT_FOUND, result.errorCode)
        assertTrue(json.isNull("operation"))
    }

    @Test fun finishTimestampDoesNotGoBackwardsWhenWallClockChanges() {
        val result = pending().running(2000).succeeded(1000)
        assertEquals(2000L, result.finishedAtMs)
    }

    @Test(expected = IllegalArgumentException::class)
    fun completedOperationCannotBeCompletedAgain() {
        pending().running(1000).succeeded(2000).succeeded(3000)
    }

    @Test(expected = IllegalArgumentException::class)
    fun completedOperationCannotFailLater() {
        pending().running(1000).succeeded(2000).failed(Contract.FETCH_FAILED, 3000)
    }

    @Test(expected = IllegalArgumentException::class)
    fun combinedSuccessRequiresACompletedFetch() {
        pending("FetchAndActivate").running(1000).succeeded(2000, true)
    }

    @Test(expected = IllegalArgumentException::class)
    fun activateSuccessRequiresAnExplicitSdkBoolean() {
        pending("Activate").running(1000).succeeded(2000)
    }

    @Test(expected = IllegalArgumentException::class)
    fun ordinaryFetchCannotClaimActivation() {
        pending().running(1000).succeeded(2000, true)
    }

    @Test(expected = IllegalArgumentException::class)
    fun unknownExceptionTextCannotBecomeAnErrorCode() {
        pending().running(1000).failed("raw SDK exception text", 2000)
    }

    @Test(expected = IllegalArgumentException::class)
    fun throttleTimestampRequiresTheThrottleErrorCode() {
        pending().running(1000).failed(Contract.FETCH_FAILED, 2000, 5000)
    }

    @Test(expected = IllegalArgumentException::class)
    fun startTimestampMustBePositive() {
        pending().running(0)
    }

    @Test fun responseSizeGuardRejectsOversizedAndNonFiniteReplies() {
        assertTrue(Contract.boundedResponse(pending().toBridgeMap()))
        assertFalse(Contract.boundedResponse(mapOf("text" to "x".repeat(Contract.MAX_RESPONSE_BYTES))))
        assertFalse(Contract.boundedResponse(mapOf("number" to Double.NaN)))
    }
}
