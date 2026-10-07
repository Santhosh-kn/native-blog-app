package com.bbs.plugins.native_media_optimizer

import androidx.test.ext.junit.runners.AndroidJUnit4
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.UUID

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerStoreInstrumentedTest {
    private val id = "3f1d6c28-78e0-4f1b-a48e-f7350dcb601a"
    private val source = "a72645ed-85bb-4f51-9d9e-8c71815646af"
    private val processA = "07808c42-20ca-4e0a-a6d3-cce2114f5076"
    private val processB = "fe0f5b4d-6805-4fc6-836a-d9a0d35855c3"
    private val root = "/private/storage/app/native-media-optimizer"
    private fun request(job: String = id, operation: String = "OptimizeImage") =
        (NativeMediaOptimizerRequest.parse(operation, mapOf("id" to job, "source_document_id" to source)) as NativeMediaOptimizerRequestParseResult.Valid).request
    private fun begin(store: NativeMediaOptimizerStore, job: String = id, operation: String = "OptimizeImage"): NativeMediaOptimizerRecord {
        val result = store.begin(request(job, operation), if (operation == "InspectMedia") null else root)
        assertTrue(result is NativeMediaOptimizerBeginResult.Started)
        return (result as NativeMediaOptimizerBeginResult.Started).record
    }
    private fun metadata() = NativeMediaOptimizerMetadata("image/jpeg", 100, 10, 10, null, 0, false)
    private fun completed(record: NativeMediaOptimizerRecord) = record.result.copy(
        status = "succeeded", progress = 100, phase = "completed", input = metadata(),
        output = if (record.result.operation == "InspectMedia") null else NativeMediaOptimizerOutput(record.handle.id, "$root/${record.handle.id}.jpg", metadata()),
        outputAvailable = record.result.operation != "InspectMedia"
    )

    @Test fun acceptanceIsPersistedBeforeReturning() {
        val file = MemoryFile()
        val store = NativeMediaOptimizerStore(file, processA) { 1L }
        val record = begin(store)
        assertTrue(file.writes > 0)
        assertEquals("pending", NativeMediaOptimizerStore(file, processA).getStatus(id).status)
        assertEquals(source, record.result.sourceDocumentId)
    }

    @Test fun concurrentAcceptanceAndDuplicateIdsAreRejected() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        begin(store)
        assertEquals(NativeMediaOptimizerContract.INVALID_OPTIONS, (store.begin(request(), root) as NativeMediaOptimizerBeginResult.Rejected).result.errorCode)
        assertEquals(NativeMediaOptimizerContract.BUSY, (store.begin(request(UUID.randomUUID().toString()), root) as NativeMediaOptimizerBeginResult.Rejected).result.errorCode)
    }

    @Test fun terminalStatesCannotBeRewrittenByLateCallbacks() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        val record = begin(store)
        assertTrue(store.update(record.handle, completed(record)))
        assertFalse(store.update(record.handle, record.result.copy(status = "running", phase = "encoding", progress = 50)))
        assertEquals("succeeded", store.getStatus(id).status)
    }

    @Test fun staleTokensAndChangedBindingsAreRejected() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        val record = begin(store)
        val running = record.result.copy(status = "running", phase = "encoding", progress = 20)
        assertFalse(store.update(record.handle.copy(token = UUID.randomUUID().toString()), running))
        assertFalse(store.update(record.handle, running.copy(sourceDocumentId = id)))
        assertEquals("pending", store.getStatus(id).status)
    }

    @Test fun cancellationWinsOverLateSuccess() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        val record = begin(store)
        assertEquals("cancelling", store.cancel(id).status)
        assertFalse(store.update(record.handle, completed(record)))
        assertTrue(store.update(record.handle, record.result.copy(status = "cancelled", phase = "cancelled")))
        assertEquals("cancelled", store.cancel(id).status)
    }

    @Test fun progressCannotMoveBackwards() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        val record = begin(store)
        assertTrue(store.update(record.handle, record.result.copy(status = "running", phase = "encoding", progress = 50)))
        assertFalse(store.update(record.handle, record.result.copy(status = "running", phase = "encoding", progress = 40)))
        assertEquals(50, store.getStatus(id).progress)
    }

    @Test fun previousProcessWorkBecomesInterrupted() {
        val file = MemoryFile()
        val record = begin(NativeMediaOptimizerStore(file, processA))
        val restarted = NativeMediaOptimizerStore(file, processB)
        val result = restarted.getStatus(id)
        assertEquals("interrupted", result.status)
        assertEquals(NativeMediaOptimizerContract.PROCESS_INTERRUPTED, result.errorCode)
        assertFalse(restarted.update(record.handle, completed(record)))
        assertEquals(1, restarted.interruptedRecords().size)
    }

    @Test fun completedOutputsSurviveProcessChangesAndExplicitDeletionRetainsMetadata() {
        val file = MemoryFile()
        val store = NativeMediaOptimizerStore(file, processA)
        val record = begin(store)
        assertTrue(store.update(record.handle, completed(record)))
        val restarted = NativeMediaOptimizerStore(file, processB)
        assertEquals("succeeded", restarted.getStatus(id).status)
        assertTrue(restarted.getStatus(id).outputAvailable)
        assertTrue(restarted.markOutputUnavailable(id))
        assertFalse(restarted.getStatus(id).outputAvailable)
        assertNotNull(restarted.getStatus(id).output)
    }

    @Test fun liveOutputsAreNotEvictedToMakeSpace() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        repeat(NativeMediaOptimizerContract.MAX_RECORDS) {
            val record = begin(store, UUID.randomUUID().toString())
            assertTrue(store.update(record.handle, completed(record)))
        }
        val denied = store.begin(request(UUID.randomUUID().toString()), root) as NativeMediaOptimizerBeginResult.Rejected
        assertEquals(NativeMediaOptimizerContract.LIMIT_EXCEEDED, denied.result.errorCode)
    }

    @Test fun metadataWithoutOutputsCanBeEvictedAtCapacity() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        repeat(NativeMediaOptimizerContract.MAX_RECORDS + 1) {
            val record = begin(store, UUID.randomUUID().toString(), "InspectMedia")
            assertTrue(store.update(record.handle, completed(record)))
        }
    }

    @Test fun writeFailureDoesNotReturnAcceptance() {
        val file = MemoryFile().apply { failWrites = true }
        val store = NativeMediaOptimizerStore(file, processA)
        try { store.begin(request(), root); fail("Acceptance must fail with persistence.") } catch (_: NativeMediaOptimizerStorageException) { }
        file.failWrites = false
        assertEquals("not_found", store.getStatus(id).status)
    }

    @Test fun malformedStateFailsClosed() {
        for (bytes in listOf("{}".toByteArray(), byteArrayOf(0xff.toByte()), "{\"version\":1,\"records\":[]} trailing".toByteArray(), ("[".repeat(100) + "0" + "]".repeat(100)).toByteArray())) {
            val file = MemoryFile().apply { stored = bytes }
            try { NativeMediaOptimizerStore(file, processA).getStatus(id); fail("Malformed state must not be reset.") } catch (_: NativeMediaOptimizerStorageException) { }
        }
    }

    @Test fun completionEventsContainNoInputOrOutputPaths() {
        val store = NativeMediaOptimizerStore(MemoryFile(), processA)
        val result = completed(begin(store))
        val event = result.toEventJson()
        assertEquals(setOf("id", "operation", "status", "errorCode", "errorMessage"), event.keys().asSequence().toSet())
        assertFalse(event.toString().contains(root))
        assertNotNull(NativeMediaOptimizerResult.fromJson(JSONObject(result.toBridgeMap())))
    }

    private class MemoryFile : NativeMediaOptimizerStateFile {
        var stored: ByteArray? = null
        var writes = 0
        var failWrites = false
        override fun <T> locked(action: () -> T): T = synchronized(this) { action() }
        override fun read() = stored?.clone()
        override fun write(bytes: ByteArray) {
            if (failWrites) throw NativeMediaOptimizerStorageException()
            stored = bytes.clone(); writes++
        }
    }
}
