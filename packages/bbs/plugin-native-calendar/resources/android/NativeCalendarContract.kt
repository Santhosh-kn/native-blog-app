package com.bbs.plugins.native_calendar

import java.util.TimeZone

internal object NativeCalendarContract {

    const val MIN_API_LEVEL = 33

    const val MAX_REQUEST_BYTES = 8_192
    const val MAX_RESPONSE_BYTES = 16_384
    const val MAX_RESULT_BYTES = 2_048

    const val MAX_TITLE_CODE_POINTS = 120
    const val MAX_DESCRIPTION_CODE_POINTS = 2_048
    const val MAX_LOCATION_CODE_POINTS = 240
    const val MAX_TIME_ZONE_BYTES = 64
    const val MAX_RECURRENCE_BYTES = 128

    const val MAX_EPOCH_MS = 253_402_300_799_999L
    const val MAX_EVENT_ID = 9_007_199_254_740_991L
    const val DAY_MS = 86_400_000L

    const val METADATA_TTL_MS = 86_400_000L
    const val MAX_STORED_RESULTS = 100

    const val CREATE_EVENT = "create_event"
    const val OPEN = "open"

    const val TARGET_EDITOR = "editor"
    const val TARGET_DATE = "date"
    const val TARGET_EVENT = "event"

    const val STATUS_PENDING = "pending"
    const val STATUS_LAUNCHED = "launched"
    const val STATUS_FAILED = "failed"
    const val STATUS_UNKNOWN = "unknown"
    const val STATUS_NOT_FOUND = "not_found"

    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val INVALID_OPTIONS = "INVALID_OPTIONS"
    const val INVALID_TITLE = "INVALID_TITLE"
    const val INVALID_DESCRIPTION = "INVALID_DESCRIPTION"
    const val INVALID_LOCATION = "INVALID_LOCATION"
    const val INVALID_TIME_RANGE = "INVALID_TIME_RANGE"
    const val INVALID_ALL_DAY = "INVALID_ALL_DAY"
    const val INVALID_TIME_ZONE = "INVALID_TIME_ZONE"
    const val INVALID_RECURRENCE = "INVALID_RECURRENCE"
    const val INVALID_EVENT_ID = "INVALID_EVENT_ID"
    const val INVALID_TARGET = "INVALID_TARGET"
    const val REQUEST_TOO_LARGE = "REQUEST_TOO_LARGE"
    const val UNSUPPORTED_PLATFORM = "UNSUPPORTED_PLATFORM"
    const val UNSUPPORTED_ANDROID_VERSION = "UNSUPPORTED_ANDROID_VERSION"
    const val BRIDGE_UNAVAILABLE = "BRIDGE_UNAVAILABLE"
    const val INVALID_NATIVE_RESPONSE = "INVALID_NATIVE_RESPONSE"
    const val NO_CALENDAR_APP = "NO_CALENDAR_APP"
    const val ACTIVITY_UNAVAILABLE = "ACTIVITY_UNAVAILABLE"
    const val REQUEST_ALREADY_EXISTS = "REQUEST_ALREADY_EXISTS"
    const val REQUEST_IN_PROGRESS = "REQUEST_IN_PROGRESS"
    const val LAUNCH_FAILED = "LAUNCH_FAILED"
    const val INTERRUPTED = "INTERRUPTED"
    const val PERSIST_FAILED = "PERSIST_FAILED"
    const val RESULT_NOT_FOUND = "RESULT_NOT_FOUND"
    const val UNKNOWN_ERROR = "UNKNOWN_ERROR"

    const val FALLBACK_REQUEST_ID =
        "00000000-0000-4000-8000-000000000000"

    private val requestIdPattern = Regex(
        "[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"
    )

    private val timeZonePattern = Regex("[A-Za-z0-9_+/-]+")

    private val recurrencePattern = Regex(
        "FREQ=(DAILY|WEEKLY|MONTHLY|YEARLY)" +
            "(?:;INTERVAL=([1-9][0-9]{0,2}))?" +
            "(?:;COUNT=([1-9][0-9]{0,3}))?"
    )

    private val timeZones: Set<String> by lazy {
        TimeZone.getAvailableIDs().toSet() + "UTC"
    }

