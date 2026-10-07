package com.bbs.plugins.native_media_optimizer

import android.content.Context
import android.media.MediaFormat
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.os.Handler
import android.os.HandlerThread
import android.os.SystemClock
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.util.UnstableApi
import androidx.media3.effect.Presentation
import androidx.media3.transformer.AudioEncoderSettings
import androidx.media3.transformer.Composition
import androidx.media3.transformer.DefaultEncoderFactory
import androidx.media3.transformer.EditedMediaItem
import androidx.media3.transformer.Effects
import androidx.media3.transformer.ExportException
import androidx.media3.transformer.ExportResult
import androidx.media3.transformer.ProgressHolder
import androidx.media3.transformer.Transformer
import androidx.media3.transformer.VideoEncoderSettings
import java.io.File
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

/** SDR, square-pixel video to H.264/AAC MP4. Activity-independent, with one export looper. */
@androidx.annotation.OptIn(markerClass = [UnstableApi::class])
internal class NativeMediaOptimizerVideoEngine(
    context: Context,
    private val files: NativeMediaOptimizerFiles,
    private val inspection: NativeMediaOptimizerVideoInspection
) {
    private val appContext = context.applicationContext

    fun optimize(
        request: NativeMediaOptimizerRequest,
        source: NativeMediaOptimizerPreparedSource,
        handle: NativeMediaOptimizerHandle,
        cancellation: NativeMediaOptimizerCancellation,
        progress: (String, Int) -> Unit = { _, _ -> }
    ): NativeMediaOptimizerProcessed {
        requireMediaWorker(); cancellation.check()
        if (request.operation != "OptimizeVideo" || request.id != handle.id || request.sourceDocumentId != source.documentId) fail(Contract.INVALID_OPTIONS)
        progress("inspecting", 5)
        val info = inspection.inspect(source, cancellation)
        info.requireFrameDecoder()
        val duration = info.metadata.durationMs!!
        val end = request.endMs ?: duration
        if (request.startMs >= duration || end > duration || end <= request.startMs) fail(Contract.INVALID_TIME_RANGE)
        val (fittedWidth, fittedHeight) = NativeMediaOptimizerGeometry.fit(info.displayWidth, info.displayHeight, request.maxWidth, request.maxHeight)
        // H.264 requires even dimensions. Round down; do not enlarge small inputs.
        val width = fittedWidth - fittedWidth % 2
        val height = fittedHeight - fittedHeight % 2
        if (width < 16 || height < 16) fail(Contract.INVALID_DIMENSIONS)
        if (info.metadata.width.toLong() * info.metadata.height > 16777216L) fail(Contract.LIMIT_EXCEEDED)
        val keepAudio = info.metadata.hasAudio && !request.removeAudio
        checkSpace(source.outputDirectory, end - request.startMs, request.videoBitrate, if (keepAudio) request.audioBitrate else 0)
        cancellation.check(); files.verifySource(source)
        val transaction = files.beginOutput(source.outputDirectory.path, handle, "video/mp4")
        try {
            val partial = transaction.prepareForEncoder(cancellation)
            val exported = export(request, source, transaction, partial, width, height, cancellation, progress)
            cancellation.check(); files.verifySource(source)
            if (exported.videoFrameCount < 1 || exported.videoMimeType != MimeTypes.VIDEO_H264 || exported.videoEncoderName == null ||
                exported.videoConversionProcess != ExportResult.CONVERSION_PROCESS_TRANSCODED ||
                (keepAudio && (exported.audioMimeType != MimeTypes.AUDIO_AAC || exported.audioEncoderName == null || exported.audioConversionProcess != ExportResult.CONVERSION_PROCESS_TRANSCODED))) fail(Contract.ENCODE_FAILED)
            transaction.finishEncoderOutput(cancellation)
            val outputInfo = inspection.inspectFile(partial, partial.length(), "video/mp4", cancellation)
            verifyOutput(outputInfo, width, height, end - request.startMs, keepAudio)
            // Verify that the newly encoded video actually contains a decodable frame.
            withVideoRetriever(partial, partial.length()) { retriever ->
                val bitmap = retriever.getScaledFrameAtTime(0, MediaMetadataRetriever.OPTION_CLOSEST, 32, 32) ?: fail(Contract.OUTPUT_FAILED)
                bitmap.recycle()
            }
            progress("finalizing", 95); cancellation.check(); files.verifySource(source)
            val outputFile = transaction.publish(cancellation)
            val output = NativeMediaOptimizerOutput(handle.id, outputFile.path, outputInfo.metadata)
            if (files.verifiedOutput(source.outputDirectory.path, output) == null) fail(Contract.OUTPUT_FAILED)
            cancellation.check()
            return NativeMediaOptimizerProcessed(info.metadata, output)
        } catch (error: NativeMediaOptimizerCancelled) { transaction.discard(); throw error }
        catch (error: NativeMediaOptimizerProcessingException) {
            transaction.discard()
            cancellation.check(); files.verifySource(source)
            throw error
        }
        catch (_: OutOfMemoryError) { transaction.discard(); fail(Contract.LIMIT_EXCEEDED) }
        catch (_: Exception) {
            transaction.discard()
            cancellation.check(); files.verifySource(source)
            fail(Contract.ENCODE_FAILED)
        }
    }

    private fun checkSpace(directory: File, durationMs: Long, videoBitrate: Int, audioBitrate: Int) {
        // Conservative planning estimate, not a promise of final size or savings.
        val estimate = durationMs * (videoBitrate.toLong() + audioBitrate) / 8000L * 6L / 5L + 8L * 1024 * 1024
        if (estimate > Contract.MAX_INPUT_BYTES || directory.usableSpace < estimate + 32L * 1024 * 1024) fail(Contract.LIMIT_EXCEEDED)
    }

    private fun verifyOutput(info: NativeMediaOptimizerVideoInfo, width: Int, height: Int, durationMs: Long, keepAudio: Boolean) {
        if (info.displayWidth != width || info.displayHeight != height || info.metadata.rotationDegrees != 0 || info.metadata.hasAudio != keepAudio ||
            info.videoFormat.getString(MediaFormat.KEY_MIME) != MimeTypes.VIDEO_H264 ||
            (keepAudio && info.audioFormat?.getString(MediaFormat.KEY_MIME) != MimeTypes.AUDIO_AAC) || info.encrypted || info.hdr || !info.squarePixels) fail(Contract.OUTPUT_FAILED)
        // Container duration has frame/audio-packet granularity, not arbitrary millisecond precision.
        val frameRate = if (info.videoFormat.containsKey(MediaFormat.KEY_FRAME_RATE)) {
            try { info.videoFormat.getInteger(MediaFormat.KEY_FRAME_RATE).toDouble() } catch (_: Exception) { 0.0 }
        } else 0.0
        val tolerance = if (frameRate > 0.0) maxOf(100L, kotlin.math.ceil(2000.0 / frameRate).toLong()).coerceAtMost(1000L) else 1000L
        if (kotlin.math.abs(info.metadata.durationMs!! - durationMs) > tolerance) fail(Contract.OUTPUT_FAILED)
    }

    /** Synchronous to the worker caller; Transformer itself and its callbacks use a separate looper. */
    private fun export(
        request: NativeMediaOptimizerRequest,
        source: NativeMediaOptimizerPreparedSource,
        transaction: NativeMediaOptimizerOutputTransaction,
        partial: File,
        width: Int,
        height: Int,
        cancellation: NativeMediaOptimizerCancellation,
        progress: (String, Int) -> Unit
    ): ExportResult {
        requireMediaWorker()
        val thread = HandlerThread("bbs-media-video-export").apply { start() }
        val handler = Handler(thread.looper)
        val finished = CountDownLatch(1)
        val disposed = CountDownLatch(1)
        val result = AtomicReference<ExportResult?>()
        val failure = AtomicReference<Throwable?>()
        val cleanupFailure = AtomicReference<Throwable?>()
        var transformer: Transformer? = null // Only accessed on this handler's thread.
        var terminal = false
        var lastProgress = 10
        val startedAt = SystemClock.elapsedRealtime()
        fun finish(error: Throwable?, exported: ExportResult? = null) {
            if (terminal) return
            terminal = true; failure.set(error); result.set(exported); finished.countDown()
        }
        val polling = object : Runnable {
            override fun run() {
                if (terminal) return
                try {
                    cancellation.check(); transaction.checkEncoderOutput(cancellation); files.verifySource(source)
                    if (SystemClock.elapsedRealtime() - startedAt > 7200000L) fail(Contract.ENCODE_FAILED)
                    val holder = ProgressHolder()
                    if (transformer!!.getProgress(holder) == Transformer.PROGRESS_STATE_AVAILABLE) {
                        lastProgress = maxOf(lastProgress, 10 + holder.progress.coerceIn(0, 100) * 80 / 100)
                    }
                    progress("encoding", lastProgress); cancellation.check()
                    handler.postDelayed(this, 250L)
                } catch (error: NativeMediaOptimizerCancelled) { finish(error) }
                catch (error: NativeMediaOptimizerProcessingException) { finish(error) }
                catch (_: OutOfMemoryError) { finish(NativeMediaOptimizerProcessingException(Contract.LIMIT_EXCEEDED)) }
                catch (_: Exception) { finish(NativeMediaOptimizerProcessingException(Contract.ENCODE_FAILED)) }
            }
        }
        try {
            check(handler.post {
                try {
                    cancellation.check(); files.verifySource(source); transaction.checkEncoderOutput(cancellation)
                    val clipping = MediaItem.ClippingConfiguration.Builder().setStartPositionMs(request.startMs).apply {
                        request.endMs?.let { setEndPositionMs(it) }
                    }.build()
                    val item = MediaItem.Builder().setUri(Uri.fromFile(source.file)).setClippingConfiguration(clipping).build()
                    val edited = EditedMediaItem.Builder(item).setRemoveAudio(request.removeAudio)
                        .setEffects(Effects(emptyList(), listOf(Presentation.createForWidthAndHeight(width, height, Presentation.LAYOUT_SCALE_TO_FIT))))
                        .build()
                    // Non-default bitrate settings force actual encoding even if dimensions match.
                    val encoder = DefaultEncoderFactory.Builder(appContext).setEnableFallback(false)
                        .setRequestedVideoEncoderSettings(VideoEncoderSettings.Builder().setBitrate(request.videoBitrate).build())
                        .setRequestedAudioEncoderSettings(AudioEncoderSettings.Builder().setBitrate(request.audioBitrate).build())
                        .build()
                    transformer = Transformer.Builder(appContext).setLooper(thread.looper).setEncoderFactory(encoder)
                        .setVideoMimeType(MimeTypes.VIDEO_H264).setAudioMimeType(MimeTypes.AUDIO_AAC)
                        .setUsePlatformDiagnostics(false)
                        .setPortraitEncodingEnabled(true)
                        .experimentalSetTrimOptimizationEnabled(false).experimentalSetMp4EditListTrimEnabled(false)
                        .setMaxDelayBetweenMuxerSamplesMs(30000L)
                        .addListener(object : Transformer.Listener {
                            override fun onCompleted(composition: Composition, exportResult: ExportResult) { finish(null, exportResult) }
                            override fun onError(composition: Composition, exportResult: ExportResult, exception: ExportException) {
                                finish(NativeMediaOptimizerProcessingException(exportError(exception)))
                            }
                        }).build()
                    transformer!!.start(edited, partial.path)
                    handler.post(polling)
                } catch (error: NativeMediaOptimizerCancelled) { finish(error) }
                catch (error: NativeMediaOptimizerProcessingException) { finish(error) }
                catch (_: OutOfMemoryError) { finish(NativeMediaOptimizerProcessingException(Contract.LIMIT_EXCEEDED)) }
                catch (_: Exception) { finish(NativeMediaOptimizerProcessingException(Contract.ENCODE_FAILED)) }
            })
            while (!finished.await(250L, TimeUnit.MILLISECONDS)) {
                try { cancellation.check() } catch (error: NativeMediaOptimizerCancelled) { throw error }
            }
            cancellation.check()
            failure.get()?.let { throw it }
            return result.get() ?: fail(Contract.ENCODE_FAILED)
        } catch (_: InterruptedException) {
            cancellation.cancel()
            Thread.currentThread().interrupt()
            throw NativeMediaOptimizerCancelled()
        } finally {
            // Wait until cancel/release returns before a caller can discard the muxer's file.
            val restoreInterrupt = Thread.interrupted()
            check(handler.post {
                try { terminal = true; handler.removeCallbacksAndMessages(null); transformer?.cancel() }
                catch (_: Exception) { cleanupFailure.set(NativeMediaOptimizerProcessingException(Contract.ENCODE_FAILED)) }
                catch (_: OutOfMemoryError) { cleanupFailure.set(NativeMediaOptimizerProcessingException(Contract.LIMIT_EXCEEDED)) }
                finally { disposed.countDown(); thread.quitSafely() }
            })
            var interrupted = restoreInterrupt
            while (true) {
                try { disposed.await(); break } catch (_: InterruptedException) { interrupted = true }
            }
            if (interrupted) Thread.currentThread().interrupt()
            cleanupFailure.get()?.let { throw it }
        }
    }

    private fun exportError(exception: ExportException): String = when (exception.errorCode) {
        ExportException.ERROR_CODE_DECODING_FORMAT_UNSUPPORTED, ExportException.ERROR_CODE_ENCODING_FORMAT_UNSUPPORTED -> Contract.UNSUPPORTED_MEDIA
        ExportException.ERROR_CODE_DECODER_INIT_FAILED, ExportException.ERROR_CODE_DECODING_FAILED -> Contract.DECODE_FAILED
        ExportException.ERROR_CODE_MUXING_FAILED, ExportException.ERROR_CODE_MUXING_TIMEOUT -> Contract.OUTPUT_FAILED
        ExportException.ERROR_CODE_IO_FILE_NOT_FOUND, ExportException.ERROR_CODE_IO_NO_PERMISSION -> Contract.SOURCE_UNAVAILABLE
        else -> Contract.ENCODE_FAILED
    }

    private fun fail(code: String): Nothing = throw NativeMediaOptimizerProcessingException(code)
}
