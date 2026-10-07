package com.bbs.plugins.native_media_optimizer

import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.FileInputStream
import java.io.RandomAccessFile
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerVideoTestSupport as Video

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerVideoOutputInstrumentedTest {
    @Test fun anExternalEncoderFileIsSealedPublishedAndVerified() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val original = source.file.readBytes()
            val request = f.request(source, operation = "OptimizeVideo")
            val cancellation = NativeMediaOptimizerCancellation()
            val transaction = f.files.beginOutput(source.outputDirectory.path, f.handle(request), "video/mp4")
            val partial = transaction.prepareForEncoder(cancellation)
            partial.outputStream().use { it.write(original) }
            assertEquals(original.size.toLong(), transaction.checkEncoderOutput(cancellation))
            transaction.finishEncoderOutput(cancellation)
            val published = transaction.publish(cancellation)
            assertFalse(partial.exists()); assertTrue(published.exists())
            val info = Video.inspection(f).inspectFile(published, published.length(), "video/mp4", cancellation)
            assertEquals(160, info.metadata.width); assertEquals(96, info.metadata.height)
            assertArrayEquals(original, source.file.readBytes())
            assertTrue(transaction.discard()); assertFalse(published.exists())
        }
    }

    @Test fun anEmptyEncoderFileCannotBePublished() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val request = f.request(source, operation = "OptimizeVideo")
            val transaction = f.files.beginOutput(source.outputDirectory.path, f.handle(request), "video/mp4")
            transaction.prepareForEncoder(NativeMediaOptimizerCancellation())
            Video.failure(Contract.OUTPUT_FAILED) { transaction.finishEncoderOutput(NativeMediaOptimizerCancellation()) }
            assertTrue(transaction.discard()); assertTrue(f.outputs(source).isEmpty())
        }
    }

    @Test fun oversizedEncoderOutputIsRejectedAndRemoved() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val request = f.request(source, operation = "OptimizeVideo")
            val transaction = f.files.beginOutput(source.outputDirectory.path, f.handle(request), "video/mp4")
            val partial = transaction.prepareForEncoder(NativeMediaOptimizerCancellation())
            // A sparse file tests the actual filesystem boundary without allocating media bytes.
            RandomAccessFile(partial, "rw").use { it.setLength(Contract.MAX_INPUT_BYTES + 1L) }
            Video.failure(Contract.LIMIT_EXCEEDED) { transaction.checkEncoderOutput(NativeMediaOptimizerCancellation()) }
            Video.failure(Contract.LIMIT_EXCEEDED) { transaction.finishEncoderOutput(NativeMediaOptimizerCancellation()) }
            assertTrue(transaction.discard()); assertTrue(f.outputs(source).isEmpty())
        }
    }

    @Test fun aSubstitutedFileIsRejectedAndNeverDeletedAsOwnedOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val request = f.request(source, operation = "OptimizeVideo")
            val transaction = f.files.beginOutput(source.outputDirectory.path, f.handle(request), "video/mp4")
            val partial = transaction.prepareForEncoder(NativeMediaOptimizerCancellation())
            // Keep the old inode open so the filesystem cannot immediately recycle it.
            FileInputStream(partial).use {
                check(partial.delete()); partial.writeBytes(byteArrayOf(7, 8, 9))
                Video.failure(Contract.OUTPUT_FAILED) { transaction.finishEncoderOutput(NativeMediaOptimizerCancellation()) }
                assertFalse(transaction.discard()); assertArrayEquals(byteArrayOf(7, 8, 9), partial.readBytes())
            }
        }
    }

    @Test fun cancellationBeforeSealingKeepsOnlyTheOriginal() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val original = source.file.readBytes()
            val request = f.request(source, operation = "OptimizeVideo")
            val transaction = f.files.beginOutput(source.outputDirectory.path, f.handle(request), "video/mp4")
            val cancellation = NativeMediaOptimizerCancellation()
            transaction.prepareForEncoder(cancellation).writeBytes(original)
            cancellation.cancel()
            try { transaction.finishEncoderOutput(cancellation); fail("Expected cancellation.") } catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(transaction.discard()); assertTrue(f.outputs(source).isEmpty())
            assertArrayEquals(original, source.file.readBytes())
        }
    }
}
