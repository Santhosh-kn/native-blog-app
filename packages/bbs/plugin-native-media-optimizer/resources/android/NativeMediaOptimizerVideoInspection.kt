package com.bbs.plugins.native_media_optimizer

import android.media.MediaCodecList
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMetadataRetriever
import android.system.Os
import android.system.OsConstants
import java.io.DataInputStream
import java.io.File
import java.io.FileInputStream
import java.nio.ByteBuffer
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal data class NativeMediaOptimizerVideoInfo(
    val metadata: NativeMediaOptimizerMetadata,
    val videoFormat: MediaFormat,
    val audioFormat: MediaFormat?,
    val encrypted: Boolean,
    val hdr: Boolean,
    val squarePixels: Boolean
) {
    val displayWidth get() = if (metadata.rotationDegrees in setOf(90, 270)) metadata.height else metadata.width
    val displayHeight get() = if (metadata.rotationDegrees in setOf(90, 270)) metadata.width else metadata.height
    override fun toString() = "NativeMediaOptimizerVideoInfo(redacted)"

    fun requireFrameDecoder() {
        if (encrypted || hdr || !squarePixels) throw NativeMediaOptimizerProcessingException(Contract.UNSUPPORTED_MEDIA)
        val decoder = try { MediaCodecList(MediaCodecList.REGULAR_CODECS).findDecoderForFormat(videoFormat) } catch (_: Exception) { null }
        if (decoder == null) throw NativeMediaOptimizerProcessingException(Contract.UNSUPPORTED_MEDIA)
    }
}

/** Bounded container checks plus Android metadata/track inspection; never decodes a video frame. */
internal class NativeMediaOptimizerVideoInspection(private val files: NativeMediaOptimizerFiles) {
    fun inspect(source: NativeMediaOptimizerPreparedSource, cancellation: NativeMediaOptimizerCancellation): NativeMediaOptimizerVideoInfo {
        requireMediaWorker(); cancellation.check(); files.verifySource(source)
        if (!source.declaredMimeType.startsWith("video/")) fail(Contract.UNSUPPORTED_MEDIA)
        val info = inspectFile(source.file, source.size, source.declaredMimeType, cancellation)
        cancellation.check(); files.verifySource(source)
        return info
    }

