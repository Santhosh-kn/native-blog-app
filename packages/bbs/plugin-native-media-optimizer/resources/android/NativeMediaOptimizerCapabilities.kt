package com.bbs.plugins.native_media_optimizer

import android.media.MediaCodecList
import android.os.Build

/** Device codec presence is a capability hint; each actual input is validated. */
internal object NativeMediaOptimizerCapabilities {
    fun read(): Map<String, Any> {
        requireMediaWorker()
        if (Build.VERSION.SDK_INT < 33) return unavailable()
        val codecs = try { MediaCodecList(MediaCodecList.REGULAR_CODECS).codecInfos.toList() }
            catch (_: Exception) { emptyList() }
        fun codec(mime: String, encoder: Boolean) = codecs.any { info ->
            info.isEncoder == encoder && info.supportedTypes.any { it.equals(mime, ignoreCase = true) }
        }
        val video = codec("video/avc", true) && codec("audio/mp4a-latm", true) && codec("video/avc", false)
        return response(true, video, codec("video/avc", false))
    }

    fun unavailable(): Map<String, Any> = response(false, false, false)
    private fun response(images: Boolean, video: Boolean, thumbnails: Boolean): Map<String, Any> = linkedMapOf(
        "platform" to "android", "available" to (images || video || thumbnails),
        "images" to images, "video" to video, "thumbnails" to thumbnails
    )
}
