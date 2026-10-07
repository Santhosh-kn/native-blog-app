package com.bbs.plugins.native_media_optimizer

import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal sealed interface NativeMediaOptimizerRequestParseResult {
    class Valid(val request: NativeMediaOptimizerRequest) : NativeMediaOptimizerRequestParseResult {
        override fun toString() = "MediaRequest.Valid(redacted)"
    }
    class Invalid(val id: String?, val operation: String?, val code: String) : NativeMediaOptimizerRequestParseResult {
        override fun toString() = "MediaRequest.Invalid(redacted)"
    }
}

internal class NativeMediaOptimizerRequest private constructor(
    val id: String,
    val operation: String,
    val sourceDocumentId: String,
    val maxWidth: Int,
    val maxHeight: Int,
    val format: String,
    val quality: Int?,
    val videoBitrate: Int,
    val audioBitrate: Int,
    val startMs: Long,
    val endMs: Long?,
    val removeAudio: Boolean,
    val timestampMs: Long
) {
    override fun toString() = "NativeMediaOptimizerRequest(redacted)"

    companion object {
        private val keys = mapOf(
            "InspectMedia" to setOf("id", "source_document_id"),
            "OptimizeImage" to setOf("id", "source_document_id", "max_width", "max_height", "format", "quality"),
            "GenerateThumbnail" to setOf("id", "source_document_id", "max_width", "max_height", "format", "quality", "timestamp_ms"),
            "OptimizeVideo" to setOf("id", "source_document_id", "max_width", "max_height", "video_bitrate", "audio_bitrate", "start_ms", "end_ms", "remove_audio")
        )

        fun parse(operation: String, parameters: Map<*, *>): NativeMediaOptimizerRequestParseResult {
            val allowed = keys[operation]
            val id = Contract.requestId(parameters["id"])
            fun invalid(code: String) = NativeMediaOptimizerRequestParseResult.Invalid(id, operation.takeIf { it in Contract.OPERATIONS }, code)
            if (allowed == null || parameters.size > allowed.size) return invalid(Contract.INVALID_OPTIONS)
            val options = parameters.toMap()
            Contract.sizeError(options)?.let { return invalid(it) }
            if (options.keys.any { it !is String || it !in allowed }) return invalid(Contract.INVALID_OPTIONS)
            if (id == null) return invalid(Contract.INVALID_REQUEST_ID)
            val source = Contract.requestId(options["source_document_id"]) ?: return invalid(Contract.INVALID_SOURCE_ID)
            val thumbnail = operation == "GenerateThumbnail"
            val video = operation == "OptimizeVideo"
            val inspection = operation == "InspectMedia"
            fun option(key: String, default: Any): Any? = if (options.containsKey(key)) options[key] else default
            val defaultWidth = if (thumbnail) 512 else 1920
            val defaultHeight = if (thumbnail) 512 else if (video) 1080 else 1920
            val width = Contract.integer(option("max_width", defaultWidth))
            val height = Contract.integer(option("max_height", defaultHeight))
            val maximumEdge = if (thumbnail) Contract.MAX_THUMBNAIL_EDGE else if (video) Contract.MAX_VIDEO_EDGE else Contract.MAX_IMAGE_EDGE
            val minimumEdge = if (video) 16 else 1
            if (!inspection && (width == null || height == null || width !in minimumEdge.toLong()..maximumEdge.toLong() || height !in minimumEdge.toLong()..maximumEdge.toLong() || width * height > (if (video) Contract.MAX_VIDEO_OUTPUT_PIXELS else Contract.MAX_IMAGE_OUTPUT_PIXELS) || (video && (width % 2L != 0L || height % 2L != 0L)))) {
                return invalid(Contract.INVALID_DIMENSIONS)
            }
            val format = option("format", "jpeg") as? String
            if (!video && !inspection && format !in setOf("jpeg", "png", "webp")) return invalid(Contract.INVALID_FORMAT)
            val quality = if (format == "png") null else Contract.integer(option("quality", 80))
            if (!video && !inspection && ((format == "png" && options.containsKey("quality")) || (format != "png" && (quality == null || quality !in 1L..100L)))) return invalid(Contract.INVALID_QUALITY)
            val videoBitrate = Contract.integer(option("video_bitrate", 2500000))
            val audioBitrate = Contract.integer(option("audio_bitrate", 128000))
            if (video && (videoBitrate == null || videoBitrate !in 128000L..20000000L || audioBitrate == null || audioBitrate !in 32000L..320000L)) return invalid(Contract.INVALID_BITRATE)
            val removeAudio = option("remove_audio", false) as? Boolean
            if (video && removeAudio == null) return invalid(Contract.INVALID_OPTIONS)
            val start = Contract.integer(option("start_ms", 0))
            val end = if (options.containsKey("end_ms")) Contract.integer(options["end_ms"]) else null
            if (video && (start == null || start !in 0L until Contract.MAX_DURATION_MS || (options.containsKey("end_ms") && (end == null || end !in 1L..Contract.MAX_DURATION_MS || end <= start)))) return invalid(Contract.INVALID_TIME_RANGE)
            val timestamp = Contract.integer(option("timestamp_ms", 0))
            if (thumbnail && (timestamp == null || timestamp !in 0L until Contract.MAX_DURATION_MS)) return invalid(Contract.INVALID_TIME_RANGE)
            return NativeMediaOptimizerRequestParseResult.Valid(NativeMediaOptimizerRequest(
                id, operation, source,
                (width ?: defaultWidth.toLong()).toInt(), (height ?: defaultHeight.toLong()).toInt(),
                format ?: "jpeg", quality?.toInt(), (videoBitrate ?: 2500000L).toInt(),
                (audioBitrate ?: 128000L).toInt(), start ?: 0L, end, removeAudio ?: false, timestamp ?: 0L
            ))
        }
    }
}