    /** For the transcoder's own controlled output transaction, before completion is persisted. */
    fun inspectFile(file: File, size: Long, expectedMime: String, cancellation: NativeMediaOptimizerCancellation): NativeMediaOptimizerVideoInfo {
        requireMediaWorker(); cancellation.check()
        if (size !in 1L..Contract.MAX_INPUT_BYTES || !file.isFile || file.length() != size) fail(Contract.SOURCE_UNAVAILABLE)
        try {
            val mime = containerMime(file, size)
            if (mime != expectedMime) fail(Contract.UNSUPPORTED_MEDIA)
            val extractor = MediaExtractor()
            try {
                withDescriptor(file, size) { descriptor -> extractor.setDataSource(descriptor, 0, size) }
                if (extractor.trackCount !in 1..16) fail(Contract.LIMIT_EXCEEDED)
                val videos = mutableListOf<Pair<Int, MediaFormat>>()
                val audios = mutableListOf<MediaFormat>()
                for (index in 0 until extractor.trackCount) {
                    cancellation.check()
                    val format = extractor.getTrackFormat(index)
                    val trackMime = format.getString(MediaFormat.KEY_MIME) ?: fail(Contract.DECODE_FAILED)
                    if (trackMime.startsWith("video/")) videos.add(index to format)
                    if (trackMime.startsWith("audio/")) audios.add(format)
                }
                if (videos.size != 1 || audios.size > 1) fail(Contract.UNSUPPORTED_MEDIA)
                val (videoIndex, videoFormat) = videos.single()
                extractor.selectTrack(videoIndex)
                if (extractor.sampleTime < 0) fail(Contract.DECODE_FAILED)
                val encrypted = !extractor.psshInfo.isNullOrEmpty() || (extractor.sampleFlags and MediaExtractor.SAMPLE_FLAG_ENCRYPTED) != 0
                return withVideoRetriever(file, size) { retriever ->
                    cancellation.check()
                    if (retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_HAS_VIDEO) != "yes") fail(Contract.UNSUPPORTED_MEDIA)
                    val width = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)?.toIntOrNull() ?: fail(Contract.DECODE_FAILED)
                    val height = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)?.toIntOrNull() ?: fail(Contract.DECODE_FAILED)
                    val duration = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull() ?: fail(Contract.DECODE_FAILED)
                    val rawRotation = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_ROTATION)
                    val rotation = if (rawRotation == null) 0 else rawRotation.toIntOrNull() ?: fail(Contract.DECODE_FAILED)
                    if (width !in 1..100000 || height !in 1..100000 || width.toLong() * height > Contract.MAX_SOURCE_PIXELS || duration !in 1L..Contract.MAX_DURATION_MS) fail(Contract.LIMIT_EXCEEDED)
                    if (rotation !in setOf(0, 90, 180, 270)) fail(Contract.UNSUPPORTED_MEDIA)
                    val hasAudio = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_HAS_AUDIO) == "yes"
                    if (hasAudio != audios.isNotEmpty()) fail(Contract.DECODE_FAILED)
                    val transfer = if (videoFormat.containsKey(MediaFormat.KEY_COLOR_TRANSFER)) videoFormat.getInteger(MediaFormat.KEY_COLOR_TRANSFER) else 0
                    val sarWidth = if (videoFormat.containsKey("sar-width")) videoFormat.getInteger("sar-width") else 1
                    val sarHeight = if (videoFormat.containsKey("sar-height")) videoFormat.getInteger("sar-height") else 1
                    cancellation.check()
                    NativeMediaOptimizerVideoInfo(
                        NativeMediaOptimizerMetadata(mime, size, width, height, duration, rotation, hasAudio),
                        videoFormat, audios.singleOrNull(), encrypted,
                        transfer == MediaFormat.COLOR_TRANSFER_ST2084 || transfer == MediaFormat.COLOR_TRANSFER_HLG,
                        sarWidth > 0 && sarWidth == sarHeight
                    )
                }
            } finally { extractor.release() }
        } catch (error: NativeMediaOptimizerCancelled) { throw error }
        catch (error: NativeMediaOptimizerProcessingException) { throw error }
        catch (_: OutOfMemoryError) { fail(Contract.LIMIT_EXCEEDED) }
        catch (_: Exception) { fail(Contract.DECODE_FAILED) }
    }

    private fun containerMime(file: File, size: Long): String {
        val bytes = ByteArray(minOf(size, 65536L).toInt())
        withDescriptor(file, size) { descriptor ->
            FileInputStream(descriptor).use { stream -> DataInputStream(stream).readFully(bytes) }
        }
        if (bytes.size < 8) fail(Contract.DECODE_FAILED)
        val buffer = ByteBuffer.wrap(bytes)
        if (buffer.getInt(0) == 0x1a45dfa3) return webmMime(bytes)
        var offset = 0
        repeat(32) {
            if (bytes.size - offset < 8) fail(Contract.UNSUPPORTED_MEDIA)
            val length32 = buffer.getInt(offset).toLong() and 0xffffffffL
            val kind = buffer.getInt(offset + 4)
            val headerSize = if (length32 == 1L) 16 else 8
            if (bytes.size - offset < headerSize) fail(Contract.DECODE_FAILED)
            val length = if (length32 == 1L) buffer.getLong(offset + 8) else length32
            if (length < headerSize || length > size - offset) fail(Contract.DECODE_FAILED)
            if (kind == 0x66747970) { // ftyp
                if (length < headerSize + 8 || length > bytes.size - offset) fail(Contract.DECODE_FAILED)
                return when (buffer.getInt(offset + headerSize)) {
                    0x71742020 -> "video/quicktime" // qt
                    0x69736f6d, 0x69736f32, 0x69736f33, 0x69736f34, 0x69736f35, 0x69736f36,
                    0x6d703431, 0x6d703432, 0x61766331, 0x4d345620, 0x4d534e56, 0x64617368, 0x636d6663, 0x636d6673 -> "video/mp4"
                    else -> fail(Contract.UNSUPPORTED_MEDIA)
                }
            }
            if (kind !in setOf(0x66726565, 0x736b6970, 0x77696465) || length > bytes.size - offset) fail(Contract.UNSUPPORTED_MEDIA)
            offset += length.toInt()
        }
        fail(Contract.LIMIT_EXCEEDED)
    }

    private fun webmMime(bytes: ByteArray): String {
        fun vint(start: Int, stripMarker: Boolean): Pair<Long, Int> {
            if (start !in bytes.indices) fail(Contract.DECODE_FAILED)
            val first = bytes[start].toInt() and 255
            var marker = 128; var length = 1
            while (length <= 8 && (first and marker) == 0) { marker = marker shr 1; length++ }
            if (length > (if (stripMarker) 8 else 4) || start + length > bytes.size) fail(Contract.DECODE_FAILED)
            var value = (if (stripMarker) first and (marker - 1) else first).toLong()
            for (index in 1 until length) value = (value shl 8) or (bytes[start + index].toLong() and 255)
            return value to length
        }
        val (headerLength, sizeBytes) = vint(4, true)
        val headerStart = 4 + sizeBytes
        if (headerLength !in 1L..(bytes.size - headerStart).toLong()) fail(Contract.DECODE_FAILED)
        val end = headerStart + headerLength.toInt()
        var offset = headerStart
        repeat(64) {
            if (offset >= end) fail(Contract.UNSUPPORTED_MEDIA)
            val (id, idLength) = vint(offset, false)
            val (length, lengthBytes) = vint(offset + idLength, true)
            val start = offset + idLength + lengthBytes
            if (length < 0 || length > end - start) fail(Contract.DECODE_FAILED)
            if (id == 0x4282L) {
                if (length == 4L && bytes.copyOfRange(start, start + 4).contentEquals(byteArrayOf(119, 101, 98, 109))) return "video/webm"
                fail(Contract.UNSUPPORTED_MEDIA)
            }
            offset = start + length.toInt()
        }
        fail(Contract.LIMIT_EXCEEDED)
    }

    private fun fail(code: String): Nothing = throw NativeMediaOptimizerProcessingException(code)
}

internal fun <T> withVideoRetriever(file: File, size: Long, action: (MediaMetadataRetriever) -> T): T {
    requireMediaWorker()
    val retriever = MediaMetadataRetriever()
    try {
        withDescriptor(file, size) { descriptor -> retriever.setDataSource(descriptor, 0, size) }
        return action(retriever)
    } finally { retriever.release() }
}

private fun <T> withDescriptor(file: File, size: Long, action: (java.io.FileDescriptor) -> T): T {
    val descriptor = Os.open(file.path, OsConstants.O_RDONLY or OsConstants.O_NOFOLLOW, 0)
    try {
        if (Os.fstat(descriptor).st_size != size) throw NativeMediaOptimizerProcessingException(Contract.SOURCE_UNAVAILABLE)
        return action(descriptor)
    } finally { if (descriptor.valid()) Os.close(descriptor) }
}
