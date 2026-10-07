package com.bbs.plugins.native_media_optimizer

import android.graphics.Bitmap
import android.graphics.Color
import android.system.Os
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicReference
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerCoordinatorInstrumentedTest {
    @Test fun pendingAcceptanceIsDurableBeforeTheProcessorStarts() {
        val entered = CountDownLatch(1); val release = CountDownLatch(1)
        val observed = AtomicReference<NativeMediaOptimizerResult>()
        lateinit var f: JobsFixture
        f = JobsFixture(NativeMediaOptimizerProcessor { _, source, _, cancellation, _ ->
            observed.set(f.store.getStatus(f.request.id)); entered.countDown()
            check(release.await(5, TimeUnit.SECONDS)); cancellation.check()
            NativeMediaOptimizerEngineResult(f.media.engine.inspect(source, cancellation))
        })
        try {
            val accepted = f.coordinator.start(f.request)
            assertTrue(accepted.accepted); assertEquals("pending", accepted.status)
            assertTrue(entered.await(5, TimeUnit.SECONDS))
            assertEquals("pending", observed.get().status)
            assertTrue(f.memory.writes.get() >= 1)
            release.countDown(); assertEquals("succeeded", f.terminal().status)
        } finally { release.countDown(); f.close() }
    }

    @Test fun anotherJobIsBusyWhileStatusQueriesRemainResponsive() {
        val entered = CountDownLatch(1); val release = CountDownLatch(1)
        JobsFixture(NativeMediaOptimizerProcessor { _, source, _, cancellation, _ ->
            entered.countDown(); check(release.await(5, TimeUnit.SECONDS)); cancellation.check()
            NativeMediaOptimizerEngineResult(NativeMediaOptimizerMetadata("image/png", source.size, 16, 8, null, 0, false))
        }).use { f ->
            try {
                assertTrue(f.coordinator.start(f.request).accepted)
                assertTrue(entered.await(5, TimeUnit.SECONDS))
                val before = System.nanoTime()
                assertTrue(f.coordinator.getStatus(f.request.id).accepted)
                assertTrue(TimeUnit.NANOSECONDS.toMillis(System.nanoTime() - before) < 2000)
                val another = f.media.request(f.source, operation = "InspectMedia")
                assertEquals(Contract.BUSY, f.coordinator.start(another).errorCode)
                release.countDown(); assertEquals("succeeded", f.terminal().status)
                assertEquals(Contract.INVALID_OPTIONS, f.coordinator.start(f.request).errorCode)
            } finally { release.countDown() }
        }
    }

    @Test fun mainThreadCallsRejectWithoutTouchingStateOrStartingMedia() {
        JobsFixture().use { f ->
            val before = f.memory.writes.get()
            val result = AtomicReference<NativeMediaOptimizerResult>()
            InstrumentationRegistry.getInstrumentation().runOnMainSync { result.set(f.coordinator.start(f.request)) }
            assertFalse(result.get().accepted); assertEquals(Contract.NATIVE_UNAVAILABLE, result.get().errorCode)
            assertEquals(before, f.memory.writes.get())
        }
    }

    @Test fun failedPendingCommitNeverStartsTheProcessor() {
        val calls = AtomicInteger()
        JobsFixture(NativeMediaOptimizerProcessor { _, source, _, _, _ ->
            calls.incrementAndGet(); NativeMediaOptimizerEngineResult(NativeMediaOptimizerMetadata("image/png", source.size, 16, 8, null, 0, false))
        }).use { f ->
            f.memory.failWrites = true
            assertEquals(Contract.PERSIST_FAILED, f.coordinator.start(f.request).errorCode)
            assertEquals(0, calls.get())
            f.memory.failWrites = false
            assertEquals("not_found", f.coordinator.getStatus(f.request.id).status)
        }
    }

    @Test fun progressNeverDecreasesAndCompletionIsEmittedAfterItsCommit() {
        val entered = CountDownLatch(1); val next = CountDownLatch(1); val finish = CountDownLatch(1)
        JobsFixture(NativeMediaOptimizerProcessor { _, source, _, cancellation, progress ->
            progress("inspecting", 30); entered.countDown()
            check(next.await(5, TimeUnit.SECONDS))
            progress("encoding", 10)
            check(finish.await(5, TimeUnit.SECONDS)); cancellation.check()
            NativeMediaOptimizerEngineResult(NativeMediaOptimizerMetadata("image/png", source.size, 16, 8, null, 0, false))
        }).use { f ->
            try {
                f.coordinator.start(f.request); assertTrue(entered.await(5, TimeUnit.SECONDS))
                f.await { f.coordinator.getStatus(f.request.id).progress == 30 }
                next.countDown()
                f.await { f.coordinator.getStatus(f.request.id).phase == "encoding" }
                assertEquals(30, f.coordinator.getStatus(f.request.id).progress)
                finish.countDown(); assertEquals("succeeded", f.terminal().status)
                f.await { f.events.size == 1 }
                assertEquals("succeeded", f.events.single().second.status)
                val json = f.events.single().first.toEventJson()
                assertEquals(setOf("id", "operation", "status", "errorCode", "errorMessage"), json.keys().asSequence().toSet())
                assertFalse(json.toString().contains(f.source.file.path))
            } finally { next.countDown(); finish.countDown() }
        }
    }

    @Test fun cancellationIsDurableAndRepeatedCancellationIsIdempotent() {
        val entered = CountDownLatch(1); val release = CountDownLatch(1)
        JobsFixture(NativeMediaOptimizerProcessor { _, source, _, cancellation, _ ->
            entered.countDown(); check(release.await(5, TimeUnit.SECONDS)); cancellation.check()
            NativeMediaOptimizerEngineResult(NativeMediaOptimizerMetadata("image/png", source.size, 16, 8, null, 0, false))
        }).use { f ->
            try {
                f.coordinator.start(f.request); assertTrue(entered.await(5, TimeUnit.SECONDS))
                assertEquals("cancelling", f.coordinator.cancel(f.request.id).status)
                assertEquals("cancelling", f.store.getStatus(f.request.id).status)
                release.countDown()
                assertEquals("cancelled", f.terminal().status)
                assertEquals("cancelled", f.coordinator.cancel(f.request.id).status)
                assertTrue(f.source.file.isFile)
            } finally { release.countDown() }
        }
    }

    @Test fun failedCancellationCommitDoesNotSignalTheProcessor() {
        val entered = CountDownLatch(1); val release = CountDownLatch(1)
        JobsFixture(NativeMediaOptimizerProcessor { _, source, _, cancellation, _ ->
            entered.countDown(); check(release.await(5, TimeUnit.SECONDS)); cancellation.check()
            NativeMediaOptimizerEngineResult(NativeMediaOptimizerMetadata("image/png", source.size, 16, 8, null, 0, false))
        }).use { f ->
            try {
                f.coordinator.start(f.request); assertTrue(entered.await(5, TimeUnit.SECONDS))
                f.memory.failWrites = true
                assertEquals(Contract.PERSIST_FAILED, f.coordinator.cancel(f.request.id).errorCode)
                f.memory.failWrites = false; release.countDown()
                assertEquals("succeeded", f.terminal().status)
            } finally { release.countDown(); f.memory.failWrites = false }
        }
    }

    @Test fun lateEncoderSuccessCannotWinOverCancellationAndItsOutputIsRemoved() {
        val published = CountDownLatch(1); val release = CountDownLatch(1)
        val output = AtomicReference<NativeMediaOptimizerOutput>()
        lateinit var f: JobsFixture
        f = JobsFixture(NativeMediaOptimizerProcessor { request, source, handle, _, _ ->
            val value = f.media.engine.optimize(request, source, handle, NativeMediaOptimizerCancellation())
            output.set(value.output); published.countDown(); check(release.await(5, TimeUnit.SECONDS))
            NativeMediaOptimizerEngineResult(value.input, value.output)
        }, operation = "OptimizeImage")
        try {
            f.coordinator.start(f.request); assertTrue(published.await(5, TimeUnit.SECONDS))
            assertTrue(File(output.get().path).isFile)
            assertEquals(Contract.OUTPUT_IN_USE, f.coordinator.deleteOutput(f.request.id)["errorCode"])
            assertEquals("cancelling", f.coordinator.cancel(f.request.id).status)
            release.countDown()
            assertEquals("cancelled", f.terminal().status)
            assertFalse(File(output.get().path).exists()); assertTrue(f.source.file.isFile)
            assertTrue(f.media.outputs(f.source).isEmpty())
        } finally { release.countDown(); f.close() }
    }

    @Test fun failedSuccessCommitRemovesTheOutputAndRetriesAControlledFailure() {
        val encoded = CountDownLatch(1)
        lateinit var f: JobsFixture
        f = JobsFixture(NativeMediaOptimizerProcessor { request, source, handle, cancellation, _ ->
            val value = f.media.engine.optimize(request, source, handle, cancellation)
            f.memory.failWrites = true; encoded.countDown()
            NativeMediaOptimizerEngineResult(value.input, value.output)
        }, operation = "OptimizeImage")
        try {
            f.coordinator.start(f.request); assertTrue(encoded.await(5, TimeUnit.SECONDS))
            f.await { f.media.outputs(f.source).isEmpty() }
            assertTrue(f.events.isEmpty())
            assertNotEquals("succeeded", f.coordinator.getStatus(f.request.id).status)
            f.memory.failWrites = false
            val terminal = f.terminal()
            assertEquals("failed", terminal.status); assertEquals(Contract.PERSIST_FAILED, terminal.errorCode)
            assertNull(terminal.output); assertFalse(terminal.outputAvailable)
            f.await { f.events.size == 1 }
            assertEquals("failed", f.events.single().second.status)
        } finally { f.memory.failWrites = false; f.close() }
    }

    @Test fun aProgressCommitFailureCancelsWorkAndReportsPersistenceFailure() {
        val posted = CountDownLatch(1)
        lateinit var f: JobsFixture
        f = JobsFixture(NativeMediaOptimizerProcessor { _, source, _, cancellation, progress ->
            f.memory.failWrites = true; progress("encoding", 30); posted.countDown()
            val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(5)
            while (System.nanoTime() < deadline) { cancellation.check(); Thread.sleep(10) }
            NativeMediaOptimizerEngineResult(NativeMediaOptimizerMetadata("image/png", source.size, 16, 8, null, 0, false))
        })
        try {
            f.coordinator.start(f.request); assertTrue(posted.await(5, TimeUnit.SECONDS))
            // A status call waits behind the queued progress write.
            assertNotEquals("succeeded", f.coordinator.getStatus(f.request.id).status)
            f.memory.failWrites = false
            val terminal = f.terminal()
            assertEquals("failed", terminal.status); assertEquals(Contract.PERSIST_FAILED, terminal.errorCode)
        } finally { f.memory.failWrites = false; f.close() }
    }

    @Test fun changingTheSourceAfterEngineCompletionFailsClosedAndCleansOutput() {
        lateinit var f: JobsFixture
        f = JobsFixture(NativeMediaOptimizerProcessor { request, source, handle, cancellation, _ ->
            val value = f.media.engine.optimize(request, source, handle, cancellation)
            source.file.appendBytes(byteArrayOf(1))
            NativeMediaOptimizerEngineResult(value.input, value.output)
        }, operation = "OptimizeImage")
        f.use {
            f.coordinator.start(f.request)
            val result = f.terminal()
            assertEquals("failed", result.status); assertEquals(Contract.SOURCE_UNAVAILABLE, result.errorCode)
            assertTrue(f.media.outputs(f.source).isEmpty())
        }
    }

    @Test fun previousProcessJobsBecomeInterruptedAndOnlyTheirOwnedFilesAreRemoved() {
        JobsFixture(operation = "OptimizeImage").use { f ->
            val old = NativeMediaOptimizerStore(f.memory, UUID.randomUUID().toString())
            val record = (old.begin(f.request, f.source.outputDirectory.path) as NativeMediaOptimizerBeginResult.Started).record
            val partial = File(f.source.outputDirectory, ".${record.handle.id}.${record.handle.token}.part").apply { writeBytes(byteArrayOf(1)) }
            val output = File(f.source.outputDirectory, "${record.handle.id}.jpg").apply { writeBytes(byteArrayOf(1)) }
            val unrelated = File(f.source.outputDirectory, "${UUID.randomUUID()}.jpg").apply { writeBytes(byteArrayOf(2)) }
            val result = f.coordinator.getStatus(f.request.id)
            assertEquals("interrupted", result.status); assertEquals(Contract.PROCESS_INTERRUPTED, result.errorCode)
            assertFalse(partial.exists()); assertFalse(output.exists()); assertTrue(unrelated.isFile); assertTrue(f.source.file.isFile)
            f.coordinator.getStatus(f.request.id)
            assertEquals(1, f.events.size)
        }
    }

    @Test fun completedOutputSurvivesCoordinatorRecreationAndExplicitDeletionRetainsMetadata() {
        JobsFixture(operation = "OptimizeImage").use { f ->
            f.coordinator.start(f.request); val completed = f.terminal()
            f.coordinator.closeForTests()
            val next = NativeMediaOptimizerCoordinator(
                NativeMediaOptimizerStore(f.memory, UUID.randomUUID().toString()), f.media.files,
                { f.source }, NativeMediaOptimizerEngines(f.media.context, f.media.files)
            )
            try {
                val restored = next.getStatus(f.request.id)
                assertEquals("succeeded", restored.status); assertTrue(restored.outputAvailable)
                assertEquals(completed.output, restored.output)
                assertEquals(true, next.deleteOutput(f.request.id)["deleted"])
                val deleted = next.getStatus(f.request.id)
                assertFalse(deleted.outputAvailable); assertEquals(completed.output, deleted.output)
                assertFalse(File(completed.output!!.path).exists()); assertTrue(f.source.file.isFile)
                assertEquals(Contract.OUTPUT_NOT_FOUND, next.deleteOutput(f.request.id)["errorCode"])
            } finally { next.closeForTests(); f.coordinatorClosed = true }
        }
    }

    @Test fun failedCleanupIsDurableBlocksNewJobsAndCanBeRetriedSafely() {
        lateinit var f: JobsFixture
        var moved: File? = null
        f = JobsFixture(NativeMediaOptimizerProcessor { _, source, handle, _, _ ->
            File(source.outputDirectory, ".${handle.id}.${handle.token}.part").writeBytes(byteArrayOf(1))
            val redirected = File(source.outputDirectory.parentFile, "media-optimizer-quarantine")
            check(source.outputDirectory.renameTo(redirected)); moved = redirected
            Os.symlink(redirected.path, source.outputDirectory.path)
            throw NativeMediaOptimizerProcessingException(Contract.DECODE_FAILED)
        }, operation = "OptimizeImage")
        try {
            f.coordinator.start(f.request)
            val terminal = f.terminal()
            assertEquals("failed", terminal.status); assertEquals(Contract.OUTPUT_FAILED, terminal.errorCode)
            assertEquals(Contract.OUTPUT_FAILED, f.coordinator.start(f.media.request(f.source)).errorCode)
            Os.remove(f.source.outputDirectory.path)
            assertTrue(moved!!.renameTo(f.source.outputDirectory)); moved = null
            assertEquals("failed", f.coordinator.getStatus(f.request.id).status)
            assertTrue(f.media.outputs(f.source).isEmpty())
            assertEquals(Contract.INVALID_OPTIONS, f.coordinator.start(f.request).errorCode)
        } finally {
            moved?.let { original ->
                if (f.source.outputDirectory.canonicalFile.path != f.source.outputDirectory.path) Os.remove(f.source.outputDirectory.path)
                original.renameTo(f.source.outputDirectory)
            }
            f.close()
        }
    }

    @Test fun interruptedCleanupToleratesAnAlreadyRemovedPrivateOutputDirectory() {
        JobsFixture(operation = "OptimizeImage").use { f ->
            val old = NativeMediaOptimizerStore(f.memory, UUID.randomUUID().toString())
            assertTrue(old.begin(f.request, f.source.outputDirectory.path) is NativeMediaOptimizerBeginResult.Started)
            assertTrue(f.source.outputDirectory.delete())
            assertEquals("interrupted", f.coordinator.getStatus(f.request.id).status)
            assertTrue(f.source.outputDirectory.isDirectory)
            val next = f.media.request(f.source, operation = "InspectMedia")
            assertTrue(f.coordinator.start(next).accepted)
            assertEquals("succeeded", f.terminal(next.id).status)
        }
    }

    @Test fun missingOutputIsReconciledWithoutErasingItsMetadata() {
        JobsFixture(operation = "OptimizeImage").use { f ->
            f.coordinator.start(f.request); val completed = f.terminal()
            assertTrue(File(completed.output!!.path).delete())
            val result = f.coordinator.getStatus(f.request.id)
            assertEquals("succeeded", result.status); assertFalse(result.outputAvailable)
            assertEquals(completed.output, result.output)
        }
    }

    @Test fun deletionCommitFailureIsRepairedByTheNextQuery() {
        JobsFixture(operation = "OptimizeImage").use { f ->
            f.coordinator.start(f.request); val completed = f.terminal()
            f.memory.failWrites = true
            assertEquals(Contract.PERSIST_FAILED, f.coordinator.deleteOutput(f.request.id)["errorCode"])
            assertFalse(File(completed.output!!.path).exists())
            f.memory.failWrites = false
            assertFalse(f.coordinator.getStatus(f.request.id).outputAvailable)
        }
    }

    @Test fun inspectionsHaveNoOutputAndUnknownIdsReturnControlledResults() {
        JobsFixture().use { f ->
            f.coordinator.start(f.request); val result = f.terminal()
            assertEquals("succeeded", result.status); assertNotNull(result.input); assertNull(result.output)
            assertEquals(Contract.OUTPUT_NOT_FOUND, f.coordinator.deleteOutput(f.request.id)["errorCode"])
            val unknown = UUID.randomUUID().toString()
            assertEquals("not_found", f.coordinator.getStatus(unknown).status)
            assertEquals(Contract.RESULT_NOT_FOUND, f.coordinator.cancel(unknown).errorCode)
            assertEquals(Contract.RESULT_NOT_FOUND, f.coordinator.deleteOutput(unknown)["errorCode"])
            assertEquals(Contract.INVALID_REQUEST_ID, f.coordinator.getStatus("../bad").errorCode)
        }
    }

    @Test fun notificationFailureDoesNotChangeTheDurableResult() {
        JobsFixture(eventFailure = true).use { f ->
            f.coordinator.start(f.request)
            assertEquals("succeeded", f.terminal().status)
            assertEquals("succeeded", f.coordinator.getStatus(f.request.id).status)
        }
    }

    @Test fun routerProducesRealInspectionThumbnailAndVideoResults() {
        JobsFixture().use { f ->
            val video = NativeMediaOptimizerVideoTestSupport.source(f.media)
            f.sources[video.documentId] = video
            for (operation in listOf("InspectMedia", "GenerateThumbnail", "OptimizeVideo")) {
                val request = f.media.request(video, operation = operation)
                assertTrue(f.coordinator.start(request).accepted)
                val result = f.terminal(request.id)
                assertEquals("succeeded", result.status); assertEquals("video/mp4", result.input!!.mimeType)
                if (operation == "InspectMedia") assertNull(result.output)
                else {
                    assertTrue(result.outputAvailable)
                    assertTrue(File(result.output!!.path).length() > 0)
                    assertEquals(if (operation == "OptimizeVideo") "video/mp4" else "image/jpeg", result.output.metadata.mimeType)
                }
            }
        }
    }

    private class MemoryFile : NativeMediaOptimizerStateFile {
        private var stored: ByteArray? = null
        val writes = AtomicInteger()
        @Volatile var failWrites = false
        override fun <T> locked(action: () -> T): T = synchronized(this) { action() }
        override fun read() = stored?.clone()
        override fun write(bytes: ByteArray) {
            if (failWrites) throw NativeMediaOptimizerStorageException()
            stored = bytes.clone(); writes.incrementAndGet()
        }
    }

    private class JobsFixture(
        processor: NativeMediaOptimizerProcessor? = null,
        operation: String = "InspectMedia",
        eventFailure: Boolean = false
    ) : AutoCloseable {
        val media = NativeMediaOptimizerMediaTestFixture()
        val source = Bitmap.createBitmap(16, 8, Bitmap.Config.ARGB_8888).let { bitmap ->
            try { bitmap.eraseColor(Color.RED); media.image(bitmap) } finally { bitmap.recycle() }
        }
        val request = media.request(source, operation = operation)
        val sources = ConcurrentHashMap<String, NativeMediaOptimizerPreparedSource>().apply { put(source.documentId, source) }
        val memory = MemoryFile()
        val store = NativeMediaOptimizerStore(memory, UUID.randomUUID().toString())
        val events = CopyOnWriteArrayList<Pair<NativeMediaOptimizerResult, NativeMediaOptimizerResult>>()
        var coordinatorClosed = false
        val coordinator = NativeMediaOptimizerCoordinator(
            store, media.files,
            { id -> sources[id] ?: throw NativeMediaOptimizerProcessingException(Contract.SOURCE_UNAVAILABLE) },
            processor ?: NativeMediaOptimizerEngines(media.context, media.files),
            { result ->
                if (eventFailure) throw IllegalStateException("Test event transport unavailable.")
                events.add(result to store.getStatus(result.id!!))
            }
        )
        fun await(predicate: () -> Boolean) {
            val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(20)
            while (System.nanoTime() < deadline) { if (predicate()) return; Thread.sleep(10) }
            fail("Timed out waiting for the media job.")
        }
        fun terminal(id: String = request.id): NativeMediaOptimizerResult {
            var result = coordinator.getStatus(id)
            await { result = coordinator.getStatus(id); result.accepted && result.isTerminal }
            return result
        }
        override fun close() {
            try { if (!coordinatorClosed) coordinator.closeForTests() } finally { media.close() }
        }
    }
}
