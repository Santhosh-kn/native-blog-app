package com.bbs.plugins.native_calendar

import androidx.test.ext.junit.runners.AndroidJUnit4
import java.io.IOException
import java.util.UUID
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

@RunWith(AndroidJUnit4::class)
class NativeCalendarStoreInstrumentedTest {

    private lateinit var disk: MemoryFile
    private lateinit var store: NativeCalendarStore
    private lateinit var processId: String
    private var now = BASE_TIME

    @Before
    fun setUp() {
        disk = MemoryFile()
        processId = UUID.randomUUID().toString()
        now = BASE_TIME
        store = newStore()
    }

    @Test
    fun reservationWritesMetadataBeforeReturningPending() {
        val pending = begin()

        assertEquals(1, disk.writes)
        assertEquals(Contract.STATUS_PENDING, pending.result.status)
        assertTrue(pending.result.accepted)
        assertFalse(pending.result.success)
        assertEquals(BASE_TIME, pending.result.createdAtMs)
        assertNull(pending.result.completedAtMs)

        val json = JSONObject(requireNotNull(disk.bytes).toString(Charsets.UTF_8))
        val record = json.getJSONArray("results").getJSONObject(0)
        assertEquals(METADATA_KEYS, record.keys().asSequence().toSet())
        assertEquals(pending.result.id, record.getString("id"))
        assertEquals(
            NativeCalendarStore.PHASE_QUEUED,
            json.getJSONObject("active").getString("phase")
        )
    }

    @Test
    fun invalidIdentityOrBindingDoesNotAccessDisk() {
        val invalidId = store.begin(
            "invalid-fixture",
            Contract.CREATE_EVENT,
            Contract.TARGET_EDITOR
        ) as NativeCalendarBeginResult.Rejected
        assertEquals(Contract.INVALID_REQUEST_ID, invalidId.result.errorCode)

        val invalidBinding = store.begin(
            UUID.randomUUID().toString(),
            Contract.OPEN,
            Contract.TARGET_EDITOR
        ) as NativeCalendarBeginResult.Rejected
        assertEquals(Contract.INVALID_OPTIONS, invalidBinding.result.errorCode)

        assertEquals(0, disk.reads)
        assertEquals(0, disk.writes)
        assertNull(disk.bytes)
    }

    @Test
    fun duplicateIdentityIsRejectedWhilePendingAndAfterCompletion() {
        val pending = begin()

        assertEquals(
            Contract.REQUEST_ALREADY_EXISTS,
            rejection(pending.result.id).errorCode
        )

        assertNotNull(store.completeFailed(pending.handle, Contract.LAUNCH_FAILED))

        assertEquals(
            Contract.REQUEST_ALREADY_EXISTS,
            rejection(pending.result.id).errorCode
        )
    }

    @Test
    fun anotherIdentityIsRejectedWhileOneRequestIsPending() {
        val pending = begin()
        val writes = disk.writes

        assertEquals(
            Contract.REQUEST_IN_PROGRESS,
            rejection(UUID.randomUUID().toString()).errorCode
        )
        assertEquals(writes, disk.writes)
        assertEquals(pending.handle, store.activePending()?.handle)
    }

    @Test
    fun launchingPhaseCanBeClaimedOnlyOnce() {
        val pending = begin()
        val launching = requireNotNull(store.markLaunching(pending.handle))

        assertEquals(NativeCalendarStore.PHASE_LAUNCHING, launching.phase)
        assertEquals(pending.handle.token, launching.token)
        assertEquals(pending.handle.processId, launching.processId)
        assertNull(store.markLaunching(pending.handle))
        assertNull(store.markLaunching(launching))
        assertEquals(launching, store.activePending()?.handle)
    }

    @Test
    fun queuedRequestCannotBeReportedAsLaunched() {
        val pending = begin()
        val writes = disk.writes

        assertNull(store.completeLaunched(pending.handle))
        assertEquals(writes, disk.writes)
        assertEquals(Contract.STATUS_PENDING, store.getStatus(pending.result.id).status)
    }

