package com.bbs.plugins.native_media_optimizer

import org.json.JSONObject
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal data class NativeMediaOptimizerMetadata(
    val mimeType: String,
    val size: Long,
    val width: Int,
    val height: Int,
    val durationMs: Long?,
    val rotationDegrees: Int,
    val hasAudio: Boolean
) {
    init { require(valid()) { "Invalid media metadata." } }
    override fun toString() = "NativeMediaOptimizerMetadata(redacted)"
    val isImage get() = mimeType.startsWith("image/")

    private fun valid(): Boolean =
        mimeType in MIME_TYPES && size in 1L..Contract.MAX_INPUT_BYTES &&
            width in 1..100000 && height in 1..100000 && width.toLong() * height <= Contract.MAX_SOURCE_PIXELS &&
            rotationDegrees in setOf(0, 90, 180, 270) &&
            (if (mimeType.startsWith("image/")) durationMs == null && !hasAudio
            else durationMs != null && durationMs in 1L..Contract.MAX_DURATION_MS)

    fun toMap(): Map<String, Any> = linkedMapOf(
        "mime_type" to mimeType, "size" to size, "width" to width, "height" to height,
        "duration_ms" to (durationMs ?: JSONObject.NULL), "rotation_degrees" to rotationDegrees, "has_audio" to hasAudio
    )

    companion object {
        val MIME_TYPES = setOf("image/jpeg", "image/png", "image/webp", "video/mp4", "video/webm", "video/quicktime")
        private val KEYS = setOf("mime_type", "size", "width", "height", "duration_ms", "rotation_degrees", "has_audio")
        fun fromJson(value: Any?): NativeMediaOptimizerMetadata? {
            val json = value as? JSONObject ?: return null
            return try {
                if (json.keys().asSequence().toSet() != KEYS) return null
                val mime = json.get("mime_type") as? String ?: return null
                val size = Contract.integer(json.get("size")) ?: return null
                val width = Contract.integer(json.get("width"))?.takeIf { it in 1L..100000L } ?: return null
                val height = Contract.integer(json.get("height"))?.takeIf { it in 1L..100000L } ?: return null
                val duration = if (json.get("duration_ms") === JSONObject.NULL) null else Contract.integer(json.get("duration_ms")) ?: return null
                val rotation = Contract.integer(json.get("rotation_degrees"))?.takeIf { it in 0L..270L } ?: return null
                val audio = json.get("has_audio") as? Boolean ?: return null
                NativeMediaOptimizerMetadata(mime, size, width.toInt(), height.toInt(), duration, rotation.toInt(), audio)
            } catch (_: Exception) { null }
        }
    }
}

internal data class NativeMediaOptimizerOutput(
    val id: String,
    val path: String,
    val metadata: NativeMediaOptimizerMetadata
) {
    init {
        require(Contract.requestId(id) == id && path.length in 1..4096 && path.none { it.code < 32 || it.code == 127 }) { "Invalid output metadata." }
        require(!metadata.isImage || metadata.rotationDegrees == 0) { "Invalid output orientation." }
        val extension = EXTENSIONS[metadata.mimeType]
        require(extension != null && path.replace('\\', '/').endsWith("/native-media-optimizer/$id.$extension")) { "Invalid output reference." }
    }
    override fun toString() = "NativeMediaOptimizerOutput(redacted)"
    fun toMap(): Map<String, Any> = linkedMapOf("id" to id, "path" to path, *metadata.toMap().entries.map { it.key to it.value }.toTypedArray())

    companion object {
        val EXTENSIONS = mapOf("image/jpeg" to "jpg", "image/png" to "png", "image/webp" to "webp", "video/mp4" to "mp4")
        fun fromJson(value: Any?): NativeMediaOptimizerOutput? {
            val json = value as? JSONObject ?: return null
            return try {
                if (json.length() != 9) return null
                val id = json.get("id") as? String ?: return null
                val path = json.get("path") as? String ?: return null
                val metadataJson = JSONObject(json.toString()).apply { remove("id"); remove("path") }
                NativeMediaOptimizerOutput(id, path, NativeMediaOptimizerMetadata.fromJson(metadataJson) ?: return null)
            } catch (_: Exception) { null }
        }
    }
}
