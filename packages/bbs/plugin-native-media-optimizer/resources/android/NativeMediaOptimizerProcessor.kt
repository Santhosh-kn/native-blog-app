package com.bbs.plugins.native_media_optimizer

import android.content.Context
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal data class NativeMediaOptimizerEngineResult(
    val input: NativeMediaOptimizerMetadata,
    val output: NativeMediaOptimizerOutput? = null
) {
    override fun toString() = "NativeMediaOptimizerEngineResult(redacted)"
}

internal fun interface NativeMediaOptimizerProcessor {
    fun run(
        request: NativeMediaOptimizerRequest,
        source: NativeMediaOptimizerPreparedSource,
        handle: NativeMediaOptimizerHandle,
        cancellation: NativeMediaOptimizerCancellation,
        progress: (String, Int) -> Unit
    ): NativeMediaOptimizerEngineResult
}

/** The only engine router. It retains application context, never an activity. */
internal class NativeMediaOptimizerEngines(context: Context, private val files: NativeMediaOptimizerFiles) : NativeMediaOptimizerProcessor {
    private val images = NativeMediaOptimizerImageEngine(files)
    private val inspection = NativeMediaOptimizerVideoInspection(files)
    private val video = NativeMediaOptimizerVideoEngine(context.applicationContext, files, inspection)
    private val thumbnails = NativeMediaOptimizerThumbnailEngine(files, inspection, images)

    override fun run(
        request: NativeMediaOptimizerRequest,
        source: NativeMediaOptimizerPreparedSource,
        handle: NativeMediaOptimizerHandle,
        cancellation: NativeMediaOptimizerCancellation,
        progress: (String, Int) -> Unit
    ): NativeMediaOptimizerEngineResult {
        requireMediaWorker(); cancellation.check(); files.verifySource(source)
        if (request.id != handle.id || request.sourceDocumentId != source.documentId) {
            throw NativeMediaOptimizerProcessingException(Contract.INVALID_OPTIONS)
        }
        if (request.operation == "InspectMedia") {
            progress("inspecting", 5)
            val metadata = if (source.declaredMimeType.startsWith("image/")) images.inspect(source, cancellation)
                else inspection.inspect(source, cancellation).metadata
            cancellation.check(); files.verifySource(source)
            return NativeMediaOptimizerEngineResult(metadata)
        }
        val processed = when (request.operation) {
            "OptimizeImage" -> images.optimize(request, source, handle, cancellation, progress)
            "OptimizeVideo" -> video.optimize(request, source, handle, cancellation, progress)
            "GenerateThumbnail" -> thumbnails.generate(request, source, handle, cancellation, progress)
            else -> throw NativeMediaOptimizerProcessingException(Contract.INVALID_OPTIONS)
        }
        return NativeMediaOptimizerEngineResult(processed.input, processed.output)
    }
}
