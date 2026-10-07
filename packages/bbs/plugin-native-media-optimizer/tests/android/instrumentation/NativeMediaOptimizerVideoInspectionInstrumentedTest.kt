package com.bbs.plugins.native_media_optimizer

import android.util.Base64
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerVideoTestSupport as Video

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerVideoInspectionInstrumentedTest {
    @Test fun mp4WebmAndQuicktimeReportRealTrackAndContainerMetadata() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            for ((encoded, mime) in listOf(NativeMediaOptimizerVideoFixtures.TIMELINE to "video/mp4", NativeMediaOptimizerVideoFixtures.WEBM to "video/webm", NativeMediaOptimizerVideoFixtures.QUICKTIME to "video/quicktime")) {
                val selected = Video.source(f, encoded, mime)
                val original = selected.file.readBytes()
                val info = Video.inspection(f).inspect(selected, NativeMediaOptimizerCancellation())
                assertEquals(mime, info.metadata.mimeType); assertEquals(selected.size, info.metadata.size)
                assertEquals(160, info.metadata.width); assertEquals(96, info.metadata.height)
                assertTrue(info.metadata.durationMs!! in 2900L..3100L)
                assertEquals(0, info.metadata.rotationDegrees); assertFalse(info.metadata.hasAudio)
                assertNull(info.audioFormat); assertFalse(info.encrypted); assertFalse(info.hdr); assertTrue(info.squarePixels)
                info.requireFrameDecoder()
                assertArrayEquals(original, selected.file.readBytes()); assertTrue(f.outputs(selected).isEmpty())
            }
        }
    }

    @Test fun audioPresenceComesFromTheActualAudioTrack() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f, NativeMediaOptimizerVideoFixtures.AUDIO)
            val info = Video.inspection(f).inspect(selected, NativeMediaOptimizerCancellation())
            assertTrue(info.metadata.hasAudio); assertNotNull(info.audioFormat)
            assertTrue(info.metadata.durationMs!! in 2900L..3200L)
            assertEquals(160, info.displayWidth); assertEquals(96, info.displayHeight)
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun rotationMetadataAndDisplayDimensionsRemainDistinct() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            for (rotation in listOf(0, 90, 180, 270)) {
                val selected = Video.rotated(f, rotation)
                val info = Video.inspection(f).inspect(selected, NativeMediaOptimizerCancellation())
                assertEquals(rotation, info.metadata.rotationDegrees)
                assertEquals(160, info.metadata.width); assertEquals(96, info.metadata.height)
                assertEquals(if (rotation == 90 || rotation == 270) 96 else 160, info.displayWidth)
                assertEquals(if (rotation == 90 || rotation == 270) 160 else 96, info.displayHeight)
            }
        }
    }

    @Test fun corruptDisguisedAndUnsupportedContainersFailWithoutOutputs() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f)
            Video.failure(Contract.UNSUPPORTED_MEDIA) { Video.inspection(f).inspect(f.prepared(selected.documentId, selected.file, "video/webm"), NativeMediaOptimizerCancellation()) }
            val corrupt = f.raw(byteArrayOf(1, 2, 3, 4), "video/mp4")
            Video.failure(Contract.DECODE_FAILED) { Video.inspection(f).inspect(corrupt, NativeMediaOptimizerCancellation()) }
            val truncated = f.raw(selected.file.readBytes().copyOf(12), "video/mp4")
            Video.failure(Contract.DECODE_FAILED) { Video.inspection(f).inspect(truncated, NativeMediaOptimizerCancellation()) }
            val unknownBrand = selected.file.readBytes().apply { for (index in 8..11) this[index] = 120.toByte() }
            Video.failure(Contract.UNSUPPORTED_MEDIA) { Video.inspection(f).inspect(f.raw(unknownBrand, "video/mp4"), NativeMediaOptimizerCancellation()) }
            val ebml = Base64.decode(NativeMediaOptimizerVideoFixtures.WEBM, Base64.DEFAULT)
            val docType = (0 until ebml.size - 4).first { ebml[it] == 119.toByte() && ebml[it + 1] == 101.toByte() && ebml[it + 2] == 98.toByte() && ebml[it + 3] == 109.toByte() }
            "matr".toByteArray(Charsets.US_ASCII).copyInto(ebml, docType)
            Video.failure(Contract.UNSUPPORTED_MEDIA) { Video.inspection(f).inspect(f.raw(ebml, "video/webm"), NativeMediaOptimizerCancellation()) }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun nonVideoMediaIsRejectedBeforeTrackDecoding() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = f.raw(byteArrayOf(1), "image/png")
            Video.failure(Contract.UNSUPPORTED_MEDIA) { Video.inspection(f).inspect(selected, NativeMediaOptimizerCancellation()) }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun cancellationAndMissingSourceDoNotCreateOutputs() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f)
            val cancellation = NativeMediaOptimizerCancellation().apply { cancel() }
            try { Video.inspection(f).inspect(selected, cancellation); fail("Expected cancellation.") } catch (_: NativeMediaOptimizerCancelled) { }
            check(selected.file.delete())
            Video.failure(Contract.SOURCE_UNAVAILABLE) { Video.inspection(f).inspect(selected, NativeMediaOptimizerCancellation()) }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }
}
