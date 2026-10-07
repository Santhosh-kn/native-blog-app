package com.bbs.plugins.native_media_optimizer

import android.graphics.BitmapFactory
import android.graphics.Color
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaMuxer
import android.util.Base64
import org.junit.Assert.*
import java.io.File
import java.nio.ByteBuffer
import java.util.UUID

/** Small generated H.264/AAC/VP8 fixtures; contains no personal media. */
internal object NativeMediaOptimizerVideoTestSupport {
    fun source(f: NativeMediaOptimizerMediaTestFixture, encoded: String = NativeMediaOptimizerVideoFixtures.TIMELINE, mime: String = "video/mp4") =
        f.raw(Base64.decode(encoded, Base64.DEFAULT), mime)

    fun inspection(f: NativeMediaOptimizerMediaTestFixture) = NativeMediaOptimizerVideoInspection(f.files)
    fun thumbnails(f: NativeMediaOptimizerMediaTestFixture) = NativeMediaOptimizerThumbnailEngine(f.files, inspection(f), f.engine)
    fun generate(f: NativeMediaOptimizerMediaTestFixture, selected: NativeMediaOptimizerPreparedSource, options: Map<String, Any> = emptyMap()): NativeMediaOptimizerProcessed {
        val request = f.request(selected, options, "GenerateThumbnail")
        return thumbnails(f).generate(request, selected, f.handle(request), NativeMediaOptimizerCancellation())
    }

    fun failure(code: String, action: () -> Unit) {
        try { action(); fail("Expected controlled failure.") }
        catch (error: NativeMediaOptimizerProcessingException) { assertEquals(code, error.code) }
    }

    fun pixel(output: NativeMediaOptimizerOutput, expected: Int) {
        val bitmap = BitmapFactory.decodeFile(output.path)
        assertNotNull(bitmap)
        try {
            val actual = bitmap.getPixel(bitmap.width / 4, bitmap.height / 4)
            assertTrue("Decoded frame color", kotlin.math.abs(Color.red(actual) - Color.red(expected)) < 45 &&
                kotlin.math.abs(Color.green(actual) - Color.green(expected)) < 45 &&
                kotlin.math.abs(Color.blue(actual) - Color.blue(expected)) < 45)
        } finally { bitmap.recycle() }
    }

    // Android's muxer stores a clockwise composition matrix without rotating pixels.
    // This tests that thumbnail decoding applies that matrix exactly once.
    fun rotated(f: NativeMediaOptimizerMediaTestFixture, rotation: Int): NativeMediaOptimizerPreparedSource {
        val original = source(f, NativeMediaOptimizerVideoFixtures.QUADRANTS)
        val id = UUID.randomUUID().toString()
        val output = File(f.picker, "$id.mp4")
        val extractor = MediaExtractor()
        var muxer: MediaMuxer? = null
        try {
            extractor.setDataSource(original.file.path)
            check(extractor.trackCount == 1)
            extractor.selectTrack(0)
            val writer = MediaMuxer(output.path, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)
            muxer = writer
            val target = writer.addTrack(extractor.getTrackFormat(0))
            writer.setOrientationHint(rotation); writer.start()
            val buffer = ByteBuffer.allocateDirect(1024 * 1024)
            val info = MediaCodec.BufferInfo()
            var samples = 0
            while (true) {
                buffer.clear()
                val count = extractor.readSampleData(buffer, 0)
                if (count < 0) break
                check(count <= buffer.capacity() && samples++ < 100)
                info.set(0, count, extractor.sampleTime, extractor.sampleFlags)
                writer.writeSampleData(target, buffer, info)
                if (!extractor.advance()) break
            }
            check(samples > 0); writer.stop()
        } finally { try { muxer?.release() } finally { extractor.release() } }
        return f.prepared(id, output, "video/mp4")
    }
}
