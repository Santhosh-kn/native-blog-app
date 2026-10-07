package com.bbs.plugins.native_media_optimizer

import android.graphics.Bitmap
import android.graphics.Color
import android.system.Os
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.bbs.plugins.native_document_picker.NativeDocumentPickerPrivateSourceResult
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.util.UUID
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerFilesInstrumentedTest {
    private fun source(f: NativeMediaOptimizerMediaTestFixture): NativeMediaOptimizerPreparedSource {
        val bitmap = Bitmap.createBitmap(16, 16, Bitmap.Config.ARGB_8888).apply { eraseColor(Color.RED) }
        return try { f.image(bitmap) } finally { bitmap.recycle() }
    }
    private fun failure(code: String, action: () -> Unit) {
        try { action(); fail("Expected controlled failure.") }
        catch (error: NativeMediaOptimizerProcessingException) { assertEquals(code, error.code) }
    }

    @Test fun arbitraryIdsCannotResolveUnregisteredFiles() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f)
            failure(Contract.SOURCE_UNAVAILABLE) { f.files.resolve(selected.documentId) }
            failure(Contract.INVALID_SOURCE_ID) { f.files.resolve("../media") }
        }
    }

    @Test fun outputsUseOnlyTheFixedPrivateSiblingDirectory() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f)
            assertEquals(File(f.picker.parentFile, "native-media-optimizer").path, selected.outputDirectory.path)
            assertTrue(selected.outputDirectory.isDirectory)
            val request = f.request(selected)
            failure(Contract.OUTPUT_FAILED) { f.files.beginOutput(f.base.path, f.handle(request), "image/png") }
            failure(Contract.OUTPUT_FAILED) { f.files.beginOutput(File(selected.outputDirectory, "../native-media-optimizer").path, f.handle(request), "image/png") }
            val other = File(f.base, "other").apply { mkdir() }
            val forged = File(other, "${selected.documentId}.png").apply { selected.file.copyTo(this) }
            failure(Contract.SOURCE_UNAVAILABLE) { f.prepared(selected.documentId, forged, "image/png") }
        }
    }

    @Test fun sourceByteSizeAndCanonicalNameMustMatchThePickerRecord() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f)
            failure(Contract.SOURCE_UNAVAILABLE) {
                f.files.prepareResolved(NativeDocumentPickerPrivateSourceResult.Resolved(selected.documentId, selected.file, "media", "image/png", selected.size + 1))
            }
            val renamed = File(f.picker, "not-the-selected-id.png").apply { selected.file.copyTo(this) }
            failure(Contract.SOURCE_UNAVAILABLE) { f.prepared(selected.documentId, renamed, "image/png") }
            selected.file.appendBytes(byteArrayOf(1))
            failure(Contract.SOURCE_UNAVAILABLE) { f.files.verifySource(selected) }
        }
    }

    @Test fun symbolicLinksCannotRedirectInputsOrOutputRoots() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f)
            val victim = File(f.base, "victim.png").apply { selected.file.copyTo(this) }
            selected.file.delete(); Os.symlink(victim.path, selected.file.path)
            failure(Contract.SOURCE_UNAVAILABLE) { f.prepared(selected.documentId, selected.file, "image/png") }
            Os.remove(selected.file.path)
            val target = File(f.base, "target").apply { mkdir() }
            selected.outputDirectory.delete(); Os.symlink(target.path, selected.outputDirectory.path)
            try {
                failure(Contract.OUTPUT_FAILED) { f.files.beginOutput(selected.outputDirectory.path, f.handle(f.request(selected)), "image/png") }
                assertTrue(target.listFiles()!!.isEmpty())
                assertTrue(victim.isFile)
            } finally { Os.remove(selected.outputDirectory.path) }
        }
    }

    @Test fun publishingKeepsTheOriginalAndRemovesTheTemporaryFile() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val original = selected.file.readBytes()
            val request = f.request(selected)
            val transaction = f.files.beginOutput(selected.outputDirectory.path, f.handle(request), "image/png")
            val cancellation = NativeMediaOptimizerCancellation()
            transaction.write(cancellation) { it.write(byteArrayOf(1, 2, 3)) }
            assertFalse(transaction.finalFile.exists())
            val output = transaction.publish(cancellation)
            assertArrayEquals(byteArrayOf(1, 2, 3), output.readBytes())
            assertArrayEquals(original, selected.file.readBytes())
            assertEquals(listOf(output.name), f.outputs(selected).map { it.name })
        }
    }

    @Test fun aFileCreatedBeforePublishingIsNeverOverwritten() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val request = f.request(selected)
            val transaction = f.files.beginOutput(selected.outputDirectory.path, f.handle(request), "image/png")
            val cancellation = NativeMediaOptimizerCancellation()
            transaction.write(cancellation) { it.write(byteArrayOf(1)) }
            transaction.finalFile.writeBytes(byteArrayOf(9, 8))
            failure(Contract.OUTPUT_FAILED) { transaction.publish(cancellation) }
            assertTrue(transaction.discard())
            assertArrayEquals(byteArrayOf(9, 8), transaction.finalFile.readBytes())
            assertEquals(1, f.outputs(selected).size)
        }
    }

    @Test fun cancelledAndFailedWritesCanBeDiscardedWithoutTouchingTheInput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val original = selected.file.readBytes()
            val transaction = f.files.beginOutput(selected.outputDirectory.path, f.handle(f.request(selected)), "image/png")
            val cancellation = NativeMediaOptimizerCancellation()
            try {
                transaction.write(cancellation) { it.write(byteArrayOf(1)); cancellation.cancel(); it.write(byteArrayOf(2)) }
                fail("Cancellation must stop writing.")
            } catch (_: NativeMediaOptimizerCancelled) { assertTrue(transaction.discard()) }
            assertTrue(f.outputs(selected).isEmpty())
            val failed = f.files.beginOutput(selected.outputDirectory.path, f.handle(f.request(selected)), "image/png")
            failure(Contract.OUTPUT_FAILED) { failed.write(NativeMediaOptimizerCancellation()) { it.write(byteArrayOf(1)); throw java.io.IOException("injected") } }
            assertTrue(failed.discard())
            assertTrue(f.outputs(selected).isEmpty())
            assertArrayEquals(original, selected.file.readBytes())
        }
    }

    @Test fun interruptionCleanupIsLimitedToThePersistedJobFiles() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val request = f.request(selected); val handle = f.handle(request)
            val partial = File(selected.outputDirectory, ".${handle.id}.${handle.token}.part").apply { writeBytes(byteArrayOf(1)) }
            val final = File(selected.outputDirectory, "${handle.id}.jpg").apply { writeBytes(byteArrayOf(1)) }
            val other = File(selected.outputDirectory, "${UUID.randomUUID()}.jpg").apply { writeBytes(byteArrayOf(9)) }
            val result = NativeMediaOptimizerResult.pending(request).copy(status = "interrupted", phase = "interrupted", progress = null, errorCode = Contract.PROCESS_INTERRUPTED)
            val record = NativeMediaOptimizerRecord(handle, selected.outputDirectory.path, result, 1)
            assertTrue(f.files.discardInterrupted(record))
            assertFalse(partial.exists()); assertFalse(final.exists()); assertTrue(other.exists()); assertTrue(selected.file.exists())
            assertFalse(f.files.discardInterrupted(record.copy(result = NativeMediaOptimizerResult.pending(request))))
        }
    }

    @Test fun outputVerificationRejectsChangedMissingAndRedirectedFiles() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val request = f.request(selected)
            val result = f.engine.optimize(request, selected, f.handle(request), NativeMediaOptimizerCancellation())
            assertNotNull(f.files.verifiedOutput(selected.outputDirectory.path, result.output))
            val output = File(result.output.path)
            output.appendBytes(byteArrayOf(1))
            assertNull(f.files.verifiedOutput(selected.outputDirectory.path, result.output))
            output.delete()
            assertNull(f.files.verifiedOutput(selected.outputDirectory.path, result.output))
            assertFalse(f.files.deleteOutput(selected.outputDirectory.path, result.output))
            val next = f.request(selected)
            val nextResult = f.engine.optimize(next, selected, f.handle(next), NativeMediaOptimizerCancellation())
            assertTrue(f.files.deleteOutput(selected.outputDirectory.path, nextResult.output))
            assertNull(f.files.verifiedOutput(selected.outputDirectory.path, nextResult.output))
            assertTrue(selected.file.exists())
        }
    }
}