    @Test
    fun launchedCompletionPersistsTerminalMetadataAndReleasesOwnership() {
        val pending = begin()
        val launching = requireNotNull(store.markLaunching(pending.handle))
        now += 20L

        val completed = requireNotNull(store.completeLaunched(launching))

        assertEquals(Contract.STATUS_LAUNCHED, completed.status)
        assertTrue(completed.accepted)
        assertTrue(completed.success)
        assertNull(completed.errorCode)
        assertEquals(BASE_TIME, completed.createdAtMs)
        assertEquals(now, completed.completedAtMs)
        assertNull(store.activePending())
        assertEquals(Contract.STATUS_LAUNCHED, newStore().getStatus(completed.id).status)
    }

    @Test
    fun controlledFailureCanCompleteQueuedOrLaunchingRequests() {
        for (launching in listOf(false, true)) {
            val pending = begin()
            val handle = if (launching) {
                requireNotNull(store.markLaunching(pending.handle))
            } else {
                pending.handle
            }

            val completed = requireNotNull(
                store.completeFailed(handle, Contract.NO_CALENDAR_APP)
            )

            assertEquals(Contract.STATUS_FAILED, completed.status)
            assertEquals(Contract.NO_CALENDAR_APP, completed.errorCode)
            assertTrue(completed.accepted)
            assertFalse(completed.success)
            assertNull(store.activePending())
        }
    }

    @Test
    fun interruptionReportsUnknownInsteadOfLaunchSuccess() {
        val pending = begin()
        val completed = requireNotNull(store.interrupt(pending.handle))

        assertEquals(Contract.STATUS_UNKNOWN, completed.status)
        assertEquals(Contract.INTERRUPTED, completed.errorCode)
        assertTrue(completed.accepted)
        assertFalse(completed.success)
        assertNull(store.activePending())
    }

    @Test
    fun staleTokenProcessOrPhaseCannotChangeTheOwnedRequest() {
        val pending = begin()
        val launching = requireNotNull(store.markLaunching(pending.handle))
        val writes = disk.writes

        val staleHandles = listOf(
            pending.handle,
            launching.copy(token = UUID.randomUUID().toString()),
            launching.copy(processId = UUID.randomUUID().toString())
        )

        for (handle in staleHandles) {
            assertNull(store.markLaunching(handle))
            assertNull(store.completeLaunched(handle))
            assertNull(store.completeFailed(handle, Contract.LAUNCH_FAILED))
            assertNull(store.interrupt(handle))
        }

        assertEquals(writes, disk.writes)
        assertEquals(launching, store.activePending()?.handle)
        assertNotNull(store.completeLaunched(launching))
    }

    @Test
    fun oldCallbackCannotCompleteANewerRequest() {
        val first = begin()
        assertNotNull(store.completeFailed(first.handle, Contract.LAUNCH_FAILED))
        val second = begin()

        assertNull(store.completeFailed(first.handle, Contract.LAUNCH_FAILED))
        assertNull(store.interrupt(first.handle))
        assertEquals(second.handle, store.activePending()?.handle)
        assertEquals(Contract.STATUS_PENDING, store.getStatus(second.result.id).status)
    }

    @Test
    fun previousProcessPendingRequestsRecoverAsUnknownInBothPhases() {
        for (launching in listOf(false, true)) {
            val isolated = MemoryFile()
            val first = newStore(isolated)
            val pending = begin(owner = first)

            if (launching) {
                assertNotNull(first.markLaunching(pending.handle))
            }

            val reopened = newStore(
                isolated,
                ownerProcess = UUID.randomUUID().toString()
            )
            val recovered = reopened.getStatus(pending.result.id)

            assertEquals(Contract.STATUS_UNKNOWN, recovered.status)
            assertEquals(Contract.INTERRUPTED, recovered.errorCode)
            assertFalse(recovered.success)
            assertNull(reopened.activePending())
            assertNull(reopened.completeLaunched(pending.handle))
        }
    }

