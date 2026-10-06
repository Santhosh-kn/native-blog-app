package com.bbs.plugins.native_calendar

import android.content.ContentUris
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.provider.CalendarContract
import org.json.JSONObject
import java.time.Instant
import java.time.ZoneId
import java.time.ZoneOffset
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

internal object NativeCalendarIntents {

    private const val INSERT_MIME = "vnd.android.cursor.dir/event"
    private const val EVENT_MIME = "vnd.android.cursor.item/event"
    private const val TIME_MIME = "time/epoch"

    private val MIME_TYPES = setOf(INSERT_MIME, EVENT_MIME, TIME_MIME)

    fun forRequest(
        request: NativeCalendarRequest,
        deviceTimeZone: ZoneId = ZoneId.systemDefault()
    ): Intent {
        require(Contract.isBinding(request.operation, request.target)) {
            "Native Calendar launch target is invalid."
        }

        return when (request.operation) {
            Contract.CREATE_EVENT -> create().apply {
                putExtra(
                    CalendarContract.Events.TITLE,
                    requireNotNull(request.title)
                )
                putExtra(
                    CalendarContract.EXTRA_EVENT_BEGIN_TIME,
                    editorTime(
                        requireNotNull(request.startTimeMs),
                        request.allDay,
                        deviceTimeZone
                    )
                )
                putExtra(
                    CalendarContract.EXTRA_EVENT_END_TIME,
                    editorTime(
                        requireNotNull(request.endTimeMs),
                        request.allDay,
                        deviceTimeZone
                    )
                )
                putExtra(
                    CalendarContract.EXTRA_EVENT_ALL_DAY,
                    request.allDay
                )

                request.description?.let {
                    putExtra(CalendarContract.Events.DESCRIPTION, it)
                }

                request.location?.let {
                    putExtra(CalendarContract.Events.EVENT_LOCATION, it)
                }

                // All-day dates use the receiving editor's local timezone.
                // Receiving calendar applications may ignore timed-event hints.
                if (!request.allDay) {
                    request.timeZone?.let {
                        putExtra(CalendarContract.Events.EVENT_TIMEZONE, it)
                    }
                }

                request.recurrence?.let {
                    putExtra(CalendarContract.Events.RRULE, it)
                }
            }

            Contract.OPEN -> when (request.target) {
                Contract.TARGET_DATE ->
                    openDate(requireNotNull(request.dateMs))

                Contract.TARGET_EVENT ->
                    openEvent(requireNotNull(request.eventId)).apply {
                        request.startTimeMs?.let {
                            putExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, it)
                        }
                        request.endTimeMs?.let {
                            putExtra(CalendarContract.EXTRA_EVENT_END_TIME, it)
                        }
                    }

                else -> throw IllegalArgumentException(
                    "Native Calendar launch target is invalid."
                )
            }

            else -> throw IllegalArgumentException(
                "Native Calendar operation is invalid."
            )
        }
    }

    /**
     * The public all-day contract uses UTC midnight date boundaries.
     * Translate each date to local midnight only for the editor handoff.
     * Convert the exclusive end separately so daylight-saving changes
     * do not alter the requested calendar dates.
     */
    private fun editorTime(
        timeMs: Long,
        allDay: Boolean,
        deviceTimeZone: ZoneId
    ): Long {
        if (!allDay) {
            return timeMs
        }

        return Instant.ofEpochMilli(timeMs)
            .atZone(ZoneOffset.UTC)
            .toLocalDate()
            .atStartOfDay(deviceTimeZone)
            .toInstant()
            .toEpochMilli()
    }

    fun create(): Intent =
        Intent(Intent.ACTION_INSERT).apply {
            setDataAndType(CalendarContract.Events.CONTENT_URI, INSERT_MIME)
        }

    fun openDate(dateMs: Long): Intent {
        require(Contract.epochMilliseconds(dateMs) != null) {
            "Native Calendar date target is invalid."
        }

        val uri = CalendarContract.CONTENT_URI.buildUpon()
            .appendPath("time")
            .appendPath(dateMs.toString())
            .build()

        return Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, TIME_MIME)
        }
    }

    fun openEvent(eventId: Long): Intent {
        require(Contract.eventId(eventId) != null) {
            "Native Calendar event target is invalid."
        }

        val uri = ContentUris.withAppendedId(
            CalendarContract.Events.CONTENT_URI,
            eventId
        )

        return Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, EVENT_MIME)
        }
    }

    /**
     * Resolve only activities matching the explicit Calendar intent type.
     * Do not query calendar providers or return installed package identities.
     */
    fun resolves(context: Context, intent: Intent): Boolean {
        if (
            Build.VERSION.SDK_INT < Contract.MIN_API_LEVEL ||
            intent.type !in MIME_TYPES
        ) {
            return false
        }

        return try {
            context.packageManager.queryIntentActivities(
                intent,
                PackageManager.ResolveInfoFlags.of(
                    PackageManager.MATCH_DEFAULT_ONLY.toLong()
                )
            ).any { resolved ->
                val activity = resolved.activityInfo ?: return@any false
                val permission = activity.permission

                activity.enabled &&
                    activity.exported &&
                    activity.applicationInfo?.enabled != false &&
                    (
                        permission.isNullOrEmpty() ||
                            context.checkSelfPermission(permission) ==
                            PackageManager.PERMISSION_GRANTED
                        )
            }
        } catch (_: Exception) {
            false
        }
    }

    fun availability(context: Context): Map<String, Any> {
        if (Build.VERSION.SDK_INT < Contract.MIN_API_LEVEL) {
            return unavailable(Contract.UNSUPPORTED_ANDROID_VERSION)
        }

        // These synthetic probes have no event details and are never launched.
        val createEvent = resolves(context, create())
        val openDate = resolves(context, openDate(0L))
        val openEvent = resolves(context, openEvent(1L))

        return availabilityResponse(
            createEvent = createEvent,
            openDate = openDate,
            openEvent = openEvent,
            errorCode = Contract.NO_CALENDAR_APP
        )
    }

    fun unavailable(errorCode: Any?): Map<String, Any> =
        availabilityResponse(
            createEvent = false,
            openDate = false,
            openEvent = false,
            errorCode = Contract.canonicalErrorCode(errorCode)
        )

    private fun availabilityResponse(
        createEvent: Boolean,
        openDate: Boolean,
        openEvent: Boolean,
        errorCode: String
    ): Map<String, Any> {
        val supported = Build.VERSION.SDK_INT >= Contract.MIN_API_LEVEL

        val capabilities = linkedMapOf(
            "createEvent" to (supported && createEvent),
            "openDate" to (supported && openDate),
            "openEvent" to (supported && openEvent)
        )

        val available = capabilities.values.any { it }

        val code = when {
            available -> null
            !supported -> Contract.UNSUPPORTED_ANDROID_VERSION
            else -> Contract.canonicalErrorCode(errorCode)
        }

        return linkedMapOf(
            "available" to available,
            "platform" to "android",
            "apiLevel" to Build.VERSION.SDK_INT,
            "minimumApiLevel" to Contract.MIN_API_LEVEL,
            "capabilities" to capabilities,
            "errorCode" to (code ?: JSONObject.NULL),
            "errorMessage" to (
                code?.let { Contract.errorMessage(it) } ?: JSONObject.NULL
                )
        )
    }
}
