package com.bbs.plugins.firebase_remote_config

import androidx.test.platform.app.InstrumentationRegistry
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract
import com.bbs.plugins.firebase_remote_config.RemoteConfigStorageTestFixture as Fixture

class RemoteConfigCoordinatorInstrumentedTest {
    private fun error(map: Map<String, Any>) = map["errorCode"]
    private fun persistFailure(block: () -> Unit) {
        try { block(); fail("Persistence failure hidden") }
        catch (failure: RemoteConfigCoordinatorException) { assertEquals(Contract.PERSIST_FAILED, failure.code) }
    }

    @Test fun initializationIsExplicitAsynchronousAndNeverFetches() {
        val f = RemoteConfigCoordinatorFixture()
        assertEquals(false, f.coordinator.getInfo()["ready"])
        f.coordinator.initialize()
        f.coordinator.initialize()
        assertEquals(0, f.sdk.initializes)
        assertEquals(true, f.coordinator.getInfo()["sdkBusy"])
        f.scheduler.runWorker()
        assertEquals(1, f.sdk.initializes)
        assertEquals(0, f.sdk.fetches)
        assertEquals(0, f.sdk.activations)
        assertEquals(0, f.sdk.defaultRegistrations)
        f.sdk.initializeCallback(RemoteConfigSdkOutcome.Success(Unit))
        f.scheduler.runWorker()
        assertEquals(true, f.coordinator.getInfo()["ready"])
        assertEquals(false, f.coordinator.getInfo()["defaultsReady"])
    }
    @Test fun initializationFailureIsControlledAndDoesNotCreateAnOperation() {
        val f = RemoteConfigCoordinatorFixture()
        f.coordinator.initialize(); f.scheduler.runWorker()
        f.sdk.initializeCallback(RemoteConfigSdkOutcome.Failure(Contract.NOT_INITIALIZED)); f.scheduler.runWorker()
        assertEquals(Contract.NOT_INITIALIZED, error(f.coordinator.getInfo()))
        assertEquals(false, f.coordinator.availability()["available"])
        assertEquals(Contract.NOT_INITIALIZED, f.start().errorCode)
        assertTrue(f.storage.durable.isEmpty())
    }
    @Test fun queuedInitializationExpiresWithoutStartingSdkWork() {
        val f = RemoteConfigCoordinatorFixture()
        f.coordinator.initialize(); f.scheduler.advance(15000); f.scheduler.runWorker()
        assertEquals(0, f.sdk.initializes)
        assertEquals(Contract.OPERATION_TIMEOUT, error(f.coordinator.getInfo()))
        assertEquals(Contract.NOT_INITIALIZED, f.start().errorCode)
    }
    @Test fun lateCacheInitializationCanBecomeReadyWithoutImplicitDefaults() {
        val f = RemoteConfigCoordinatorFixture()
        f.coordinator.initialize(); f.scheduler.runWorker(); f.scheduler.advance(15000)
        assertEquals(Contract.OPERATION_TIMEOUT, error(f.coordinator.getInfo()))
        f.sdk.initializeCallback(RemoteConfigSdkOutcome.Success(Unit)); f.scheduler.runWorker()
        assertEquals(true, f.coordinator.getInfo()["ready"])
        assertEquals(false, f.coordinator.getInfo()["defaultsReady"])
        assertEquals(Contract.DEFAULTS_NOT_READY, f.start().errorCode)
    }
    @Test fun duplicateInitializationCallbackDoesNotUndoReadiness() {
        val f = RemoteConfigCoordinatorFixture(); f.ready()
        f.sdk.initializeCallback(RemoteConfigSdkOutcome.Failure(Contract.NOT_INITIALIZED)); f.scheduler.runWorker()
        assertEquals(true, f.coordinator.getInfo()["ready"])
    }
    @Test fun mutationsBeforeReadyAreRejectedWithoutPersistenceOrSdkCalls() {
        val f = RemoteConfigCoordinatorFixture()
        val result = f.start()
        assertEquals("rejected", result.status)
        assertEquals(Contract.NOT_INITIALIZED, result.errorCode)
        assertEquals(0, f.storage.writes)
        assertEquals(0, f.sdk.fetches)
    }
    @Test fun initializedSdkRequiresDefaultsForFetchActivateAndReads() {
        val f = RemoteConfigCoordinatorFixture(); f.ready()
        for (operation in listOf("Fetch", "Activate", "FetchAndActivate")) assertEquals(Contract.DEFAULTS_NOT_READY, f.start(operation).errorCode)
        assertEquals(Contract.DEFAULTS_NOT_READY, error(f.coordinator.getValues(mapOf("quality" to "integer"))))
        assertEquals(0, f.sdk.reads)
    }
    @Test fun settingsCompleteAsynchronouslyAndInfoReflectsTheActualSdk() {
        val f = RemoteConfigCoordinatorFixture(); f.ready()
        val settings = RemoteConfigSettings(0, 1)
        val request = RemoteConfigRequest("Configure", Fixture.id(), settings)
        assertEquals("pending", f.coordinator.start(request).status)
        f.scheduler.runWorker()
        assertEquals(settings, f.sdk.configured)
        assertEquals("running", f.coordinator.getStatus(Fixture.id()).status)
        f.sdk.metadata = RemoteConfigSdkInfo("no_fetch_yet", null, 0, 1)
        f.sdk.configureCallback(RemoteConfigSdkOutcome.Success(Unit)); f.scheduler.runWorker()
        assertEquals("succeeded", f.coordinator.getStatus(Fixture.id()).status)
        assertEquals(0L, f.coordinator.getInfo()["minimumFetchIntervalSeconds"])
        assertEquals(1L, f.coordinator.getInfo()["fetchTimeoutSeconds"])
    }
    @Test fun defaultsBlockReadsUntilPersistedSuccess() {
        val f = RemoteConfigCoordinatorFixture(); f.ready()
        assertEquals("pending", f.coordinator.start(f.defaultsRequest()).status)
        assertEquals(Contract.BUSY, error(f.coordinator.getValues(mapOf("quality" to "integer"))))
        f.scheduler.runWorker()
        assertEquals(false, f.coordinator.getInfo()["defaultsReady"])
        f.sdk.defaultsCallback(RemoteConfigSdkOutcome.Success(Unit)); f.scheduler.runWorker()
        assertEquals(true, f.coordinator.getInfo()["defaultsReady"])
        assertEquals(true, f.coordinator.getValues(mapOf("quality" to "integer"))["success"])
    }
    @Test fun defaultsFailureRequiresAnExplicitNewRegistration() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.coordinator.start(f.defaultsRequest(2)); f.scheduler.runWorker()
        f.sdk.defaultsCallback(RemoteConfigSdkOutcome.Failure(Contract.DEFAULTS_FAILED)); f.scheduler.runWorker()
        assertEquals("failed", f.status().status)
        assertEquals(false, f.coordinator.getInfo()["defaultsReady"])
        assertEquals(Contract.DEFAULTS_NOT_READY, f.start(number = 3).errorCode)
        f.defaults(3)
        assertEquals(true, f.coordinator.getInfo()["defaultsReady"])
    }
    @Test fun fetchSuccessDoesNotActivateAndReadsRemainUsableDuringFetch() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        val pending = f.start()
        assertEquals("pending", pending.status)
        assertEquals(0, f.sdk.fetches)
        assertEquals(Fixture.id(2), f.coordinator.getInfo()["activeOperationId"])
        f.scheduler.runWorker()
        assertEquals("running", f.status().status)
        assertEquals(true, f.coordinator.getValues(mapOf("show" to "boolean"))["success"])
        f.sdk.metadata = RemoteConfigSdkInfo("success", 2000, 43200, 60)
        f.fetchSuccess()
        val result = f.status()
        assertEquals("succeeded", result.status)
        assertEquals(true, result.fetched)
        assertNull(result.activated)
        assertEquals(0, f.sdk.activations)
        assertEquals("success", f.coordinator.getInfo()["lastFetchStatus"])
        assertEquals(2000L, f.coordinator.getInfo()["lastSuccessfulFetchAtMs"])
    }
    @Test fun activationFalseIsSuccessfulUnchanged() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("Activate"); f.scheduler.runWorker(); f.activateSuccess(false)
        val result = f.status()
        assertEquals("unchanged", result.status)
        assertTrue(result.success)
        assertEquals(false, result.activated)
        assertNull(result.fetched)
    }
    @Test fun activationTrueIsSuccessfulChanged() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("Activate"); f.scheduler.runWorker(); f.activateSuccess(true)
        assertEquals("succeeded", f.status().status)
        assertEquals(true, f.status().activated)
    }
    @Test fun activationBlocksReadsUntilItSettles() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("Activate")
        assertEquals(Contract.BUSY, error(f.coordinator.getValues(mapOf("quality" to "integer"))))
        f.scheduler.runWorker()
        assertEquals(Contract.BUSY, error(f.coordinator.getValues(mapOf("quality" to "integer"))))
        f.activateSuccess(false)
        assertEquals(true, f.coordinator.getValues(mapOf("quality" to "integer"))["success"])
    }
    @Test fun combinedFetchPersistsProgressBeforeStartingExplicitActivation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker()
        assertEquals(0, f.sdk.activations)
        assertEquals(true, f.coordinator.getValues(mapOf("quality" to "integer"))["success"])
        f.fetchSuccess()
        assertEquals(1, f.sdk.activations)
        assertEquals(true, f.status().fetched)
        assertEquals(true, f.storage.durable.last().result.fetched)
        assertEquals("running", f.status().status)
        assertEquals(Contract.BUSY, error(f.coordinator.getValues(mapOf("quality" to "integer"))))
        f.activateSuccess(false)
        assertEquals("unchanged", f.status().status)
        assertEquals(true, f.status().fetched)
        assertEquals(false, f.status().activated)
    }
    @Test fun failedCombinedFetchNeverStartsActivation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker()
        f.sdk.fetchCallback(RemoteConfigSdkOutcome.Failure(Contract.FETCH_FAILED)); f.scheduler.runWorker()
        assertEquals("failed", f.status().status)
        assertNull(f.status().fetched)
        assertEquals(0, f.sdk.activations)
    }
    @Test fun combinedActivationFailureKeepsKnownSuccessfulFetch() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker(); f.fetchSuccess()
        f.sdk.activationCallback(RemoteConfigSdkOutcome.Failure(Contract.ACTIVATION_FAILED)); f.scheduler.runWorker()
        assertEquals(Contract.ACTIVATION_FAILED, f.status().errorCode)
        assertEquals(true, f.status().fetched)
        assertNull(f.status().activated)
    }
    @Test fun overlappingIdsAreBusyAndSameIdReplaysWithoutRepeatingSdkWork() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        val pending = f.start()
        assertEquals(pending, f.start())
        assertEquals(Contract.BUSY, f.start(number = 3).errorCode)
        assertEquals(Contract.REQUEST_ID_CONFLICT, f.start("Activate").errorCode)
        f.scheduler.runWorker()
        assertEquals(f.status(), f.start())
        f.fetchSuccess()
        assertEquals(f.status(), f.start())
        f.scheduler.runWorker()
        assertEquals(1, f.sdk.fetches)
    }
    @Test fun unknownIdDoesNotCreateHistory() {
        val f = RemoteConfigCoordinatorFixture()
        assertEquals("not_found", f.status().status)
        assertTrue(f.storage.durable.isEmpty())
    }
    @Test fun throttlingKeepsItsControlledCodeAndRetryTimestamp() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker()
        f.sdk.fetchCallback(RemoteConfigSdkOutcome.Failure(Contract.THROTTLED, 5000)); f.scheduler.runWorker()
        assertEquals(Contract.THROTTLED, f.status().errorCode)
        assertEquals(5000L, f.status().throttleEndTimeMs)
        assertEquals(false, f.coordinator.getInfo()["sdkBusy"])
    }
    @Test fun malformedSdkFailureMetadataIsRejectedWithoutExceptionText() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker()
        f.sdk.fetchCallback(RemoteConfigSdkOutcome.Failure("private details", 0)); f.scheduler.runWorker()
        assertEquals(Contract.INVALID_NATIVE_RESPONSE, f.status().errorCode)
        assertNull(f.status().throttleEndTimeMs)
    }
    @Test fun queueDelayCountsTowardTheDeadlineAndCanPreventSdkStart() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("Activate"); f.scheduler.advance(15000); f.scheduler.runWorker()
        assertEquals("timed_out", f.status().status)
        assertEquals(0, f.sdk.activations)
        assertEquals(false, f.coordinator.getInfo()["sdkBusy"])
    }
    @Test fun lateFetchCannotOverwriteTimeoutAndKeepsBusyUntilSettlement() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker(); f.scheduler.advance(65000)
        val timeout = f.status()
        assertEquals("timed_out", timeout.status)
        assertEquals(true, f.coordinator.getInfo()["sdkBusy"])
        assertSame(JSONObject.NULL, f.coordinator.getInfo()["activeOperationId"])
        assertEquals(Contract.BUSY, f.start(number = 3).errorCode)
        assertEquals(timeout, f.start())
        assertEquals(true, f.coordinator.getValues(mapOf("quality" to "integer"))["success"])
        f.fetchSuccess()
        assertEquals(timeout, f.status())
        assertEquals(false, f.coordinator.getInfo()["sdkBusy"])
        assertEquals("pending", f.start(number = 3).status)
    }
    @Test fun combinedFetchDeliveredAfterDeadlineNeverStartsActivation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker(); f.scheduler.advance(80000)
        f.fetchSuccess()
        assertEquals("timed_out", f.status().status)
        assertEquals(0, f.sdk.activations)
        assertNull(f.status().activated)
    }
    @Test fun callbackChecksDeadlineEvenWhenTheTimerHasNotBeenDelivered() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker()
        f.scheduler.advance(80000, deliverTimers = false)
        f.fetchSuccess()
        assertEquals("timed_out", f.status().status)
        assertEquals(0, f.sdk.activations)
    }
    @Test fun combinedActivationTimeoutPreservesFetchedAndBlocksReadsUntilLateSettlement() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker(); f.fetchSuccess(); f.scheduler.advance(80000)
        val timeout = f.status()
        assertEquals(true, timeout.fetched)
        assertNull(timeout.activated)
        assertEquals(Contract.BUSY, error(f.coordinator.getValues(mapOf("quality" to "integer"))))
        f.activateSuccess(true)
        assertEquals(timeout, f.status())
        assertEquals(true, f.coordinator.getValues(mapOf("quality" to "integer"))["success"])
    }
    @Test fun lateDefaultsSuccessDoesNotSilentlyMarkDefaultsReady() {
        val f = RemoteConfigCoordinatorFixture(); f.ready()
        f.coordinator.start(f.defaultsRequest()); f.scheduler.runWorker(); f.scheduler.advance(15000)
        f.sdk.defaultsCallback(RemoteConfigSdkOutcome.Success(Unit)); f.scheduler.runWorker()
        assertEquals("timed_out", f.coordinator.getStatus(Fixture.id()).status)
        assertEquals(false, f.coordinator.getInfo()["defaultsReady"])
        assertEquals(Contract.DEFAULTS_NOT_READY, error(f.coordinator.getValues(mapOf("quality" to "integer"))))
        f.defaults(2)
        assertEquals(true, f.coordinator.getInfo()["defaultsReady"])
    }
    @Test fun lateConfigurationSettingsAreReadFromTheActualSdk() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        val request = RemoteConfigRequest("Configure", Fixture.id(2), RemoteConfigSettings(0, 1))
        f.coordinator.start(request); f.scheduler.runWorker(); f.scheduler.advance(15000)
        f.sdk.metadata = RemoteConfigSdkInfo("no_fetch_yet", null, 0, 1)
        f.sdk.configureCallback(RemoteConfigSdkOutcome.Success(Unit)); f.scheduler.runWorker()
        assertEquals("timed_out", f.status().status)
        assertEquals(1L, f.coordinator.getInfo()["fetchTimeoutSeconds"])
        f.start(number = 3); f.scheduler.runWorker(); f.scheduler.advance(6000)
        assertEquals("timed_out", f.status(3).status)
    }
    @Test fun duplicateFetchCallbackCannotStartAnotherActivationOrFinishTheWrongPhase() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker()
        val fetchCallback = f.sdk.fetchCallback
        f.fetchSuccess()
        fetchCallback(RemoteConfigSdkOutcome.Success(Unit)); f.scheduler.runWorker()
        assertEquals(1, f.sdk.activations)
        assertEquals("running", f.status().status)
        f.activateSuccess(true)
        fetchCallback(RemoteConfigSdkOutcome.Failure(Contract.FETCH_FAILED)); f.scheduler.runWorker()
        assertEquals("succeeded", f.status().status)
    }
    @Test fun oldCallbacksCannotAlterANewerOperation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker()
        val old = f.sdk.fetchCallback
        f.fetchSuccess()
        val previous = f.status()
        f.start(number = 3); f.scheduler.runWorker()
        old(RemoteConfigSdkOutcome.Failure(Contract.FETCH_FAILED)); f.scheduler.runWorker()
        assertEquals(previous, f.status())
        assertEquals("running", f.status(3).status)
        f.fetchSuccess()
        assertEquals("succeeded", f.status(3).status)
    }
    @Test fun wallClockChangesDoNotDriveDeadlinesOrMoveFinishBeforeStart() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker()
        val started = f.status().startedAtMs
        f.scheduler.wall = 1000000000
        f.scheduler.advance(64999)
        assertEquals("running", f.status().status)
        f.scheduler.wall = 500
        f.scheduler.advance(1)
        assertEquals("timed_out", f.status().status)
        assertTrue(requireNotNull(f.status().finishedAtMs) >= requireNotNull(started))
    }
    @Test fun admissionPersistenceTimeCountsTowardTheDeadline() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.beforeSave = { records ->
            if (records.last().result.operation == "Fetch" && records.last().result.status == "pending") {
                f.scheduler.advance(65000, deliverTimers = false)
            }
        }
        f.start(); f.scheduler.runWorker()
        assertEquals("timed_out", f.status().status)
        assertEquals(0, f.sdk.fetches)
    }
    @Test fun deadlineExpiringDuringFetchProgressPersistencePreventsActivation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker()
        f.beforeSave = { records ->
            val result = records.last().result
            if (result.status == "running" && result.fetched == true) f.scheduler.advance(80000, deliverTimers = false)
        }
        f.fetchSuccess()
        assertEquals("timed_out", f.status().status)
        assertEquals(true, f.status().fetched)
        assertEquals(0, f.sdk.activations)
    }
    @Test fun mutableCallerDefaultsAreCopiedBeforeAsyncExecution() {
        val f = RemoteConfigCoordinatorFixture(); f.ready()
        val defaults = linkedMapOf("quality" to RemoteConfigDefault("integer", 80L))
        f.coordinator.start(RemoteConfigRequest("SetDefaults", Fixture.id(), defaults = defaults))
        defaults.clear()
        f.scheduler.runWorker()
        assertEquals(80L, f.sdk.registered["quality"]?.value)
    }
    @Test fun failedAdmissionCannotStartSdkWorkOrPermitAnotherMutation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.storage.failNext = true
        persistFailure { f.start() }
        f.scheduler.runWorker()
        assertEquals(0, f.sdk.fetches)
        persistFailure { f.start(number = 3) }
        assertEquals(Contract.PERSIST_FAILED, error(f.coordinator.getInfo()))
    }
    @Test fun failedRunningPersistenceStopsBeforeTheSdkStart() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.storage.failNext = true; f.scheduler.runWorker()
        assertEquals(0, f.sdk.fetches)
        persistFailure { f.status() }
        assertEquals("pending", f.storage.durable.last().result.status)
    }
    @Test fun failedCombinedFetchProgressPersistenceNeverStartsActivation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start("FetchAndActivate"); f.scheduler.runWorker(); f.storage.failNext = true; f.fetchSuccess()
        assertEquals(0, f.sdk.activations)
        persistFailure { f.status() }
        assertEquals("running", f.storage.durable.last().result.status)
        assertNull(f.storage.durable.last().result.fetched)
    }
    @Test fun committedButUnacknowledgedCompletionIsObservableAfterRestart() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker(); f.storage.commitThenFail = true; f.fetchSuccess()
        persistFailure { f.status() }
        val recoveredStore = RemoteConfigStore(f.storage) { 5000 }
        val recovered = RemoteConfigCoordinator(f.sdk, recoveredStore, f.scheduler) { 5000 }
        assertEquals("succeeded", recovered.getStatus(Fixture.id(2)).status)
        assertEquals("succeeded", recovered.start(Fixture.request(number = 2)).status)
        assertEquals(1, f.sdk.fetches)
    }
    @Test fun timeoutPersistenceFailureIsSurfacedInsteadOfLeavingALoadingRecord() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker(); f.storage.failNext = true; f.scheduler.advance(65000)
        persistFailure { f.status() }
        assertEquals(Contract.PERSIST_FAILED, error(f.coordinator.getInfo()))
        assertEquals(false, f.coordinator.availability()["available"])
    }
    @Test fun workerQueueRejectionFinishesAcceptedWorkWithoutCallingSdk() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.scheduler.rejectExecute = true
        val result = f.start()
        assertTrue(result.accepted)
        assertEquals("failed", result.status)
        assertEquals(Contract.NATIVE_UNAVAILABLE, result.errorCode)
        assertEquals(0, f.sdk.fetches)
        assertEquals(false, f.coordinator.getInfo()["sdkBusy"])
    }
    @Test fun deadlineSchedulerRejectionFinishesAcceptedWorkWithoutCallingSdk() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.scheduler.rejectSchedule = true
        assertEquals(Contract.NATIVE_UNAVAILABLE, f.start().errorCode)
        assertEquals(0, f.sdk.fetches)
        assertEquals(false, f.coordinator.getInfo()["sdkBusy"])
    }
    @Test fun callbackQueueRejectionFailsClosedAndDoesNotStartAnotherSdkOperation() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker(); f.scheduler.rejectExecute = true
        f.sdk.fetchCallback(RemoteConfigSdkOutcome.Success(Unit))
        assertEquals(Contract.NATIVE_UNAVAILABLE, error(f.coordinator.getInfo()))
        try { f.start(number = 3); fail() } catch (failure: RemoteConfigCoordinatorException) {
            assertEquals(Contract.NATIVE_UNAVAILABLE, failure.code)
        }
        assertEquals(1, f.sdk.fetches)
    }
    @Test fun unexpectedSdkStartExceptionsAreControlled() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.sdk.fetchThrow = IllegalStateException("private SDK diagnostics")
        f.start(); f.scheduler.runWorker()
        assertEquals(Contract.FETCH_FAILED, f.status().errorCode)
        assertEquals(false, f.coordinator.getInfo()["sdkBusy"])
    }
    @Test fun sdkLinkageAndMemoryErrorsBecomeControlledOutcomes() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.sdk.fetchThrow = LinkageError("private SDK diagnostics")
        f.start(); f.scheduler.runWorker()
        assertEquals(Contract.NATIVE_UNAVAILABLE, f.status().errorCode)
        f.sdk.fetchThrow = null
        f.sdk.activateThrow = OutOfMemoryError("private SDK diagnostics")
        f.start("Activate", 3); f.scheduler.runWorker()
        assertEquals(Contract.LIMIT_EXCEEDED, f.status(3).errorCode)
    }
    @Test fun invalidMetadataIsControlledAndPreventsAnUnboundedFetch() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.sdk.metadata = f.sdk.metadata.copy(fetchTimeoutSeconds = 999)
        assertEquals(Contract.INVALID_NATIVE_RESPONSE, error(f.coordinator.getInfo()))
        try { f.start(); fail() } catch (failure: RemoteConfigCoordinatorException) {
            assertEquals(Contract.INVALID_NATIVE_RESPONSE, failure.code)
        }
        assertEquals(0, f.sdk.fetches)
    }
    @Test fun sdkCallbackArrivingOnMainThreadIsQueuedForWorkerProcessing() {
        val f = RemoteConfigCoordinatorFixture(); f.initializedDefaults()
        f.start(); f.scheduler.runWorker()
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            f.sdk.fetchCallback(RemoteConfigSdkOutcome.Success(Unit))
        }
        assertEquals("running", f.status().status)
        f.scheduler.runWorker()
        assertEquals("succeeded", f.status().status)
    }
    @Test fun mainThreadQueriesAndMutationsAreRejectedBeforeAnySdkOrDiskAccess() {
        val f = RemoteConfigCoordinatorFixture()
        var rejected = 0
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            for (action in listOf<() -> Unit>(
                { f.coordinator.initialize() }, { f.start() }, { f.status() },
                { f.coordinator.getInfo() }, { f.coordinator.getValues(mapOf("quality" to "integer")) },
                { f.coordinator.availability() })) {
                try { action() } catch (_: IllegalStateException) { rejected++ }
            }
        }
        assertEquals(6, rejected)
        assertEquals(0, f.storage.writes)
        assertEquals(0, f.sdk.initializes)
        assertEquals(0, f.sdk.reads)
    }
}
