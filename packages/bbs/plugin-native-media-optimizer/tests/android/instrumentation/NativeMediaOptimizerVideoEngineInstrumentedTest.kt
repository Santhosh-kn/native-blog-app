package com.bbs.plugins.native_media_optimizer

import android.graphics.Color
import android.media.MediaMetadataRetriever
import android.os.Looper
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.util.Collections
import java.util.concurrent.atomic.AtomicBoolean
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerVideoTestSupport as Video

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerVideoEngineInstrumentedTest {
    private fun engine(f: NativeMediaOptimizerMediaTestFixture) = NativeMediaOptimizerVideoEngine(f.context, f.files, Video.inspection(f))
    private fun optimize(f: NativeMediaOptimizerMediaTestFixture, source: NativeMediaOptimizerPreparedSource, options: Map<String, Any> = emptyMap()): NativeMediaOptimizerProcessed {
        val request = f.request(source, options, "OptimizeVideo")
        return engine(f).optimize(request, source, f.handle(request), NativeMediaOptimizerCancellation())
    }
    private fun color(output: NativeMediaOptimizerOutput, expected: Int, timestampMs: Long = 200L) {
        withVideoRetriever(File(output.path), output.metadata.size) { retriever ->
            val bitmap = retriever.getScaledFrameAtTime(timestampMs * 1000L, MediaMetadataRetriever.OPTION_CLOSEST, 80, 80)
            assertNotNull(bitmap)
            try {
                val actual = bitmap!!.getPixel(bitmap.width / 4, bitmap.height / 4)
                assertTrue("Decoded output video color", kotlin.math.abs(Color.red(actual) - Color.red(expected)) < 45 &&
                    kotlin.math.abs(Color.green(actual) - Color.green(expected)) < 45 && kotlin.math.abs(Color.blue(actual) - Color.blue(expected)) < 45)
            } finally { bitmap?.recycle() }
        }
    }

    @Test(timeout = 120000) fun resizingProducesRealH264AacMp4AndPreservesTheSource() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f, NativeMediaOptimizerVideoFixtures.AUDIO); val original = source.file.readBytes()
            val result = optimize(f, source, mapOf("max_width" to 80, "max_height" to 80, "video_bitrate" to 128000, "audio_bitrate" to 64000))
            assertEquals("video/mp4", result.output.metadata.mimeType)
            assertEquals(80, result.output.metadata.width); assertEquals(48, result.output.metadata.height)
            assertEquals(0, result.output.metadata.rotationDegrees); assertTrue(result.output.metadata.hasAudio)
            assertTrue(result.output.metadata.durationMs!! in 2900L..3200L)
            assertTrue(result.output.metadata.size > 0L); assertNotNull(f.files.verifiedOutput(source.outputDirectory.path, result.output))
            assertArrayEquals(original, source.file.readBytes()); color(result.output, Color.RED)
            val output = Video.inspection(f).inspectFile(File(result.output.path), result.output.metadata.size, "video/mp4", NativeMediaOptimizerCancellation())
            assertEquals("video/avc", output.videoFormat.getString("mime")); assertEquals("audio/mp4a-latm", output.audioFormat!!.getString("mime"))
            assertEquals(1, f.outputs(source).size)
        }
    }

    @Test(timeout = 120000) fun trimmingAtANonKeyframeStartsInTheRequestedVideoSection() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f, NativeMediaOptimizerVideoFixtures.AUDIO); val original = source.file.readBytes()
            val result = optimize(f, source, mapOf("start_ms" to 1750, "end_ms" to 2750, "video_bitrate" to 128000))
            assertTrue(result.input.durationMs!! in 2900L..3100L)
            assertTrue(result.output.metadata.durationMs!! in 900L..1200L)
            assertTrue(result.output.metadata.hasAudio)
            color(result.output, Color.BLUE, 0L); color(result.output, Color.BLUE, 800L)
            assertArrayEquals(original, source.file.readBytes())
        }
    }

    @Test(timeout = 120000) fun removingAudioLeavesADecodableSilentVideo() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f, NativeMediaOptimizerVideoFixtures.AUDIO)
            val result = optimize(f, source, mapOf("remove_audio" to true, "video_bitrate" to 128000))
            assertTrue(result.input.hasAudio); assertFalse(result.output.metadata.hasAudio)
            val output = Video.inspection(f).inspectFile(File(result.output.path), result.output.metadata.size, "video/mp4", NativeMediaOptimizerCancellation())
            assertNull(output.audioFormat); color(result.output, Color.RED)
        }
    }

    @Test(timeout = 120000) fun matchingDimensionsStillEncodeAndNeverAddAudioOrEnlarge() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val original = source.file.readBytes()
            val result = optimize(f, source, mapOf("video_bitrate" to 128000))
            assertEquals(160, result.output.metadata.width); assertEquals(96, result.output.metadata.height)
            assertFalse(result.input.hasAudio); assertFalse(result.output.metadata.hasAudio)
            assertFalse(original.contentEquals(File(result.output.path).readBytes()))
            color(result.output, Color.BLUE, 2000L)
            assertArrayEquals(original, source.file.readBytes())
        }
    }

    @Test(timeout = 180000) fun webmAndQuicktimeTranscodeToMp4() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            for ((encoded, mime) in listOf(NativeMediaOptimizerVideoFixtures.WEBM to "video/webm", NativeMediaOptimizerVideoFixtures.QUICKTIME to "video/quicktime")) {
                val source = Video.source(f, encoded, mime); val original = source.file.readBytes()
                val result = optimize(f, source, mapOf("video_bitrate" to 128000))
                assertEquals(mime, result.input.mimeType); assertEquals("video/mp4", result.output.metadata.mimeType)
                assertTrue(result.output.path.endsWith(".mp4")); assertEquals(160, result.output.metadata.width); assertEquals(96, result.output.metadata.height)
                color(result.output, Color.RED); assertArrayEquals(original, source.file.readBytes())
            }
        }
    }

    @Test(timeout = 240000) fun rotationIsBakedIntoTheEncodedDimensionsAndPixelsExactlyOnce() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val expected = mapOf(0 to Color.RED, 90 to Color.BLUE, 180 to Color.YELLOW, 270 to Color.rgb(0, 128, 0))
            for (rotation in listOf(0, 90, 180, 270)) {
                val source = Video.rotated(f, rotation); val original = source.file.readBytes()
                val result = optimize(f, source, mapOf("max_width" to 80, "max_height" to 80, "video_bitrate" to 128000))
                assertEquals(rotation, result.input.rotationDegrees); assertEquals(0, result.output.metadata.rotationDegrees)
                assertEquals(if (rotation == 90 || rotation == 270) 48 else 80, result.output.metadata.width)
                assertEquals(if (rotation == 90 || rotation == 270) 80 else 48, result.output.metadata.height)
                color(result.output, expected[rotation]!!)
                assertArrayEquals(original, source.file.readBytes())
            }
        }
    }

    @Test fun invalidRangesAndMalformedMediaDoNotCreateOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f)
            val duration = Video.inspection(f).inspect(source, NativeMediaOptimizerCancellation()).metadata.durationMs!!.toInt()
            Video.failure(Contract.INVALID_TIME_RANGE) { optimize(f, source, mapOf("start_ms" to duration)) }
            Video.failure(Contract.INVALID_TIME_RANGE) { optimize(f, source, mapOf("end_ms" to duration + 100)) }
            Video.failure(Contract.DECODE_FAILED) { optimize(f, f.raw(byteArrayOf(1, 2, 3, 4), "video/mp4")) }
            Video.failure(Contract.UNSUPPORTED_MEDIA) { optimize(f, f.prepared(source.documentId, source.file, "video/webm")) }
            assertTrue(f.outputs(source).isEmpty())
        }
    }

    @Test fun operationSourceAndHandleBindingsAreRequired() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val other = Video.source(f)
            val request = f.request(source, operation = "OptimizeVideo")
            Video.failure(Contract.INVALID_OPTIONS) { engine(f).optimize(request, other, f.handle(request), NativeMediaOptimizerCancellation()) }
            val different = f.request(source, operation = "OptimizeVideo")
            Video.failure(Contract.INVALID_OPTIONS) { engine(f).optimize(request, source, f.handle(different), NativeMediaOptimizerCancellation()) }
            val wrong = f.request(source, operation = "GenerateThumbnail")
            Video.failure(Contract.INVALID_OPTIONS) { engine(f).optimize(wrong, source, f.handle(wrong), NativeMediaOptimizerCancellation()) }
            assertTrue(f.outputs(source).isEmpty())
        }
    }

    @Test fun cancellationBeforeExportCreatesNoOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val original = source.file.readBytes()
            val request = f.request(source, operation = "OptimizeVideo")
            val cancellation = NativeMediaOptimizerCancellation().apply { cancel() }
            try { engine(f).optimize(request, source, f.handle(request), cancellation); fail("Expected cancellation.") } catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(f.outputs(source).isEmpty()); assertArrayEquals(original, source.file.readBytes())
        }
    }

    @Test(timeout = 120000) fun activeExportCancellationReleasesTheEncoderBeforeRemovingOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val original = source.file.readBytes()
            val request = f.request(source, operation = "OptimizeVideo")
            val cancellation = NativeMediaOptimizerCancellation()
            val reachedEncoder = AtomicBoolean(false)
            try {
                engine(f).optimize(request, source, f.handle(request), cancellation) { phase, _ ->
                    if (phase == "encoding") { reachedEncoder.set(true); cancellation.cancel() }
                }
                fail("Expected cancellation.")
            } catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(reachedEncoder.get()); assertTrue(f.outputs(source).isEmpty())
            assertArrayEquals(original, source.file.readBytes())
            // A fresh export must be able to acquire codec resources afterwards.
            color(optimize(f, source, mapOf("video_bitrate" to 128000)).output, Color.RED)
        }
    }

    @Test(timeout = 120000) fun finalizationCancellationDiscardsTheEncodedFileAndPreservesOriginal() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f); val original = source.file.readBytes()
            val request = f.request(source, operation = "OptimizeVideo")
            val cancellation = NativeMediaOptimizerCancellation()
            var reachedFinalization = false
            try {
                engine(f).optimize(request, source, f.handle(request), cancellation) { phase, _ ->
                    if (phase == "finalizing") {
                        reachedFinalization = f.outputs(source).any { it.name.endsWith(".part") && it.length() > 0 }
                        cancellation.cancel()
                    }
                }
                fail("Expected cancellation.")
            } catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(reachedFinalization); assertTrue(f.outputs(source).isEmpty()); assertArrayEquals(original, source.file.readBytes())
        }
    }

    @Test(timeout = 120000) fun progressIsMonotonicOffTheUiThreadAndSourceChangesFailClosed() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val source = Video.source(f)
            val request = f.request(source, mapOf("video_bitrate" to 128000), "OptimizeVideo")
            val updates = Collections.synchronizedList(mutableListOf<Int>())
            val uiCallback = AtomicBoolean(false)
            val result = engine(f).optimize(request, source, f.handle(request), NativeMediaOptimizerCancellation()) { _, value ->
                updates.add(value); if (Looper.myLooper() == Looper.getMainLooper()) uiCallback.set(true)
            }
            assertFalse(uiCallback.get()); assertTrue(updates.size >= 2)
            assertEquals(5, updates.first()); assertEquals(95, updates.last())
            assertTrue(updates.zipWithNext().all { it.first <= it.second }); color(result.output, Color.RED)
            check(f.files.deleteOutput(source.outputDirectory.path, result.output))
            val changed = AtomicBoolean(false)
            val second = f.request(source, mapOf("video_bitrate" to 128000), "OptimizeVideo")
            Video.failure(Contract.SOURCE_UNAVAILABLE) {
                engine(f).optimize(second, source, f.handle(second), NativeMediaOptimizerCancellation()) { phase, _ ->
                    if (phase == "encoding" && changed.compareAndSet(false, true)) source.file.appendBytes(byteArrayOf(0))
                }
            }
            assertTrue(changed.get()); assertTrue(f.outputs(source).isEmpty())
        }
    }
}
