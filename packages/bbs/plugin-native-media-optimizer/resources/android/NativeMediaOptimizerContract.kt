package com.bbs.plugins.native_media_optimizer

internal object NativeMediaOptimizerContract {
    const val MIN_API_LEVEL = 33
    const val MAX_REQUEST_BYTES = 8192
    const val MAX_RESPONSE_BYTES = 16384
    const val MAX_INPUT_BYTES = 536870912L
    const val MAX_IMAGE_INPUT_BYTES = 104857600L
    const val MAX_SOURCE_PIXELS = 100000000L
    const val MAX_IMAGE_EDGE = 4096
    const val MAX_IMAGE_OUTPUT_PIXELS = 16777216L
    const val MAX_THUMBNAIL_EDGE = 2048
    const val MAX_VIDEO_EDGE = 1920
    const val MAX_VIDEO_OUTPUT_PIXELS = 2073600L
    const val MAX_DURATION_MS = 3600000L
    const val MAX_RECORDS = 50
    const val MAX_STATE_BYTES = 1048576
    val OPERATIONS = setOf("InspectMedia", "OptimizeImage", "OptimizeVideo", "GenerateThumbnail")
    val ACTIVE_STATES = setOf("pending", "running", "cancelling")
    val TERMINAL_STATES = setOf("succeeded", "failed", "cancelled", "interrupted")
    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val INVALID_SOURCE_ID = "INVALID_SOURCE_ID"
    const val INVALID_OPTIONS = "INVALID_OPTIONS"
    const val REQUEST_TOO_LARGE = "REQUEST_TOO_LARGE"
    const val INVALID_DIMENSIONS = "INVALID_DIMENSIONS"
    const val INVALID_QUALITY = "INVALID_QUALITY"
    const val INVALID_FORMAT = "INVALID_FORMAT"
    const val INVALID_BITRATE = "INVALID_BITRATE"
    const val INVALID_TIME_RANGE = "INVALID_TIME_RANGE"
    const val NATIVE_UNAVAILABLE = "NATIVE_UNAVAILABLE"
    const val INVALID_NATIVE_RESPONSE = "INVALID_NATIVE_RESPONSE"
    const val SOURCE_UNAVAILABLE = "SOURCE_UNAVAILABLE"
    const val UNSUPPORTED_MEDIA = "UNSUPPORTED_MEDIA"
    const val DECODE_FAILED = "DECODE_FAILED"
    const val ENCODE_FAILED = "ENCODE_FAILED"
    const val OUTPUT_FAILED = "OUTPUT_FAILED"
    const val PERSIST_FAILED = "PERSIST_FAILED"
    const val BUSY = "BUSY"
    const val RESULT_NOT_FOUND = "RESULT_NOT_FOUND"
    const val PROCESS_INTERRUPTED = "PROCESS_INTERRUPTED"
    const val OUTPUT_IN_USE = "OUTPUT_IN_USE"
    const val OUTPUT_NOT_FOUND = "OUTPUT_NOT_FOUND"
    const val LIMIT_EXCEEDED = "LIMIT_EXCEEDED"

    private val messages = mapOf(
        INVALID_REQUEST_ID to "A lowercase UUID version 4 request ID is required.",
        INVALID_SOURCE_ID to "A lowercase UUID version 4 picker document ID is required.",
        INVALID_OPTIONS to "The processing options are invalid.",
        REQUEST_TOO_LARGE to "The processing request exceeds the JSON size limit.",
        INVALID_DIMENSIONS to "The requested dimensions are outside the supported limits.",
        INVALID_QUALITY to "Quality must be an integer from 1 to 100 and is unavailable for PNG.",
        INVALID_FORMAT to "The requested output format is unsupported.",
        INVALID_BITRATE to "The requested bitrate is outside the supported limits.",
        INVALID_TIME_RANGE to "The requested media timestamps are invalid.",
        NATIVE_UNAVAILABLE to "Native Android media processing is unavailable.",
        INVALID_NATIVE_RESPONSE to "The native processor returned an invalid response.",
        SOURCE_UNAVAILABLE to "The selected private media file is unavailable.",
        UNSUPPORTED_MEDIA to "This media type or codec is unsupported.",
        DECODE_FAILED to "The media could not be decoded.",
        ENCODE_FAILED to "The media could not be encoded with the requested settings.",
        OUTPUT_FAILED to "The processed output could not be saved or verified.",
        PERSIST_FAILED to "The processing state could not be read or saved.",
        BUSY to "Another processing job is active.",
        RESULT_NOT_FOUND to "The processing request was not found.",
        PROCESS_INTERRUPTED to "Processing was interrupted. Start a new request to retry.",
        OUTPUT_IN_USE to "The output is currently in use.",
        OUTPUT_NOT_FOUND to "The processed output is unavailable.",
        LIMIT_EXCEEDED to "The media exceeds a supported processing or storage limit."
    )
    private val uuid = Regex("[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}")

    fun requestId(value: Any?): String? =
        (value as? String)?.takeIf { it.length == 36 && uuid.matches(it) }

    fun integer(value: Any?): Long? = when (value) {
        is Byte -> value.toLong()
        is Short -> value.toLong()
        is Int -> value.toLong()
        is Long -> value
        else -> null
    }

    fun knownError(value: Any?): Boolean = value is String && messages.containsKey(value)
    fun message(value: String): String = messages[value] ?: "Native media processing failed."

    // Flat scalar bridge requests only; measure the same escaped JSON as PHP.
    fun sizeError(parameters: Map<*, *>): String? {
        var bytes = 2L
        for ((index, entry) in parameters.entries.withIndex()) {
            val key = entry.key as? String ?: return INVALID_OPTIONS
            val keySize = stringSize(key) ?: return INVALID_OPTIONS
            val valueSize = when (val value = entry.value) {
                null -> 4
                is String -> stringSize(value) ?: return INVALID_OPTIONS
                is Boolean -> if (value) 4 else 5
                else -> integer(value)?.toString()?.length ?: return INVALID_OPTIONS
            }
            bytes += keySize.toLong() + 1 + valueSize + if (index == 0) 0 else 1
            if (bytes > MAX_REQUEST_BYTES) return REQUEST_TOO_LARGE
        }
        return null
    }

    private fun stringSize(value: String): Int? {
        var bytes = 2
        var index = 0
        while (index < value.length) {
            val character = value[index]
            if (Character.isHighSurrogate(character)) {
                if (index + 1 >= value.length || !Character.isLowSurrogate(value[index + 1])) return null
                bytes += 12
                index += 2
            } else {
                if (Character.isLowSurrogate(character)) return null
                bytes += when (character.code) {
                    34, 92, 8, 9, 10, 12, 13 -> 2
                    in 0..31, in 128..65535 -> 6
                    else -> 1
                }
                index++
            }
            if (bytes > MAX_REQUEST_BYTES) return MAX_REQUEST_BYTES + 1
        }
        return bytes
    }
}
