package com.bbs.plugins.native_contacts

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.IOException
import java.util.UUID
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

@RunWith(AndroidJUnit4::class)
class NativeContactsStoreInstrumentedTest {

    private var now = 1_700_000_000_000L
    private val processA = UUID.randomUUID().toString()
    private val processB = UUID.randomUUID().toString()

    @Test
    fun pendingIdentityIsPersistedBeforeBeginReturns() {
        val disk = FaultStateFile()
        val store = store(disk)
        val pending = begin(store)

        val json = JSONObject(disk.text())
        assertEquals(pending.handle.id, json.getJSONObject("active").getString("id"))
        assertEquals(pending.handle.token, json.getJSONObject("active").getString("token"))
        assertEquals(processA, json.getJSONObject("active").getString("processId"))
        assertEquals(NativeContactsStore.PHASE_QUEUED, pending.handle.phase)
        assertEquals(Contract.STATUS_PENDING, store.getStatus(pending.handle.id).status)
        assertNull(pending.result.selection)
    }

    @Test
    fun creationInputsAndOpenUriAreNeverPersisted() {
        for (operation in listOf(Contract.CREATE, Contract.OPEN)) {
            val disk = FaultStateFile()
            val store = store(disk)
            val options = if (operation == Contract.CREATE) {
                mapOf<String, Any>(
                    "name" to NAME,
                    "phone" to PHONE,
                    "email" to EMAIL
                )
            } else {
                mapOf<String, Any>("uri" to URI)
            }

            val request = request(operation = operation, inputs = options)
            begin(store, request)
            assertPrivateAbsent(disk.text())
            assertPrivateAbsent(request.toString())
        }
    }

    @Test
    fun failedBeginDoesNotReturnAcceptedPendingState() {
        val disk = FaultStateFile()
        disk.failBeforeWrite = true
        val store = store(disk)

        storageFailure { begin(store) }
        assertNull(disk.bytes())
        assertNull(store.activePending())
    }

    @Test
    fun readFailuresHaveControlledMessagesAndNoUnderlyingCause() {
        val disk = FaultStateFile()
        disk.failRead = true

        storageFailure { store(disk).getStatus(UUID.randomUUID().toString()) }
    }

    @Test
    fun activeRequestRejectsBusyAndReusedIds() {
        val disk = FaultStateFile()
        val store = store(disk)
        val pending = begin(store)

        val reused = store.begin(request(id = pending.handle.id))
            as NativeContactsBeginResult.Rejected
        val busy = store.begin(request()) as NativeContactsBeginResult.Rejected

        assertEquals(Contract.REQUEST_ID_REUSED, reused.result.errorCode)
        assertEquals(Contract.OPERATION_BUSY, busy.result.errorCode)
        assertEquals(pending.handle.id, store.activePending()!!.handle.id)
    }

    @Test
    fun pickerTransitionsRejectOutOfOrderAndDuplicateCallbacks() {
        val disk = FaultStateFile()
        val store = store(disk)
        val pending = begin(store)

        assertNull(store.markReadingPicker(pending.handle))
        assertNull(store.markLaunching(pending.handle))
        assertNull(store.complete(
            pending.handle,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))

        val awaiting = requireNotNull(store.markAwaitingPicker(pending.handle))
        val reading = requireNotNull(store.markReadingPicker(awaiting))

        assertEquals(NativeContactsStore.PHASE_READING_PICKER, reading.phase)
        assertNull(store.markReadingPicker(reading))
        assertNull(store.restorePicker(reading.id, reading.token))

        assertNotNull(store.complete(
            reading,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))
        assertNull(store.complete(
            reading,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))
    }

    @Test
    fun statusCompletionAndEventPayloadsContainMetadataOnly() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = awaiting(store)
        val completed = requireNotNull(store.complete(
            handle,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))

        assertNull(completed.selection)
        assertNull(store.getStatus(handle.id).selection)
        assertFalse(completed.toBridgeMap().containsKey("selection"))
        assertFalse(completed.toEventJson().has("selection"))
        assertPrivateAbsent(completed.toEventJson().toString())
        assertPrivateAbsent(completed.toString())

