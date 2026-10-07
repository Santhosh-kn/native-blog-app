package com.bbs.plugins.native_media_optimizer

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.ColorSpace
import android.graphics.ImageDecoder
import java.io.File
import java.io.RandomAccessFile
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

/**
 * Static JPEG/PNG/WebP only. ImageDecoder applies EXIF origin before resizing.
 * API: https://developer.android.com/reference/android/graphics/ImageDecoder
 * Origin handling: AOSP frameworks/base libs/hwui/hwui/ImageDecoder.cpp.
 */
internal class NativeMediaOptimizerImageEngine(private val files: NativeMediaOptimizerFiles) {
    fun inspect(source: NativeMediaOptimizerPreparedSource, cancellation: NativeMediaOptimizerCancellation): NativeMediaOptimizerMetadata {
        requireMediaWorker(); cancellation.check(); files.verifySource(source)
        if (!source.declaredMimeType.startsWith("image/")) fail(Contract.UNSUPPORTED_MEDIA)
        if (source.size > Contract.MAX_IMAGE_INPUT_BYTES) fail(Contract.LIMIT_EXCEEDED)
        val metadata = header(source.file, source.size, source.declaredMimeType)
        cancellation.check(); files.verifySource(source)
        return metadata
    }

    fun optimize(
        request: NativeMediaOptimizerRequest,
        source: NativeMediaOptimizerPreparedSource,
        handle: NativeMediaOptimizerHandle,
        cancellation: NativeMediaOptimizerCancellation,
        progress: (String, Int) -> Unit = { _, _ -> }
    ): NativeMediaOptimizerProcessed {
        requireMediaWorker()
        if (request.operation != "OptimizeImage" || request.id != handle.id || request.sourceDocumentId != source.documentId) fail(Contract.INVALID_OPTIONS)
        progress("inspecting", 5)
        val input = inspect(source, cancellation)
        val (width, height) = NativeMediaOptimizerGeometry.fit(input.width, input.height, request.maxWidth, request.maxHeight)
        NativeMediaOptimizerGeometry.checkImageMemory(input.width, input.height, width, height)
        cancellation.check(); progress("decoding", 20)
        val bitmap = decode(source, input, width, height)
        try {
            cancellation.check(); files.verifySource(source)
            progress("encoding", 60)
            return encode(request, source.outputDirectory.path, handle, input, bitmap, cancellation, progress)
        } finally { bitmap.recycle() }
    }

    /** Also used by the video thumbnail engine; the caller owns and recycles bitmap. */
    fun encode(
        request: NativeMediaOptimizerRequest,
        outputDirectory: String,
        handle: NativeMediaOptimizerHandle,
        input: NativeMediaOptimizerMetadata,
        bitmap: Bitmap,
        cancellation: NativeMediaOptimizerCancellation,
        progress: (String, Int) -> Unit = { _, _ -> }
    ): NativeMediaOptimizerProcessed {
        requireMediaWorker(); cancellation.check()
        val maximumEdge = if (request.operation == "GenerateThumbnail") Contract.MAX_THUMBNAIL_EDGE else Contract.MAX_IMAGE_EDGE
        if (request.id != handle.id || bitmap.width !in 1..minOf(request.maxWidth, maximumEdge) ||
            bitmap.height !in 1..minOf(request.maxHeight, maximumEdge) || bitmap.width.toLong() * bitmap.height > Contract.MAX_IMAGE_OUTPUT_PIXELS) fail(Contract.INVALID_DIMENSIONS)
        val (mime, format) = when (request.format) {
            "jpeg" -> "image/jpeg" to Bitmap.CompressFormat.JPEG
            "png" -> "image/png" to Bitmap.CompressFormat.PNG
            "webp" -> "image/webp" to Bitmap.CompressFormat.WEBP_LOSSY
            else -> fail(Contract.INVALID_FORMAT)
        }
        val transaction = files.beginOutput(outputDirectory, handle, mime)
        var flattened: Bitmap? = null
        try {
            val encoded = if (request.format == "jpeg" && bitmap.hasAlpha()) {
                Bitmap.createBitmap(bitmap.width, bitmap.height, Bitmap.Config.ARGB_8888).also { white ->
                    flattened = white
                    Canvas(white).apply { drawColor(Color.WHITE); drawBitmap(bitmap, 0f, 0f, null) }
                    white.setHasAlpha(false)
                }
            } else bitmap
            transaction.write(cancellation) { stream ->
                val success = try { encoded.compress(format, request.quality ?: 100, stream) }
                    catch (error: NativeMediaOptimizerCancelled) { throw error }
                    catch (error: NativeMediaOptimizerProcessingException) { throw error }
                    catch (_: Exception) { fail(Contract.ENCODE_FAILED) }
                cancellation.check()
                if (!success) fail(Contract.ENCODE_FAILED)
            }
            progress("finalizing", 95); cancellation.check()
            val outputFile = transaction.publish(cancellation)
            val outputMetadata = header(outputFile, outputFile.length(), mime)
            if (outputMetadata.width != bitmap.width || outputMetadata.height != bitmap.height) fail(Contract.OUTPUT_FAILED)
            val output = NativeMediaOptimizerOutput(handle.id, outputFile.path, outputMetadata)
            if (files.verifiedOutput(outputDirectory, output) == null) fail(Contract.OUTPUT_FAILED)
            cancellation.check()
            return NativeMediaOptimizerProcessed(input, output)
        } catch (error: NativeMediaOptimizerCancelled) { transaction.discard(); throw error }
        catch (error: NativeMediaOptimizerProcessingException) { transaction.discard(); throw error }
        catch (_: OutOfMemoryError) { transaction.discard(); fail(Contract.LIMIT_EXCEEDED) }
        catch (_: Exception) { transaction.discard(); fail(Contract.OUTPUT_FAILED) }
        finally { flattened?.recycle() }
    }

