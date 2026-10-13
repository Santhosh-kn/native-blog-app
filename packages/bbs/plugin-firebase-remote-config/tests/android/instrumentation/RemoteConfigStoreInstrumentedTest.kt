package com.bbs.plugins.firebase_remote_config

import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract
import com.bbs.plugins.firebase_remote_config.RemoteConfigStorageTestFixture as Fixture

class RemoteConfigStoreInstrumentedTest {
    private fun accept(store: RemoteConfigStore, request: RemoteConfigRequest = Fixture.request()): RemoteConfigOperationResult =
        (store.admit(request) as RemoteConfigAdmission.Accepted).result
    private fun run(store: RemoteConfigStore, pending: RemoteConfigOperationResult): RemoteConfigOperationResult {
        val running = pending.running(1000)
        assertTrue(store.replace(pending, running))
        return running
    }
    private fun persistenceFailure(block: () -> Unit) {
        try { block(); fail("Persistence failure was hidden") } catch (_: RemoteConfigStorageException) { }
    }
    private fun rejectedCode(admission: RemoteConfigAdmission) = (admission as RemoteConfigAdmission.Rejected).result.errorCode

    @Test fun acceptanceAndRunningAreSavedBeforeReturning() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val pending = accept(store)
        assertEquals(pending, storage.durable.single().result)
        assertEquals(Fixture.id(), store.activeId())
        val running = run(store, pending)
        assertEquals(running, storage.durable.single().result)
    }
    @Test fun sameRequestReplaysEveryStateWithoutAnotherWrite() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val pending = accept(store)
        assertEquals(pending, (store.admit(Fixture.request()) as RemoteConfigAdmission.Replay).result)
        assertEquals(1, storage.writes)
        val running = run(store, pending)
        assertEquals(running, (store.admit(Fixture.request()) as RemoteConfigAdmission.Replay).result)
        val finished = running.succeeded(2000)
        assertTrue(store.replace(running, finished))
        assertEquals(finished, (store.admit(Fixture.request()) as RemoteConfigAdmission.Replay).result)
        assertEquals(3, storage.writes)
        assertNull(store.activeId())
    }
    @Test fun sameIdWithDifferentOperationIsAConflictBeforeTheBusyCheck() {
        val store = RemoteConfigStore(RemoteConfigMemoryStorage())
        accept(store)
        assertEquals(Contract.REQUEST_ID_CONFLICT, rejectedCode(store.admit(Fixture.request("Activate"))))
    }
    @Test fun sameIdWithChangedTypedDefaultIsAConflict() {
        val store = RemoteConfigStore(RemoteConfigMemoryStorage())
        val request = RemoteConfigRequest("SetDefaults", Fixture.id(), defaults = mapOf("quality" to RemoteConfigDefault("integer", 80)))
        accept(store, request)
        assertEquals(Contract.REQUEST_ID_CONFLICT, rejectedCode(store.admit(request.copy(defaults =
            mapOf("quality" to RemoteConfigDefault("decimal", 80.0))))))
    }
    @Test fun differentRequestIsBusyAndItsRejectionDoesNotOccupyHistory() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val running = run(store, accept(store))
        assertEquals(Contract.BUSY, rejectedCode(store.admit(Fixture.request(number = 2))))
        assertEquals("not_found", store.get(Fixture.id(2)).status)
        assertEquals(2, storage.writes)
        assertTrue(store.replace(running, running.succeeded(2000)))
        accept(store, Fixture.request(number = 2))
    }
    @Test fun pendingRecordRecoversAsInterruptedBeforePolling() {
        val storage = RemoteConfigMemoryStorage()
        accept(RemoteConfigStore(storage))
        val restarted = RemoteConfigStore(storage) { 5000 }
        val result = restarted.get(Fixture.id())
        assertEquals("interrupted", result.status)
        assertEquals(Contract.PROCESS_INTERRUPTED, result.errorCode)
        assertEquals(5000L, result.startedAtMs)
        assertEquals(5000L, result.finishedAtMs)
        assertEquals(result, storage.durable.single().result)
        assertEquals(result, (restarted.admit(Fixture.request()) as RemoteConfigAdmission.Replay).result)
        assertNull(restarted.activeId())
    }
    @Test fun runningCombinedRecoveryKeepsFetchProgressAndNeverClaimsActivation() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val running = run(store, accept(store, Fixture.request("FetchAndActivate")))
        assertTrue(store.replace(running, running.markFetched()))
        val restarted = RemoteConfigStore(storage) { 500 }
        val result = restarted.get(Fixture.id())
        assertEquals("interrupted", result.status)
        assertEquals(true, result.fetched)
        assertNull(result.activated)
        assertEquals(1000L, result.startedAtMs)
        assertEquals(1000L, result.finishedAtMs)
    }
    @Test fun terminalRecordsSurviveRestartWithoutBeingRewritten() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val running = run(store, accept(store))
        val result = running.failed(Contract.THROTTLED, 2000, 3000)
        assertTrue(store.replace(running, result))
        val before = storage.writes
        val restarted = RemoteConfigStore(storage) { 5000 }
        assertEquals(result, restarted.get(Fixture.id()))
        assertEquals(before, storage.writes)
    }
    @Test fun staleFetchCallbackCannotReplaceNewerProgress() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val running = run(store, accept(store, Fixture.request("FetchAndActivate")))
        val fetched = running.markFetched()
        assertTrue(store.replace(running, fetched))
        assertFalse(store.replace(running, running.failed(Contract.FETCH_FAILED, 2000)))
        assertFalse(store.replace(fetched, fetched))
        assertEquals(fetched, store.get(Fixture.id()))
        assertEquals(3, storage.writes)
    }
    @Test fun lateCompletionCannotOverwriteTimeoutOrASeparateNewOperation() {
        val store = RemoteConfigStore(RemoteConfigMemoryStorage())
        val running = run(store, accept(store))
        val timeout = running.failed(Contract.OPERATION_TIMEOUT, 2000)
        assertTrue(store.replace(running, timeout))
        val newer = accept(store, Fixture.request(number = 2))
        assertFalse(store.replace(running, running.succeeded(3000)))
        assertFalse(store.replace(timeout, timeout.copy(status = "succeeded", success = true)))
        assertEquals(timeout, store.get(Fixture.id()))
        assertEquals(newer, store.get(Fixture.id(2)))
    }
    @Test(expected = IllegalArgumentException::class)
    fun transitionCannotChangeTheId() {
        val store = RemoteConfigStore(RemoteConfigMemoryStorage())
        val pending = accept(store)
        store.replace(pending, pending.running(1000).copy(id = Fixture.id(2)))
    }
    @Test(expected = IllegalArgumentException::class)
    fun completionCannotChangeTheStartTimestamp() {
        val store = RemoteConfigStore(RemoteConfigMemoryStorage())
        val running = run(store, accept(store))
        store.replace(running, running.succeeded(2000).copy(startedAtMs = 1500))
    }
    @Test fun oldTerminalHistoryIsPrunedWhileTheNewestActiveIdRemains() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        for (index in 1..50) {
            val running = run(store, accept(store, Fixture.request(number = index)))
            assertTrue(store.replace(running, running.succeeded(2000)))
        }
        val newest = accept(store, Fixture.request(number = 51))
        assertEquals(50, storage.durable.size)
        assertEquals("not_found", store.get(Fixture.id(1)).status)
        assertEquals("succeeded", store.get(Fixture.id(2)).status)
        assertEquals(newest, store.get(Fixture.id(51)))
        assertEquals(Fixture.id(51), store.activeId())
    }
    @Test fun failedAcceptanceFreezesTheStoreAndDoesNotInventAnAcknowledgement() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        storage.failNext = true
        persistenceFailure { store.admit(Fixture.request()) }
        assertTrue(storage.durable.isEmpty())
        persistenceFailure { store.get(Fixture.id()) }
        persistenceFailure { store.admit(Fixture.request(number = 2)) }
        assertEquals(1, storage.writes)
    }
    @Test fun committedButUnacknowledgedAcceptanceIsRecoverableAfterRestart() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        storage.commitThenFail = true
        persistenceFailure { store.admit(Fixture.request()) }
        assertEquals("pending", storage.durable.single().result.status)
        persistenceFailure { store.get(Fixture.id()) }
        val recovered = RemoteConfigStore(storage) { 5000 }
        assertEquals("interrupted", recovered.get(Fixture.id()).status)
        assertTrue(recovered.admit(Fixture.request()) is RemoteConfigAdmission.Replay)
    }
    @Test fun memoryFailureAfterCommitAlsoFreezesTheStore() {
        var durable = emptyList<RemoteConfigStoredRecord>()
        val storage = object : RemoteConfigRecordStorage {
            override fun load() = durable
            override fun save(records: List<RemoteConfigStoredRecord>) {
                durable = records.toList()
                throw OutOfMemoryError("private details")
            }
        }
        val store = RemoteConfigStore(storage)
        try { store.admit(Fixture.request()); fail() } catch (_: OutOfMemoryError) { }
        assertEquals("pending", durable.single().result.status)
        persistenceFailure { store.get(Fixture.id()) }
    }
    @Test fun failedCompletionKeepsPreviousDurableStateAndFreezesPolling() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val running = run(store, accept(store))
        storage.failNext = true
        persistenceFailure { store.replace(running, running.succeeded(2000)) }
        assertEquals(running, storage.durable.single().result)
        persistenceFailure { store.get(Fixture.id()) }
        assertEquals("interrupted", RemoteConfigStore(storage) { 5000 }.get(Fixture.id()).status)
    }
    @Test fun failedRecoveryDoesNotAllowANewOperationToEraseHistory() {
        val storage = RemoteConfigMemoryStorage()
        accept(RemoteConfigStore(storage))
        storage.failNext = true
        persistenceFailure { RemoteConfigStore(storage) { 5000 } }
        assertEquals("pending", storage.durable.single().result.status)
    }
    @Test fun actualDiskHistoryIsRecoveredAcrossStoreInstances() {
        val directory = Fixture.directory()
        try {
            val original = RemoteConfigStore(RemoteConfigStorage(directory))
            run(original, accept(original))
            val restarted = RemoteConfigStore(RemoteConfigStorage(directory)) { 5000 }
            assertEquals("interrupted", restarted.get(Fixture.id()).status)
            assertEquals("interrupted", RemoteConfigStorage(directory).load().single().result.status)
        } finally { directory.deleteRecursively() }
    }
    @Test fun concurrentAdmissionsAcceptExactlyOneMutation() {
        val storage = RemoteConfigMemoryStorage()
        val store = RemoteConfigStore(storage)
        val executor = Executors.newFixedThreadPool(4)
        try {
            val futures = (1..8).map { index -> executor.submit<RemoteConfigAdmission> { store.admit(Fixture.request(number = index)) } }
            val admissions = futures.map { it.get(10, TimeUnit.SECONDS) }
            assertEquals(1, admissions.count { it is RemoteConfigAdmission.Accepted })
            assertEquals(7, admissions.count { it is RemoteConfigAdmission.Rejected })
            assertEquals(1, storage.writes)
        } finally { executor.shutdownNow() }
    }
    @Test fun storeConstructionOnMainThreadIsRejectedBeforeLoading() {
        var rejected = false
        var loads = 0
        val storage = object : RemoteConfigRecordStorage {
            override fun load(): List<RemoteConfigStoredRecord> { loads++; return emptyList() }
            override fun save(records: List<RemoteConfigStoredRecord>) { fail() }
        }
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            try { RemoteConfigStore(storage) } catch (_: IllegalStateException) { rejected = true }
        }
        assertTrue(rejected)
        assertEquals(0, loads)
    }
}
