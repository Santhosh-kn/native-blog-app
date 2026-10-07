package com.bbs.plugins.native_media_optimizer

import android.graphics.BitmapFactory
import android.graphics.Color
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerVideoTestSupport as Video

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerThumbnailEngineInstrumentedTest {
    @Test fun threeImageFormatsContainDecodableThumbnailsAndKeepOriginalBytes() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f, NativeMediaOptimizerVideoFixtures.AUDIO)
            val original = selected.file.readBytes()
            for ((format, mime) in listOf("jpeg" to "image/jpeg", "png" to "image/png", "webp" to "image/webp")) {
                val result = Video.generate(f, selected, mapOf("format" to format, "timestamp_ms" to 250, "max_width" to 80, "max_height" to 80))
                assertEquals("video/mp4", result.input.mimeType); assertTrue(result.input.hasAudio)
                assertEquals(mime, result.output.metadata.mimeType)
                assertEquals(80, result.output.metadata.width); assertEquals(48, result.output.metadata.height)
                assertNull(result.output.metadata.durationMs); assertFalse(result.output.metadata.hasAudio); assertEquals(0, result.output.metadata.rotationDegrees)
                assertEquals(File(result.output.path).length(), result.output.metadata.size)
                assertNotNull(f.files.verifiedOutput(selected.outputDirectory.path, result.output))
                Video.pixel(result.output, Color.RED)
                assertArrayEquals(original, selected.file.readBytes())
            }
        }
    }

    @Test fun timestampsSelectDifferentSectionsOfTheVideo() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f)
            Video.pixel(Video.generate(f, selected, mapOf("timestamp_ms" to 250, "format" to "png")).output, Color.RED)
            Video.pixel(Video.generate(f, selected, mapOf("timestamp_ms" to 2000, "format" to "png")).output, Color.BLUE)
        }
    }

    @Test fun rotationIsAppliedOnceToBothDimensionsAndPixels() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val expected = mapOf(0 to Color.RED, 90 to Color.BLUE, 180 to Color.YELLOW, 270 to Color.rgb(0, 128, 0))
            for (rotation in listOf(0, 90, 180, 270)) {
                val selected = Video.rotated(f, rotation)
                val result = Video.generate(f, selected, mapOf("timestamp_ms" to 250, "format" to "png", "max_width" to 80, "max_height" to 80))
                assertEquals(rotation, result.input.rotationDegrees)
                assertEquals(if (rotation == 90 || rotation == 270) 48 else 80, result.output.metadata.width)
                assertEquals(if (rotation == 90 || rotation == 270) 80 else 48, result.output.metadata.height)
                assertEquals(0, result.output.metadata.rotationDegrees)
                Video.pixel(result.output, expected[rotation]!!)
            }
        }
    }

    @Test fun webmQuicktimeAndSmallVideosAreNotEnlarged() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            for ((encoded, mime) in listOf(NativeMediaOptimizerVideoFixtures.WEBM to "video/webm", NativeMediaOptimizerVideoFixtures.QUICKTIME to "video/quicktime")) {
                val selected = Video.source(f, encoded, mime)
                val result = Video.generate(f, selected, mapOf("timestamp_ms" to 250))
                assertEquals(160, result.output.metadata.width); assertEquals(96, result.output.metadata.height)
                val bitmap = BitmapFactory.decodeFile(result.output.path)
                assertNotNull(bitmap)
                try { assertEquals(160, bitmap.width); assertEquals(96, bitmap.height) } finally { bitmap.recycle() }
            }
        }
    }

    @Test fun timestampsAtOrAfterDurationAreRejectedWithoutOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f)
            val duration = Video.inspection(f).inspect(selected, NativeMediaOptimizerCancellation()).metadata.durationMs!!.toInt()
            for (timestamp in listOf(duration, duration + 100)) {
                Video.failure(Contract.INVALID_TIME_RANGE) { Video.generate(f, selected, mapOf("timestamp_ms" to timestamp)) }
            }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun operationSourceAndRequestHandleMustMatch() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f); val other = Video.source(f)
            val request = f.request(selected, operation = "GenerateThumbnail")
            Video.failure(Contract.INVALID_OPTIONS) { Video.thumbnails(f).generate(request, other, f.handle(request), NativeMediaOptimizerCancellation()) }
            val otherRequest = f.request(selected, operation = "GenerateThumbnail")
            Video.failure(Contract.INVALID_OPTIONS) { Video.thumbnails(f).generate(request, selected, f.handle(otherRequest), NativeMediaOptimizerCancellation()) }
            val wrongOperation = f.request(selected)
            Video.failure(Contract.INVALID_OPTIONS) { Video.thumbnails(f).generate(wrongOperation, selected, f.handle(wrongOperation), NativeMediaOptimizerCancellation()) }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun cancellationBeforeDecodingPreservesTheSourceAndCreatesNoOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f); val original = selected.file.readBytes()
            val request = f.request(selected, operation = "GenerateThumbnail")
            val cancellation = NativeMediaOptimizerCancellation().apply { cancel() }
            try { Video.thumbnails(f).generate(request, selected, f.handle(request), cancellation); fail("Expected cancellation.") } catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(f.outputs(selected).isEmpty()); assertArrayEquals(original, selected.file.readBytes())
        }
    }

    @Test fun cancellationAfterEncodingRemovesTheOwnedTemporaryOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f); val original = selected.file.readBytes()
            val request = f.request(selected, operation = "GenerateThumbnail")
            val cancellation = NativeMediaOptimizerCancellation()
            val phases = mutableListOf<String>()
            try {
                Video.thumbnails(f).generate(request, selected, f.handle(request), cancellation) { phase, _ ->
                    phases.add(phase); if (phase == "finalizing") cancellation.cancel()
                }
                fail("Expected cancellation.")
            } catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(phases.contains("finalizing")); assertTrue(f.outputs(selected).isEmpty())
            assertArrayEquals(original, selected.file.readBytes())
        }
    }

    @Test fun aSourceChangedBetweenInspectionAndDecodingFailsClosed() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = Video.source(f)
            val request = f.request(selected, operation = "GenerateThumbnail")
            Video.failure(Contract.SOURCE_UNAVAILABLE) {
                Video.thumbnails(f).generate(request, selected, f.handle(request), NativeMediaOptimizerCancellation()) { phase, _ ->
                    if (phase == "decoding") selected.file.appendBytes(byteArrayOf(0))
                }
            }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }
}
