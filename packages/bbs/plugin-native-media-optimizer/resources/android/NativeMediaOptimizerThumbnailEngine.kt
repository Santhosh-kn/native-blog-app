package com.bbs.plugins.native_media_optimizer

import android.graphics.Bitmap
import android.media.MediaMetadataRetriever
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

/** Closest decoded frame, already oriented by Android, encoded through the private image writer. */
internal class NativeMediaOptimizerThumbnailEngine(
    private val files: NativeMediaOptimizerFiles,
    private val inspection: NativeMediaOptimizerVideoInspection,
    private val images: NativeMediaOptimizerImageEngine
) {
    fun generate(
        request: NativeMediaOptimizerRequest,
        source: NativeMediaOptimizerPreparedSource,
        handle: NativeMediaOptimizerHandle,
        cancellation: NativeMediaOptimizerCancellation,
        progress: (String, Int) -> Unit = { _, _ -> }
    ): NativeMediaOptimizerProcessed {
        requireMediaWorker(); cancellation.check()
        if (request.operation != "GenerateThumbnail" || request.id != handle.id || request.sourceDocumentId != source.documentId) fail(Contract.INVALID_OPTIONS)
        progress("inspecting", 5)
        val info = inspection.inspect(source, cancellation)
        val input = info.metadata
        if (request.timestampMs >= input.durationMs!!) fail(Contract.INVALID_TIME_RANGE)
        info.requireFrameDecoder()
        val (width, height) = NativeMediaOptimizerGeometry.fit(info.displayWidth, info.displayHeight, request.maxWidth, request.maxHeight)
        // Some Android implementations decode a full frame before scaling in JNI.
        // Bound both source-frame area and the heap estimate; no unscaled fallback.
        if (input.width.toLong() * input.height > 16777216L) fail(Contract.LIMIT_EXCEEDED)
        NativeMediaOptimizerGeometry.checkImageMemory(input.width, input.height, width, height)
        progress("decoding", 20); cancellation.check(); files.verifySource(source)
        var frame: Bitmap? = null
        try {
            frame = withVideoRetriever(source.file, source.size) { retriever ->
                val params = MediaMetadataRetriever.BitmapParams().apply { preferredConfig = Bitmap.Config.ARGB_8888 }
                retriever.getScaledFrameAtTime(request.timestampMs * 1000L, MediaMetadataRetriever.OPTION_CLOSEST, width, height, params)
            } ?: fail(Contract.DECODE_FAILED)
            cancellation.check(); files.verifySource(source)
            // Account for platform rounding while preserving aspect ratio and keeping the requested box.
            if (frame.width > width || frame.height > height) {
                val (scaledWidth, scaledHeight) = NativeMediaOptimizerGeometry.fit(frame.width, frame.height, width, height)
                val scaled = Bitmap.createScaledBitmap(frame, scaledWidth, scaledHeight, true)
                if (scaled !== frame) { frame.recycle(); frame = scaled }
            }
            if (frame.width < 1 || frame.height < 1 || frame.width > width || frame.height > height) fail(Contract.DECODE_FAILED)
            progress("encoding", 60); cancellation.check()
            return images.encode(request, source.outputDirectory.path, handle, input, frame, cancellation, progress)
        } catch (error: NativeMediaOptimizerCancelled) { throw error }
        catch (error: NativeMediaOptimizerProcessingException) { throw error }
        catch (_: OutOfMemoryError) { fail(Contract.LIMIT_EXCEEDED) }
        catch (_: Exception) { fail(Contract.DECODE_FAILED) }
        finally { frame?.recycle() }
    }

    private fun fail(code: String): Nothing = throw NativeMediaOptimizerProcessingException(code)
}
