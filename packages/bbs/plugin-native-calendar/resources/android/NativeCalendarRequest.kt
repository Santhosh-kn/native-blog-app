package com.bbs.plugins.native_calendar

import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

internal sealed interface NativeCalendarRequestParseResult {

    data class Valid(
        val request: NativeCalendarRequest
    ) : NativeCalendarRequestParseResult {
        override fun toString(): String =
            "NativeCalendarRequestParseResult.Valid(redacted)"
    }

    data class Invalid(
        val id: String,
        val operation: String,
        val target: String?,
        val errorCode: String
    ) : NativeCalendarRequestParseResult {
        override fun toString(): String =
            "NativeCalendarRequestParseResult.Invalid(redacted)"
    }
}

/**
 * Validated, transient input for a single editor or viewer launch.
 *
 * Event details must not be written to request state, saved-state bundles,
 * completion events, status responses, or logs.
 */
internal class NativeCalendarRequest private constructor(
    val id: String,
    val operation: String,
    val target: String,
    val title: String? = null,
    val startTimeMs: Long? = null,
    val endTimeMs: Long? = null,
    val allDay: Boolean = false,
    val description: String? = null,
    val location: String? = null,
    val timeZone: String? = null,
    val recurrence: String? = null,
    val dateMs: Long? = null,
    val eventId: Long? = null
) {

    override fun toString(): String =
        "NativeCalendarRequest(redacted)"

    companion object {

        private val CREATE_KEYS = setOf(
            "id", "title", "startTimeMs", "endTimeMs",
            "allDay", "description", "location", "timeZone", "recurrence"
        )

        private val OPEN_KEYS = setOf(
            "id", "dateMs", "eventId", "startTimeMs", "endTimeMs"
        )

        fun parseCreate(
            parameters: Map<*, *>
        ): NativeCalendarRequestParseResult {
            // Bound the snapshot before copying caller-owned input.
            if (parameters.size > CREATE_KEYS.size) {
                return invalid(
                    parameters["id"],
                    Contract.CREATE_EVENT,
                    Contract.TARGET_EDITOR,
                    Contract.INVALID_OPTIONS
                )
            }

            val options = parameters.toMap()
            val suppliedId = Contract.requestId(options["id"])

            fun failed(code: String): NativeCalendarRequestParseResult.Invalid =
                invalid(
                    suppliedId,
                    Contract.CREATE_EVENT,
                    Contract.TARGET_EDITOR,
                    code
                )

            if (options.keys.any { it !is String || it !in CREATE_KEYS }) {
                return failed(Contract.INVALID_OPTIONS)
            }

            val id = suppliedId ?: return failed(Contract.INVALID_REQUEST_ID)

            if (!Contract.isTitle(options["title"])) {
                return failed(Contract.INVALID_TITLE)
            }

            val start = Contract.epochMilliseconds(options["startTimeMs"])
            val end = Contract.epochMilliseconds(options["endTimeMs"])

            if (start == null || end == null || end <= start) {
                return failed(Contract.INVALID_TIME_RANGE)
            }

            val allDayValue = if (options.containsKey("allDay")) {
                options["allDay"]
            } else {
                false
            }

            if (allDayValue !is Boolean) {
                return failed(Contract.INVALID_ALL_DAY)
            }

            if (
                allDayValue &&
                (start % Contract.DAY_MS != 0L || end % Contract.DAY_MS != 0L)
            ) {
                return failed(Contract.INVALID_ALL_DAY)
            }

            if (
                options.containsKey("description") &&
                !Contract.isDescription(options["description"])
            ) {
                return failed(Contract.INVALID_DESCRIPTION)
            }

            if (
                options.containsKey("location") &&
                !Contract.isLocation(options["location"])
            ) {
                return failed(Contract.INVALID_LOCATION)
            }

            if (options.containsKey("timeZone")) {
                if (
                    !Contract.isTimeZone(options["timeZone"]) ||
                    (allDayValue && options["timeZone"] != "UTC")
                ) {
                    return failed(Contract.INVALID_TIME_ZONE)
                }
            }

            if (
                options.containsKey("recurrence") &&
                !Contract.isRecurrence(options["recurrence"])
            ) {
                return failed(Contract.INVALID_RECURRENCE)
            }

            Contract.requestSizeError(options)?.let {
                return failed(it)
            }

            return NativeCalendarRequestParseResult.Valid(
                NativeCalendarRequest(
                    id = id,
                    operation = Contract.CREATE_EVENT,
                    target = Contract.TARGET_EDITOR,
                    title = options["title"] as String,
                    startTimeMs = start,
                    endTimeMs = end,
                    allDay = allDayValue,
                    description = options["description"] as? String,
                    location = options["location"] as? String,
                    timeZone = if (allDayValue) {
                        "UTC"
                    } else {
                        options["timeZone"] as? String
                    },
                    recurrence = options["recurrence"] as? String
                )
            )
        }

        fun parseOpen(
            parameters: Map<*, *>
        ): NativeCalendarRequestParseResult {
            if (parameters.size > OPEN_KEYS.size) {
                return invalid(
                    parameters["id"],
                    Contract.OPEN,
                    openTarget(parameters),
                    Contract.INVALID_OPTIONS
                )
            }

            val options = parameters.toMap()
            val suppliedId = Contract.requestId(options["id"])
            val target = openTarget(options)

            fun failed(code: String): NativeCalendarRequestParseResult.Invalid =
                invalid(suppliedId, Contract.OPEN, target, code)

            if (options.keys.any { it !is String || it !in OPEN_KEYS }) {
                return failed(Contract.INVALID_OPTIONS)
            }

            val id = suppliedId ?: return failed(Contract.INVALID_REQUEST_ID)

            if (target == null) {
                return failed(Contract.INVALID_TARGET)
            }

            val hasStart = options.containsKey("startTimeMs")
            val hasEnd = options.containsKey("endTimeMs")

            var date: Long? = null
            var event: Long? = null
            var start: Long? = null
            var end: Long? = null

            if (target == Contract.TARGET_DATE) {
                date = Contract.epochMilliseconds(options["dateMs"])

                if (date == null || hasStart || hasEnd) {
                    return failed(Contract.INVALID_TARGET)
                }
            } else {
                event = Contract.eventId(options["eventId"])
                    ?: return failed(Contract.INVALID_EVENT_ID)

                if (hasStart != hasEnd) {
                    return failed(Contract.INVALID_TIME_RANGE)
                }

                if (hasStart) {
                    val occurrenceStart =
                        Contract.epochMilliseconds(options["startTimeMs"])
                    val occurrenceEnd =
                        Contract.epochMilliseconds(options["endTimeMs"])

                    if (
                        occurrenceStart == null ||
                        occurrenceEnd == null ||
                        occurrenceEnd <= occurrenceStart
                    ) {
                        return failed(Contract.INVALID_TIME_RANGE)
                    }

                    start = occurrenceStart
                    end = occurrenceEnd
                }
            }

            Contract.requestSizeError(options)?.let {
                return failed(it)
            }

            return NativeCalendarRequestParseResult.Valid(
                NativeCalendarRequest(
                    id = id,
                    operation = Contract.OPEN,
                    target = target,
                    startTimeMs = start,
                    endTimeMs = end,
                    dateMs = date,
                    eventId = event
                )
            )
        }

        private fun openTarget(options: Map<*, *>): String? {
            val hasDate = options.containsKey("dateMs")
            val hasEvent = options.containsKey("eventId")

            return when {
                hasDate == hasEvent -> null
                hasDate -> Contract.TARGET_DATE
                else -> Contract.TARGET_EVENT
            }
        }

        private fun invalid(
            id: Any?,
            operation: String,
            target: String?,
            errorCode: String
        ): NativeCalendarRequestParseResult.Invalid =
            NativeCalendarRequestParseResult.Invalid(
                id = Contract.requestId(id) ?: Contract.FALLBACK_REQUEST_ID,
                operation = operation,
                target = target,
                errorCode = Contract.canonicalErrorCode(errorCode)
            )
    }
}