        // Even a result object that still holds a selection serializes metadata.
        val internalResult = NativeContactsResult.pending(request(), now).complete(
            Contract.STATUS_SELECTED,
            selected = selection(),
            now = now
        )
        assertNotNull(internalResult.selection)
        assertFalse(internalResult.toBridgeMap().containsKey("selection"))
        assertFalse(internalResult.toEventJson().has("selection"))
        assertPrivateAbsent(internalResult.toEventJson().toString())
    }

    @Test
    fun consumptionRedactsCommittedStateBeforeDeliveringSelection() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = selected(store)

        val delivered = store.consume(handle.id)
            as NativeContactsConsumeResult.Delivered

        assertEquals(PHONE, delivered.selection.phoneNumber)
        assertTrue(delivered.result.consumed)
        assertNull(delivered.result.selection)
        assertTrue(record(disk).isNull("selection"))
        assertTrue(record(disk).getBoolean("consumed"))
        assertPrivateAbsent(disk.text())
        assertPrivateAbsent(delivered.toString())

        val metadata = delivered.result.toBridgeMap()
        assertFalse(metadata.containsKey("selection"))

        val explicit = delivered.result.toBridgeMap(
            selectionToDeliver = delivered.selection
        )
        assertTrue(explicit.containsKey("selection"))

        val repeat = store.consume(handle.id)
            as NativeContactsConsumeResult.Metadata
        assertEquals(Contract.RESULT_ALREADY_CONSUMED, repeat.errorOverride)
        assertTrue(repeat.result.consumed)
        assertNull(repeat.result.selection)
        assertEquals(Contract.STATUS_SELECTED, store.getStatus(handle.id).status)
    }

    @Test
    fun concurrentConsumptionDeliversExactlyOneSelection() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = selected(store)
        val executor = Executors.newFixedThreadPool(2)
        val ready = CountDownLatch(2)
        val gate = CountDownLatch(1)

        try {
            val futures = (1..2).map {
                executor.submit<NativeContactsConsumeResult> {
                    ready.countDown()
                    check(gate.await(5, TimeUnit.SECONDS))
                    store.consume(handle.id)
                }
            }

            assertTrue(ready.await(5, TimeUnit.SECONDS))
            gate.countDown()
            val results = futures.map { it.get(5, TimeUnit.SECONDS) }

            assertEquals(1, results.count {
                it is NativeContactsConsumeResult.Delivered
            })
            assertEquals(1, results.count {
                it is NativeContactsConsumeResult.Metadata &&
                    it.errorOverride == Contract.RESULT_ALREADY_CONSUMED
            })
            assertPrivateAbsent(disk.text())
        } finally {
            gate.countDown()
            executor.shutdownNow()
        }
    }

    @Test
    fun failedRedactionBeforeCommitDeliversNothingAndAllowsSafeRetry() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = selected(store)
        disk.failBeforeWrite = true

        storageFailure { store.consume(handle.id) }

        assertFalse(store.getStatus(handle.id).consumed)
        assertTrue(disk.text().contains(NAME))

        val retry = store.consume(handle.id)
            as NativeContactsConsumeResult.Delivered
        assertEquals(PHONE, retry.selection.phoneNumber)
        assertPrivateAbsent(disk.text())
    }

    @Test
    fun uncertainFailureAfterRedactionCommitNeverReplaysTheSelection() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = selected(store)
        disk.failAfterWrite = true

        storageFailure { store.consume(handle.id) }
        assertPrivateAbsent(disk.text())

        val repeat = store.consume(handle.id)
            as NativeContactsConsumeResult.Metadata

        assertEquals(Contract.RESULT_ALREADY_CONSUMED, repeat.errorOverride)
        assertTrue(repeat.result.consumed)
        assertNull(repeat.result.selection)
    }

    @Test
    fun failedSelectionCompletionReturnsNoUnverifiedResult() {
        val disk = FaultStateFile()
        val store = store(disk)
        val reading = requireNotNull(store.markReadingPicker(awaiting(store)))
        disk.failBeforeWrite = true

        storageFailure {
            store.complete(
                reading,
                Contract.STATUS_SELECTED,
                selection = selection()
            )
        }

        assertEquals(Contract.STATUS_PENDING, store.getStatus(reading.id).status)
        assertPrivateAbsent(disk.text())

        val failed = requireNotNull(store.complete(
            reading,
            Contract.STATUS_FAILED,
            Contract.RESULT_PERSISTENCE_FAILED
        ))
        assertEquals(Contract.RESULT_PERSISTENCE_FAILED, failed.errorCode)
        assertNull(failed.selection)
        assertPrivateAbsent(failed.toEventJson().toString())
    }

    @Test
    fun restoredAwaitingPickerAdoptsNewProcessAndRejectsOldOwner() {
        val disk = FaultStateFile()
        val oldStore = store(disk)
        val oldHandle = awaiting(oldStore)
        val newStore = store(disk, processB)

        val restored = requireNotNull(newStore.restorePicker(
            oldHandle.id,
            oldHandle.token
        ))

        assertEquals(processB, restored.handle.processId)
        assertEquals(
            processB,
            JSONObject(disk.text()).getJSONObject("active").getString("processId")
        )
        assertNull(newStore.interruptPreviousProcess())
        assertNull(oldStore.complete(
            oldHandle,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))

        assertNotNull(newStore.complete(
            restored.handle,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))
    }

    @Test
    fun wrongRestorationIdentityDoesNotAdoptTheActivePicker() {
        val disk = FaultStateFile()
        val handle = awaiting(store(disk))
        val restoredStore = store(disk, processB)

        assertNull(restoredStore.restorePicker(
            handle.id,
            UUID.randomUUID().toString()
        ))
        assertNull(restoredStore.restorePicker(
            UUID.randomUUID().toString(),
            handle.token
        ))

        val interrupted = requireNotNull(restoredStore.interruptPreviousProcess())
        assertEquals(Contract.STATUS_UNKNOWN, interrupted.status)
        assertEquals(Contract.OPERATION_INTERRUPTED, interrupted.errorCode)
        assertNull(interrupted.selection)
        assertNull(restoredStore.activePending())
    }

    @Test
    fun previousProcessReadingPhaseCannotBeRestoredOrReplayed() {
        val disk = FaultStateFile()
        val oldStore = store(disk)
        val reading = requireNotNull(oldStore.markReadingPicker(awaiting(oldStore)))
        val newStore = store(disk, processB)

        assertNull(newStore.restorePicker(reading.id, reading.token))
        val interrupted = requireNotNull(newStore.interruptPreviousProcess())

        assertEquals(Contract.STATUS_UNKNOWN, interrupted.status)
        assertEquals(Contract.OPERATION_INTERRUPTED, interrupted.errorCode)
        assertNull(newStore.activePending())
        assertNull(oldStore.complete(
            reading,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))
        assertPrivateAbsent(disk.text())
    }

    @Test
    fun queuedAndLaunchingOperationsAreInterruptedWithoutReplay() {
        for (operation in listOf(Contract.PICK, Contract.CREATE, Contract.OPEN)) {
            val disk = FaultStateFile()
            val oldStore = store(disk)
            val pending = begin(oldStore, request(operation = operation))

            if (operation != Contract.PICK) {
                assertNotNull(oldStore.markLaunching(pending.handle))
            }

            val newStore = store(disk, processB)
            assertNull(newStore.restorePicker(pending.handle.id, pending.handle.token))

            val result = requireNotNull(newStore.interruptPreviousProcess())
            assertEquals(Contract.STATUS_UNKNOWN, result.status)
            assertEquals(Contract.OPERATION_INTERRUPTED, result.errorCode)
            assertNull(newStore.activePending())
            assertPrivateAbsent(disk.text())
        }
    }

    @Test
    fun completedSelectionSurvivesProcessChangeAndIsConsumedOnce() {
        val disk = FaultStateFile()
        val handle = selected(store(disk))
        val newStore = store(disk, processB)

        assertNull(newStore.interruptPreviousProcess())
        assertEquals(Contract.STATUS_SELECTED, newStore.getStatus(handle.id).status)
        assertTrue(newStore.consume(handle.id) is NativeContactsConsumeResult.Delivered)
        assertTrue(newStore.consume(handle.id) is NativeContactsConsumeResult.Metadata)
        assertPrivateAbsent(disk.text())
    }

    @Test
    fun oldCallbackCannotCompleteANewerRequestWithTheSameExpiredId() {
        val disk = FaultStateFile()
        val store = store(disk)
        val old = awaiting(store)
        assertNotNull(store.complete(old, Contract.STATUS_CANCELLED))

        now += Contract.METADATA_TTL_MS
        val newer = awaiting(store, request(id = old.id))

        assertNotEquals(old.token, newer.token)
        assertNull(store.restorePicker(old.id, old.token))
        assertNull(store.complete(
            old,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))
        assertEquals(Contract.STATUS_PENDING, store.getStatus(newer.id).status)
    }

    @Test
    fun lateReadOutcomeCannotReplaceATerminalFailureOrANewerRequest() {
        val disk = FaultStateFile()
        val store = store(disk)
        val old = requireNotNull(store.markReadingPicker(awaiting(store)))

        assertNotNull(store.complete(
            old,
            Contract.STATUS_FAILED,
            Contract.CONTACT_READ_FAILED
        ))

        val newer = awaiting(store)
        val before = disk.text()

        assertNull(store.complete(
            old,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))
        assertEquals(before, disk.text())
        assertEquals(Contract.STATUS_PENDING, store.getStatus(newer.id).status)
        assertPrivateAbsent(disk.text())
    }

    @Test
    fun selectionExpiryRedactsWithoutConsumption() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = selected(store)

        now += Contract.SELECTION_TTL_MS
        store.purgeExpired()

        val status = store.getStatus(handle.id)
        assertEquals(Contract.STATUS_FAILED, status.status)
        assertEquals(Contract.RESULT_EXPIRED, status.errorCode)
        assertNull(status.selection)
        assertPrivateAbsent(disk.text())
        assertTrue(store.consume(handle.id) is NativeContactsConsumeResult.Metadata)
    }

    @Test
    fun metadataExpiryRemovesOldSelections() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = selected(store)

        now += Contract.METADATA_TTL_MS

        assertEquals(Contract.STATUS_NOT_FOUND, store.getStatus(handle.id).status)
        assertPrivateAbsent(disk.text())
        assertEquals(0, JSONObject(disk.text()).getJSONArray("results").length())
    }

    @Test
    fun clockRollbackFailsClosedAndRemovesPrivateSelection() {
        val disk = FaultStateFile()
        val store = store(disk)
        val handle = selected(store)

        now--

        assertEquals(Contract.STATUS_NOT_FOUND, store.getStatus(handle.id).status)
        assertPrivateAbsent(disk.text())
    }

    @Test
    fun pendingMetadataExpiryInterruptsAndReleasesTheActiveSlot() {
        val disk = FaultStateFile()
        val store = store(disk)
        val pending = begin(store)

        now += Contract.METADATA_TTL_MS

        val status = store.getStatus(pending.handle.id)
        assertEquals(Contract.STATUS_UNKNOWN, status.status)
        assertEquals(Contract.OPERATION_INTERRUPTED, status.errorCode)
        assertNull(store.activePending())
        assertTrue(store.begin(request()) is NativeContactsBeginResult.Started)
    }

    @Test
    fun cacheCapacityEvictsOnlyOldTerminalRequests() {
        val disk = FaultStateFile()
        val store = store(disk)
        var oldestId = ""

        repeat(Contract.MAX_STORED_RESULTS) { index ->
            val handle = awaiting(store)
            if (index == 0) oldestId = handle.id
            assertNotNull(store.complete(handle, Contract.STATUS_CANCELLED))
            now++
        }

        val active = begin(store)

        assertEquals(Contract.STATUS_NOT_FOUND, store.getStatus(oldestId).status)
        assertEquals(active.handle.id, store.activePending()!!.handle.id)
        assertEquals(
            Contract.MAX_STORED_RESULTS,
            JSONObject(disk.text()).getJSONArray("results").length()
        )
    }

    @Test
    fun corruptStateIsRejectedWithoutResettingOrOverwritingIt() {
        val validEmpty = """{"version":1,"results":[],"active":null}"""
        val cases = listOf(
            byteArrayOf(),
            byteArrayOf(0xc3.toByte(), 0x28),
            "{}".toByteArray(),
            "$validEmpty trailing".toByteArray(),
            """{"version":2,"results":[],"active":null}""".toByteArray(),
            """{"version":1,"results":[],"active":null,"extra":true}""".toByteArray(),
            ("[".repeat(17) + "]".repeat(17)).toByteArray()
        )

        for (bytes in cases) {
            val disk = FaultStateFile()
            disk.replace(bytes)
            storageFailure { store(disk).getStatus(UUID.randomUUID().toString()) }
            assertArrayEquals(bytes, disk.bytes())
            assertEquals(0, disk.writes)
        }
    }

    private fun store(
        disk: NativeContactsStateFile,
        process: String = processA
    ) = NativeContactsStore(disk, clock = { now }, processId = process)

    private fun request(
        operation: String = Contract.PICK,
        id: String = UUID.randomUUID().toString(),
        inputs: Map<String, Any> = emptyMap()
    ): NativeContactsRequest {
        val parameters = mutableMapOf<String, Any>("id" to id)
        if (operation == Contract.PICK) parameters["mode"] = Contract.MODE_PHONE
        if (operation == Contract.OPEN) parameters["uri"] = URI
        parameters.putAll(inputs)

        return (NativeContactsRequest.fromParameters(operation, parameters)
            as NativeContactsRequestValidation.Valid).request
    }

    private fun begin(
        store: NativeContactsStore,
        request: NativeContactsRequest = request()
    ): NativeContactsPending =
        (store.begin(request) as NativeContactsBeginResult.Started).pending

    private fun awaiting(
        store: NativeContactsStore,
        request: NativeContactsRequest = request()
    ): NativeContactsPendingHandle =
        requireNotNull(store.markAwaitingPicker(begin(store, request).handle))

    private fun selected(store: NativeContactsStore): NativeContactsPendingHandle {
        val handle = awaiting(store)
        val reading = requireNotNull(store.markReadingPicker(handle))
        assertNotNull(store.complete(
            reading,
            Contract.STATUS_SELECTED,
            selection = selection()
        ))
        return reading
    }

    private fun selection() = NativeContactsSelection(
        displayName = NAME,
        phoneNumber = PHONE
    )

    private fun record(disk: FaultStateFile): JSONObject =
        JSONObject(disk.text()).getJSONArray("results").getJSONObject(0)

    private fun storageFailure(action: () -> Unit) {
        try {
            action()
        } catch (failure: NativeContactsStorageException) {
            assertEquals(Contract.RESULT_PERSISTENCE_FAILED, failure.errorCode)
            assertEquals("Native contacts storage is unavailable.", failure.message)
            assertNull(failure.cause)
            return
        }
        throw AssertionError("Expected a controlled storage failure.")
    }

    private fun assertPrivateAbsent(text: String) {
        val normalized = text.replace("\\/", "/")
        for (value in listOf(NAME, PHONE, EMAIL, URI)) {
            assertFalse("Private fixture value escaped.", normalized.contains(value))
        }
    }

    private class FaultStateFile : NativeContactsStateFile {
        private val monitor = Any()
        private var contents: ByteArray? = null

        var failRead = false
        var failBeforeWrite = false
        var failAfterWrite = false
        var writes = 0
            private set

        override fun <T> locked(action: () -> T): T =
            synchronized(monitor) { action() }

        override fun read(): ByteArray? {
            if (failRead) throw IOException(NAME)
            return contents?.copyOf()
        }

        override fun writeVerified(bytes: ByteArray) {
            if (failBeforeWrite) {
                failBeforeWrite = false
                throw IOException(NAME)
            }

            contents = bytes.copyOf()
            writes++

            if (failAfterWrite) {
                failAfterWrite = false
                throw IOException(NAME)
            }
        }

        fun replace(bytes: ByteArray) {
            synchronized(monitor) { contents = bytes.copyOf() }
        }

        fun bytes(): ByteArray? =
            synchronized(monitor) { contents?.copyOf() }

        fun text(): String =
            synchronized(monitor) { contents?.toString(Charsets.UTF_8) ?: "" }
    }

    companion object {
        private const val NAME = "Private Native Contacts Fixture"
        private const val PHONE = "+1 202-555-0199"
        private const val EMAIL = "private.native.contacts@example.invalid"
        private const val URI = "content://com.android.contacts/contacts/12345"
    }
}