    @Test
    fun sameProcessAndOwnedTokenProtectALiveRequest() {
        val pending = begin()
        val reopened = newStore()

        assertEquals(Contract.STATUS_PENDING, reopened.getStatus(pending.result.id).status)
        assertNull(reopened.interruptUnowned(pending.handle.token))
        assertEquals(pending.handle, reopened.activePending()?.handle)
    }

    @Test
    fun missingOrWrongInMemoryOwnershipRecoversPendingMetadata() {
        for (ownedToken in listOf<String?>(null, UUID.randomUUID().toString())) {
            val pending = begin()
            val recovered = requireNotNull(store.interruptUnowned(ownedToken))

            assertEquals(pending.result.id, recovered.id)
            assertEquals(Contract.STATUS_UNKNOWN, recovered.status)
            assertEquals(Contract.INTERRUPTED, recovered.errorCode)
            assertNull(store.activePending())
        }
    }

    @Test
    fun terminalMetadataExpiresAtTheExactRetentionBoundary() {
        val pending = begin()
        val completed = requireNotNull(
            store.completeFailed(pending.handle, Contract.LAUNCH_FAILED)
        )

        now = requireNotNull(completed.completedAtMs) + Contract.METADATA_TTL_MS - 1L
        assertEquals(Contract.STATUS_FAILED, store.getStatus(completed.id).status)

        now += 1L
        assertEquals(Contract.STATUS_NOT_FOUND, store.getStatus(completed.id).status)
        assertEquals(
            0,
            JSONObject(requireNotNull(disk.bytes).toString(Charsets.UTF_8))
                .getJSONArray("results").length()
        )
    }

    @Test
    fun expiredPendingRequestBecomesUnknownAndIsNotReplayed() {
        val pending = begin()
        now += Contract.METADATA_TTL_MS

        val recovered = store.getStatus(pending.result.id)
        assertEquals(Contract.STATUS_UNKNOWN, recovered.status)
        assertEquals(Contract.INTERRUPTED, recovered.errorCode)
        assertEquals(now, recovered.completedAtMs)
        assertNull(store.activePending())
        assertNull(store.markLaunching(pending.handle))
    }

    @Test
    fun clockRollbackConservativelyRecoversThenExpiresFutureMetadata() {
        val pending = begin()
        now -= 1L

        val recovered = store.getStatus(pending.result.id)
        assertEquals(Contract.STATUS_UNKNOWN, recovered.status)
        assertEquals(BASE_TIME, recovered.completedAtMs)
        assertNull(recovered.errorMessage?.takeIf { it.contains("fixture") })

        // The first transaction preserves the newly recovered outcome.
        // A later transaction prunes metadata with a future timestamp.
        assertEquals(Contract.STATUS_NOT_FOUND, store.getStatus(pending.result.id).status)
    }

    @Test
    fun fullCacheEvictsOldestTerminalMetadataAndPreservesLiveOwnership() {
        val ids = mutableListOf<String>()

        repeat(Contract.MAX_STORED_RESULTS) {
            val pending = begin()
            ids.add(pending.result.id)
            val launching = requireNotNull(store.markLaunching(pending.handle))
            now += 1L
            assertNotNull(store.completeLaunched(launching))
            now += 1L
        }

        val current = begin()

        assertEquals(Contract.STATUS_NOT_FOUND, store.getStatus(ids.first()).status)
        assertEquals(Contract.STATUS_LAUNCHED, store.getStatus(ids[1]).status)
        assertEquals(current.handle, store.activePending()?.handle)

        val snapshot = requireNotNull(disk.bytes).copyOf()
        assertEquals(
            Contract.REQUEST_IN_PROGRESS,
            rejection(UUID.randomUUID().toString()).errorCode
        )
        assertArrayEquals(snapshot, disk.bytes)
        assertEquals(
            Contract.MAX_STORED_RESULTS,
            JSONObject(snapshot.toString(Charsets.UTF_8)).getJSONArray("results").length()
        )
    }

