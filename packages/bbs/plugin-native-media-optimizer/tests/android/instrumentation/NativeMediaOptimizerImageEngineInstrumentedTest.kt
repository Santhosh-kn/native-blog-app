package com.bbs.plugins.native_media_optimizer

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.media.ExifInterface
import android.util.Base64
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

@RunWith(AndroidJUnit4::class)
class NativeMediaOptimizerImageEngineInstrumentedTest {
    private fun bitmap(width: Int = 120, height: Int = 80, color: Int = Color.RED): Bitmap =
        Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888).apply { eraseColor(color) }
    private fun source(f: NativeMediaOptimizerMediaTestFixture, width: Int = 120, height: Int = 80, color: Int = Color.RED, mime: String = "image/png"): NativeMediaOptimizerPreparedSource {
        val bitmap = bitmap(width, height, color)
        return try { f.image(bitmap, mime) } finally { bitmap.recycle() }
    }
    private fun optimize(f: NativeMediaOptimizerMediaTestFixture, selected: NativeMediaOptimizerPreparedSource, options: Map<String, Any> = emptyMap()): NativeMediaOptimizerProcessed {
        val request = f.request(selected, options)
        return f.engine.optimize(request, selected, f.handle(request), NativeMediaOptimizerCancellation())
    }
    private fun failure(code: String, action: () -> Unit) {
        try { action(); fail("Expected controlled failure.") }
        catch (error: NativeMediaOptimizerProcessingException) { assertEquals(code, error.code) }
    }

    @Test fun inspectionReturnsDisplayDimensionsWithoutCreatingAnOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f)
            val result = f.engine.inspect(selected, NativeMediaOptimizerCancellation())
            assertEquals("image/png", result.mimeType); assertEquals(selected.size, result.size)
            assertEquals(120, result.width); assertEquals(80, result.height)
            assertEquals(0, result.rotationDegrees); assertNull(result.durationMs); assertFalse(result.hasAudio)
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun resizingFitsLandscapePortraitAndSmallImagesWithoutEnlarging() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            for ((width, height, expectedWidth, expectedHeight) in listOf(listOf(120, 80, 60, 40), listOf(80, 120, 40, 60), listOf(20, 10, 20, 10))) {
                val selected = source(f, width, height); val original = selected.file.readBytes()
                val result = optimize(f, selected, mapOf("max_width" to 60, "max_height" to 60))
                assertEquals(expectedWidth, result.output.metadata.width); assertEquals(expectedHeight, result.output.metadata.height)
                assertArrayEquals(original, selected.file.readBytes())
                val decoded = BitmapFactory.decodeFile(result.output.path)
                assertNotNull(decoded)
                try { assertEquals(expectedWidth, decoded.width); assertEquals(expectedHeight, decoded.height) } finally { decoded.recycle() }
            }
        }
    }

    @Test fun jpegPngAndWebpOutputsContainRealDecodableMedia() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            for (inputMime in listOf("image/jpeg", "image/png", "image/webp")) {
                val selected = source(f, mime = inputMime)
                for ((format, mime) in listOf("jpeg" to "image/jpeg", "png" to "image/png", "webp" to "image/webp")) {
                    val result = optimize(f, selected, mapOf("format" to format))
                    assertEquals(inputMime, result.input.mimeType); assertEquals(mime, result.output.metadata.mimeType)
                    assertEquals(File(result.output.path).length(), result.output.metadata.size)
                    assertEquals(0, result.output.metadata.rotationDegrees)
                    assertNotNull(f.files.verifiedOutput(selected.outputDirectory.path, result.output))
                    val decoded = BitmapFactory.decodeFile(result.output.path)
                    assertNotNull(decoded); decoded.recycle()
                }
            }
        }
    }

    @Test fun transparencyIsPreservedForPngAndWebpAndFlattenedWhiteForJpeg() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f, color = Color.TRANSPARENT)
            for (format in listOf("png", "webp", "jpeg")) {
                val result = optimize(f, selected, mapOf("format" to format))
                val decoded = BitmapFactory.decodeFile(result.output.path)
                try {
                    val pixel = decoded.getPixel(10, 10)
                    if (format == "jpeg") { assertEquals(255, Color.alpha(pixel)); assertTrue(Color.red(pixel) > 245 && Color.green(pixel) > 245 && Color.blue(pixel) > 245) }
                    else assertEquals(0, Color.alpha(pixel))
                } finally { decoded.recycle() }
            }
        }
    }

    @Suppress("DEPRECATION")
    @Test fun allEightExifOriginsAreAppliedOnceIncludingMirrors() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val corners = listOf(Color.RED, Color.GREEN, Color.YELLOW, Color.BLUE, Color.RED, Color.BLUE, Color.YELLOW, Color.GREEN)
            for (orientation in 1..8) {
                val bitmap = bitmap(80, 40)
                Canvas(bitmap).apply {
                    drawRect(40f, 0f, 80f, 20f, Paint().apply { color = Color.GREEN })
                    drawRect(0f, 20f, 40f, 40f, Paint().apply { color = Color.BLUE })
                    drawRect(40f, 20f, 80f, 40f, Paint().apply { color = Color.YELLOW })
                }
                val selected = try { f.image(bitmap, "image/jpeg") } finally { bitmap.recycle() }
                ExifInterface(selected.file.path).apply { setAttribute(ExifInterface.TAG_ORIENTATION, orientation.toString()); saveAttributes() }
                val updated = f.prepared(selected.documentId, selected.file, "image/jpeg")
                val result = optimize(f, updated, mapOf("format" to "png"))
                val expectedWidth = if (orientation >= 5) 40 else 80
                val expectedHeight = if (orientation >= 5) 80 else 40
                assertEquals(expectedWidth, result.input.width); assertEquals(expectedHeight, result.input.height)
                assertEquals(expectedWidth, result.output.metadata.width); assertEquals(expectedHeight, result.output.metadata.height)
                val decoded = BitmapFactory.decodeFile(result.output.path)
                try {
                    val pixel = decoded.getPixel(decoded.width / 4, decoded.height / 4)
                    val expected = corners[orientation - 1]
                    assertTrue("EXIF orientation $orientation", kotlin.math.abs(Color.red(pixel) - Color.red(expected)) < 40 && kotlin.math.abs(Color.green(pixel) - Color.green(expected)) < 40 && kotlin.math.abs(Color.blue(pixel) - Color.blue(expected)) < 40)
                } finally { decoded.recycle() }
            }
        }
    }

    @Test fun disguisedCorruptAndNonImageSourcesFailWithoutOutputs() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f)
            val disguised = f.prepared(selected.documentId, selected.file, "image/jpeg")
            failure(Contract.UNSUPPORTED_MEDIA) { optimize(f, disguised) }
            val broken = f.raw(byteArrayOf(1, 2, 3, 4), "image/png")
            failure(Contract.DECODE_FAILED) { optimize(f, broken) }
            val truncated = f.raw(selected.file.readBytes().copyOf(40), "image/png")
            failure(Contract.DECODE_FAILED) { optimize(f, truncated) }
            val video = f.raw(byteArrayOf(1), "video/mp4")
            failure(Contract.UNSUPPORTED_MEDIA) { optimize(f, video) }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun animatedWebpAndApngAreRejected() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            for ((mime, encoded) in listOf("image/webp" to ANIMATED_WEBP, "image/png" to ANIMATED_PNG)) {
                val selected = f.raw(Base64.decode(encoded, Base64.DEFAULT), mime)
                failure(Contract.UNSUPPORTED_MEDIA) { optimize(f, selected) }
                assertTrue(f.outputs(selected).isEmpty())
            }
        }
    }

    @Test fun cancellationBeforeDecodeProducesNoOutput() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val request = f.request(selected); val cancellation = NativeMediaOptimizerCancellation().apply { cancel() }
            try { f.engine.optimize(request, selected, f.handle(request), cancellation); fail("Expected cancellation.") }
            catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(f.outputs(selected).isEmpty()); assertTrue(selected.file.exists())
        }
    }

    @Test fun cancellationAfterEncodingRemovesThePartialFile() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val original = selected.file.readBytes()
            val request = f.request(selected); val cancellation = NativeMediaOptimizerCancellation()
            val phases = mutableListOf<String>()
            try {
                f.engine.optimize(request, selected, f.handle(request), cancellation) { phase, _ -> phases.add(phase); if (phase == "finalizing") cancellation.cancel() }
                fail("Expected cancellation.")
            } catch (_: NativeMediaOptimizerCancelled) { }
            assertTrue(phases.contains("finalizing")); assertTrue(f.outputs(selected).isEmpty())
            assertArrayEquals(original, selected.file.readBytes())
        }
    }

    @Test fun bindingErrorsAndOversizedDecodeBudgetsFailBeforeProcessing() {
        NativeMediaOptimizerMediaTestFixture().use { f ->
            val selected = source(f); val other = source(f); val request = f.request(selected)
            failure(Contract.INVALID_OPTIONS) { f.engine.optimize(request, other, f.handle(request), NativeMediaOptimizerCancellation()) }
            failure(Contract.LIMIT_EXCEEDED) { NativeMediaOptimizerGeometry.checkImageMemory(10000, 10000, 4096, 4096) }
            assertTrue(f.outputs(selected).isEmpty())
        }
    }

    @Test fun extremeAspectRatiosFitWithoutZeroSizedEdges() {
        assertEquals(1 to 100, NativeMediaOptimizerGeometry.fit(1, 100000, 100, 100))
        assertEquals(100 to 1, NativeMediaOptimizerGeometry.fit(100000, 1, 100, 100))
        assertEquals(100 to 50, NativeMediaOptimizerGeometry.fit(200, 100, 100, 100))
        assertEquals(20 to 10, NativeMediaOptimizerGeometry.fit(20, 10, 100, 100))
    }

    private companion object {
        const val ANIMATED_WEBP = "UklGRsQAAABXRUJQVlA4WAoAAAACAAAABwAABwAAQU5JTQYAAAAAAAAAAABBTk1GSgAAAAAAAAAAAAcAAAcAAGQAAAJWUDggMgAAADABAJ0BKggACAABQCYloAADcAD+8ut///mwP/bz/wR6Af//0uD//pcH//S4P/SkAAAAQU5NRkYAAAAAAAAAAAAHAAAHAABkAAAAVlA4IC4AAAA0AQCdASoIAAgAAAAmJaAAA3AA/vtV4///S4P/+lwf/9Lg/9Lg//rV5Vesq6AA"
        const val ANIMATED_PNG = "iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAACGFjVEwAAAACAAAAAPONk3AAAAAaZmNUTAAAAAAAAAAIAAAACAAAAAAAAAAAAAEACgAA8k66YgAAABZJREFUeJxj/M/A8J8BD2DCJzl8FAAAElsCDh9KSV0AAAAaZmNUTAAAAAEAAAAIAAAACAAAAAAAAAAAAAEACgAAaT1QtgAAABpmZEFUAAAAAnicY2Rg+P+fAQ9gwic5fBQAABBdAg7EQ+AwAAAAAElFTkSuQmCC"
    }
}