    private val messages = mapOf(
        INVALID_REQUEST_ID to
            "The request ID must be a lowercase UUID v4.",
        INVALID_OPTIONS to
            "The request contains unsupported options.",
        INVALID_TITLE to
            "The event title is invalid.",
        INVALID_DESCRIPTION to
            "The event description is invalid.",
        INVALID_LOCATION to
            "The event location is invalid.",
        INVALID_TIME_RANGE to
            "The event time range is invalid.",
        INVALID_ALL_DAY to
            "The all-day setting or date boundaries are invalid.",
        INVALID_TIME_ZONE to
            "The event time zone is invalid.",
        INVALID_RECURRENCE to
            "The recurrence rule is invalid or unsupported.",
        INVALID_EVENT_ID to
            "The event ID must be a positive integer.",
        INVALID_TARGET to
            "Choose a valid calendar date or event target.",
        REQUEST_TOO_LARGE to
            "The request exceeds the supported size.",
        UNSUPPORTED_PLATFORM to
            "Native Calendar is supported on Android only.",
        UNSUPPORTED_ANDROID_VERSION to
            "Native Calendar requires Android API 33 or later.",
        BRIDGE_UNAVAILABLE to
            "The native Calendar bridge is unavailable.",
        INVALID_NATIVE_RESPONSE to
            "The native Calendar response could not be validated.",
        NO_CALENDAR_APP to
            "No compatible calendar application is available.",
        ACTIVITY_UNAVAILABLE to
            "The application cannot launch calendar UI right now.",
        REQUEST_ALREADY_EXISTS to
            "This request ID has already been used.",
        REQUEST_IN_PROGRESS to
            "Another calendar request is pending.",
        LAUNCH_FAILED to
            "The calendar editor or viewer could not be launched.",
        INTERRUPTED to
            "The calendar operation was interrupted; its outcome is unknown.",
        PERSIST_FAILED to
            "Calendar request state could not be stored safely.",
        RESULT_NOT_FOUND to
            "No calendar request was found for this ID.",
        UNKNOWN_ERROR to
            "The calendar operation could not be completed."
    )

    fun requestId(value: Any?): String? =
        (value as? String)?.takeIf {
            it.length == 36 && requestIdPattern.matches(it)
        }

    /**
     * Do not coerce strings, floating-point values, or booleans.
     */
    fun integer(value: Any?): Long? = when (value) {
        is Byte -> value.toLong()
        is Short -> value.toLong()
        is Int -> value.toLong()
        is Long -> value
        else -> null
    }

    fun epochMilliseconds(value: Any?): Long? =
        integer(value)?.takeIf { it in 0L..MAX_EPOCH_MS }

    fun eventId(value: Any?): Long? =
        integer(value)?.takeIf { it in 1L..MAX_EVENT_ID }

    fun isTimeRange(start: Any?, end: Any?): Boolean {
        val startMs = epochMilliseconds(start) ?: return false
        val endMs = epochMilliseconds(end) ?: return false

        return endMs > startMs
    }

    fun isAllDayRange(start: Any?, end: Any?): Boolean {
        val startMs = epochMilliseconds(start) ?: return false
        val endMs = epochMilliseconds(end) ?: return false

        return endMs > startMs &&
            startMs % DAY_MS == 0L &&
            endMs % DAY_MS == 0L
    }

    fun isTitle(value: Any?): Boolean =
        isText(value, MAX_TITLE_CODE_POINTS, false, false)

    fun isDescription(value: Any?): Boolean =
        isText(value, MAX_DESCRIPTION_CODE_POINTS, true, true)

    fun isLocation(value: Any?): Boolean =
        isText(value, MAX_LOCATION_CODE_POINTS, true, false)

    fun isTimeZone(value: Any?): Boolean {
        if (
            value !is String ||
            value.length !in 1..MAX_TIME_ZONE_BYTES ||
            !timeZonePattern.matches(value)
        ) {
            return false
        }

        // Membership prevents TimeZone's silent fallback to GMT.
        return value in timeZones
    }