    @Test
    fun malformedPrivateStateFailsWithoutResettingOrRewritingIt() {
        begin()
        val healthy = requireNotNull(disk.bytes).toString(Charsets.UTF_8)

        val invalid = listOf(
            "not JSON".toByteArray(Charsets.UTF_8),
            """{"version":1,"version":1,"results":[],"active":null}"""
                .toByteArray(Charsets.UTF_8),
            JSONObject(healthy).put("unexpected", "fixture")
                .toString().toByteArray(Charsets.UTF_8),
            JSONObject(healthy).put("version", 2)
                .toString().toByteArray(Charsets.UTF_8),
            JSONObject(healthy).apply {
                getJSONArray("results").getJSONObject(0).put("title", "private fixture")
            }.toString().toByteArray(Charsets.UTF_8),
            ("[".repeat(17) + "0" + "]".repeat(17)).toByteArray(Charsets.UTF_8),
            byteArrayOf(0xC3.toByte(), 0x28)
        )

        for (bytes in invalid) {
            val isolated = MemoryFile().apply { this.bytes = bytes.copyOf() }
            val reopened = newStore(isolated)

            storageFailure { reopened.getStatus(UUID.randomUUID().toString()) }
            assertArrayEquals(bytes, isolated.bytes)
            assertEquals(0, isolated.writes)
        }
    }

    @Test
    fun readFailureDoesNotExposeUnderlyingExceptionOrResetState() {
        begin()
        val snapshot = requireNotNull(disk.bytes).copyOf()
        val writes = disk.writes
        disk.failRead = true

        storageFailure { store.getStatus(UUID.randomUUID().toString()) }

        assertArrayEquals(snapshot, disk.bytes)
        assertEquals(writes, disk.writes)
    }

    @Test
    fun failedReservationWriteReturnsNoPendingAcknowledgment() {
        val id = UUID.randomUUID().toString()
        disk.failWrite = true

        storageFailure {
            store.begin(id, Contract.CREATE_EVENT, Contract.TARGET_EDITOR)
        }
        assertNull(disk.bytes)

        disk.failWrite = false
        assertEquals(Contract.STATUS_NOT_FOUND, store.getStatus(id).status)
    }

    @Test
    fun uncertainReservationCommitCanBeRecoveredWithoutReplay() {
        val id = UUID.randomUUID().toString()
        disk.failWrite = true
        disk.commitBeforeFailure = true

        storageFailure {
            store.begin(id, Contract.CREATE_EVENT, Contract.TARGET_EDITOR)
        }
        assertNotNull(disk.bytes)

        disk.failWrite = false
        disk.commitBeforeFailure = false

        assertEquals(Contract.STATUS_PENDING, store.getStatus(id).status)
        val recovered = requireNotNull(store.interruptUnowned(null))
        assertEquals(id, recovered.id)
        assertEquals(Contract.STATUS_UNKNOWN, recovered.status)
        assertNull(store.activePending())
    }

    @Test
    fun failedCompletionWritePreservesPendingStateForUnknownRecovery() {
        val pending = begin()
        val launching = requireNotNull(store.markLaunching(pending.handle))
        val snapshot = requireNotNull(disk.bytes).copyOf()
        disk.failWrite = true

        storageFailure { store.completeLaunched(launching) }
        assertArrayEquals(snapshot, disk.bytes)

        disk.failWrite = false
        assertEquals(Contract.STATUS_PENDING, store.getStatus(pending.result.id).status)

        val recovered = requireNotNull(store.interruptUnowned(null))
        assertEquals(Contract.STATUS_UNKNOWN, recovered.status)
        assertEquals(Contract.INTERRUPTED, recovered.errorCode)
        assertFalse(recovered.success)
    }