    private fun decode(source: NativeMediaOptimizerPreparedSource, input: NativeMediaOptimizerMetadata, width: Int, height: Int): Bitmap = try {
        ImageDecoder.decodeBitmap(ImageDecoder.createSource(source.file)) { decoder, info, _ ->
            if (info.isAnimated || info.mimeType != input.mimeType || info.size.width != input.width || info.size.height != input.height) fail(Contract.SOURCE_UNAVAILABLE)
            decoder.setAllocator(ImageDecoder.ALLOCATOR_SOFTWARE)
            decoder.setTargetColorSpace(ColorSpace.get(ColorSpace.Named.SRGB))
            decoder.setTargetSize(width, height)
            decoder.setOnPartialImageListener { false }
        }.also { bitmap ->
            if (bitmap.width != width || bitmap.height != height) { bitmap.recycle(); fail(Contract.DECODE_FAILED) }
        }
    } catch (error: NativeMediaOptimizerProcessingException) { throw error }
    catch (_: OutOfMemoryError) { fail(Contract.LIMIT_EXCEEDED) }
    catch (_: Exception) { fail(Contract.DECODE_FAILED) }

    // Throw from the header listener to finish inspection before allocating pixel memory.
    private class HeaderComplete : RuntimeException()
    private fun header(file: File, size: Long, expectedMime: String): NativeMediaOptimizerMetadata {
        var metadata: NativeMediaOptimizerMetadata? = null
        try {
            ImageDecoder.decodeBitmap(ImageDecoder.createSource(file)) { _, info, _ ->
                if (info.mimeType !in setOf("image/jpeg", "image/png", "image/webp") || info.mimeType != expectedMime || info.isAnimated) fail(Contract.UNSUPPORTED_MEDIA)
                if (info.size.width !in 1..100000 || info.size.height !in 1..100000 || info.size.width.toLong() * info.size.height > Contract.MAX_SOURCE_PIXELS) fail(Contract.LIMIT_EXCEEDED)
                if (info.mimeType == "image/png") rejectAnimatedPng(file)
                metadata = NativeMediaOptimizerMetadata(info.mimeType, size, info.size.width, info.size.height, null, 0, false)
                throw HeaderComplete()
            }.recycle()
        } catch (_: HeaderComplete) { return metadata ?: fail(Contract.DECODE_FAILED) }
        catch (error: NativeMediaOptimizerProcessingException) { throw error }
        catch (_: OutOfMemoryError) { fail(Contract.LIMIT_EXCEEDED) }
        catch (_: Exception) { fail(Contract.DECODE_FAILED) }
        fail(Contract.DECODE_FAILED)
    }

    private fun rejectAnimatedPng(file: File) {
        RandomAccessFile(file, "r").use { png ->
            if (png.length() < 8) fail(Contract.DECODE_FAILED)
            png.seek(8)
            repeat(4096) {
                if (png.length() - png.filePointer < 12) fail(Contract.DECODE_FAILED)
                val length = png.readInt().toLong() and 0xffffffffL
                val kind = png.readInt()
                if (length + 4 > png.length() - png.filePointer) fail(Contract.DECODE_FAILED)
                if (kind == 0x6163544c) fail(Contract.UNSUPPORTED_MEDIA) // acTL precedes IDAT in APNG.
                if (kind == 0x49444154) return // IDAT
                png.seek(png.filePointer + length + 4)
            }
            fail(Contract.LIMIT_EXCEEDED)
        }
    }

    private fun fail(code: String): Nothing = throw NativeMediaOptimizerProcessingException(code)
}