    fun isRecurrence(value: Any?): Boolean {
        if (
            value !is String ||
            value.length > MAX_RECURRENCE_BYTES
        ) {
            return false
        }

        val match = recurrencePattern.matchEntire(value) ?: return false

        val interval = match.groupValues[2].let {
            if (it.isEmpty()) 1 else it.toInt()
        }

        val count = match.groupValues[3].let {
            if (it.isEmpty()) null else it.toInt()
        }

        return interval <= 365 && (count == null || count <= 1_000)
    }

    fun isBinding(operation: String?, target: String?): Boolean =
        when (operation) {
            CREATE_EVENT -> target == TARGET_EDITOR
            OPEN -> target == TARGET_DATE || target == TARGET_EVENT
            else -> false
        }

    fun isKnownErrorCode(value: Any?): Boolean =
        value is String && messages.containsKey(value)

    fun canonicalErrorCode(value: Any?): String =
        if (value is String && messages.containsKey(value)) {
            value
        } else {
            UNKNOWN_ERROR
        }

    fun errorMessage(value: Any?): String =
        messages.getValue(canonicalErrorCode(value))

    /**
     * Measure the flat request as PHP encodes it with
     * JSON_UNESCAPED_SLASHES and default Unicode escaping.
     *
     * Semantic validation runs before this size check.
     * Nested values and non-integral numeric types are unsupported.
     */
    fun requestSizeError(parameters: Map<*, *>): String? {
        var bytes = 2
        var first = true

        for ((key, value) in parameters) {
            if (key !is String) {
                return INVALID_OPTIONS
            }

            val keyBytes = encodedStringBytes(key)
                ?: return INVALID_OPTIONS

            val valueBytes = when (value) {
                null -> 4
                is String -> encodedStringBytes(value)
                    ?: return INVALID_OPTIONS
                is Boolean -> if (value) 4 else 5
                else -> integer(value)?.toString()?.length
                    ?: return INVALID_OPTIONS
            }

            bytes += keyBytes + 1 + valueBytes

            if (!first) {
                bytes += 1
            }

            if (bytes > MAX_REQUEST_BYTES) {
                return REQUEST_TOO_LARGE
            }

            first = false
        }

        return null
    }

    private fun isText(
        value: Any?,
        maxCodePoints: Int,
        allowEmpty: Boolean,
        multiline: Boolean
    ): Boolean {
        if (
            value !is String ||
            value.length > maxCodePoints * 2
        ) {
            return false
        }

        var index = 0
        var count = 0
        var hasNonWhitespace = false

        while (index < value.length) {
            val character = value[index]

            if (Character.isHighSurrogate(character)) {
                if (
                    index + 1 >= value.length ||
                    !Character.isLowSurrogate(value[index + 1])
                ) {
                    return false
                }
            } else if (Character.isLowSurrogate(character)) {
                return false
            }

            val codePoint = Character.codePointAt(value, index)

            if (codePoint in 0..31 || codePoint in 127..159) {
                val allowedLineControl = multiline &&
                    (codePoint == 9 || codePoint == 10 || codePoint == 13)

                if (!allowedLineControl) {
                    return false
                }
            }

            if (
                !Character.isWhitespace(codePoint) &&
                !Character.isSpaceChar(codePoint)
            ) {
                hasNonWhitespace = true
            }

            count += 1

            if (count > maxCodePoints) {
                return false
            }

            index += Character.charCount(codePoint)
        }

        return allowEmpty || hasNonWhitespace
    }

    private fun encodedStringBytes(value: String): Int? {
        var bytes = 2
        var index = 0

        while (index < value.length) {
            val character = value[index]
            val code = character.code

            if (Character.isHighSurrogate(character)) {
                if (
                    index + 1 >= value.length ||
                    !Character.isLowSurrogate(value[index + 1])
                ) {
                    return null
                }

                // PHP emits two six-byte Unicode escapes.
                bytes += 12
                index += 2
            } else {
                if (Character.isLowSurrogate(character)) {
                    return null
                }

                bytes += when (code) {
                    34, 92 -> 2
                    8, 9, 10, 12, 13 -> 2
                    in 0..31 -> 6
                    in 128..65_535 -> 6
                    else -> 1
                }

                index += 1
            }

            if (bytes > MAX_REQUEST_BYTES) {
                return MAX_REQUEST_BYTES + 1
            }
        }

        return bytes
    }
}