    @Test
    fun uncertainTerminalCommitIsReverifiedWithoutRepeatingTheOperation() {
        val pending = begin()
        val launching = requireNotNull(store.markLaunching(pending.handle))
        disk.failWrite = true
        disk.commitBeforeFailure = true

        storageFailure { store.completeLaunched(launching) }

        disk.failWrite = false
        disk.commitBeforeFailure = false

        val persisted = store.getStatus(pending.result.id)
        assertEquals(Contract.STATUS_LAUNCHED, persisted.status)
        assertTrue(persisted.success)
        assertNull(store.activePending())
        assertNull(store.interruptUnowned(null))
        assertNull(store.completeLaunched(launching))
        assertEquals(
            Contract.REQUEST_ALREADY_EXISTS,
            rejection(pending.result.id).errorCode
        )
    }

    @Test
    fun invalidWallClockCannotCreateOrReadRequestState() {
        for (invalidTime in listOf(-1L, Contract.MAX_EPOCH_MS + 1L)) {
            now = invalidTime

            storageFailure {
                store.begin(
                    UUID.randomUUID().toString(),
                    Contract.CREATE_EVENT,
                    Contract.TARGET_EDITOR
                )
            }
            storageFailure { store.getStatus(UUID.randomUUID().toString()) }
        }

        assertEquals(0, disk.reads)
        assertEquals(0, disk.writes)
        assertNull(disk.bytes)
    }

    private fun newStore(
        state: MemoryFile = disk,
        ownerProcess: String = processId
    ): NativeCalendarStore =
        NativeCalendarStore(
            stateFile = state,
            clock = { now },
            processId = ownerProcess
        )

    private fun begin(
        id: String = UUID.randomUUID().toString(),
        owner: NativeCalendarStore = store
    ): NativeCalendarPending =
        (
            owner.begin(id, Contract.CREATE_EVENT, Contract.TARGET_EDITOR)
                as NativeCalendarBeginResult.Started
        ).pending

    private fun rejection(id: String): NativeCalendarResult =
        (
            store.begin(id, Contract.CREATE_EVENT, Contract.TARGET_EDITOR)
                as NativeCalendarBeginResult.Rejected
        ).result

    private fun storageFailure(action: () -> Any?) {
        try {
            action()
        } catch (failure: NativeCalendarStorageException) {
            assertEquals(Contract.PERSIST_FAILED, failure.errorCode)
            assertEquals("Native Calendar storage is unavailable.", failure.message)
            assertNull(failure.cause)
            assertFalse(failure.toString().contains("private injected fixture"))
            return
        }

        throw AssertionError("Expected a controlled Calendar storage failure.")
    }

    private class MemoryFile : NativeCalendarStateFile {
        private val lock = Any()
        private val held = ThreadLocal<Boolean>()

        var bytes: ByteArray? = null
        var reads = 0
        var writes = 0
        var failRead = false
        var failWrite = false
        var commitBeforeFailure = false

        override fun <T> locked(action: () -> T): T =
            synchronized(lock) {
                check(held.get() != true)
                held.set(true)

                try {
                    action()
                } finally {
                    held.remove()
                }
            }

        override fun read(): ByteArray? {
            check(held.get() == true)
            reads += 1
            if (failRead) {
                throw IOException("private injected fixture")
            }
            return bytes?.copyOf()
        }

        override fun writeVerified(bytes: ByteArray) {
            check(held.get() == true)
            writes += 1

            if (failWrite) {
                if (commitBeforeFailure) {
                    this.bytes = bytes.copyOf()
                }
                throw IOException("private injected fixture")
            }

            this.bytes = bytes.copyOf()
        }
    }

    companion object {
        private const val BASE_TIME = 1_700_000_000_000L

        private val METADATA_KEYS = setOf(
            "id", "operation", "target", "status", "accepted", "success",
            "errorCode", "errorMessage", "createdAtMs", "completedAtMs"
        )
    }
}
