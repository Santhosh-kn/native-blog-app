package com.bbs.plugins.native_calendar

import android.content.Intent
import android.provider.CalendarContract
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Test
import java.time.Instant
import java.time.ZoneId
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeCalendarIntentsInstrumentedTest {

    @Test
    fun editorProbeHasOnlyTheCalendarInsertTarget() {
        val intent = NativeCalendarIntents.create()
        assertEquals(Intent.ACTION_INSERT, intent.action)
        assertEquals("content://com.android.calendar/events", intent.dataString)
        assertEquals("vnd.android.cursor.dir/event", intent.type)
        assertTrue(extraKeys(intent).isEmpty())
    }

    @Test
    fun timedEditorPreservesPrefillsAndLongTimestamps() {
        val intent = NativeCalendarIntents.forRequest(
            create(
                "description" to "Synthetic description\nSecond line",
                "location" to "Synthetic room",
                "timeZone" to "Asia/Kolkata",
                "recurrence" to "FREQ=WEEKLY;COUNT=3"
            )
        )

        assertEquals(Intent.ACTION_INSERT, intent.action)
        assertEquals("vnd.android.cursor.dir/event", intent.type)
        assertEquals("content://com.android.calendar/events", intent.dataString)
        assertEquals("Synthetic event", intent.getStringExtra(CalendarContract.Events.TITLE))
        assertEquals(START, intent.getLongExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, -1L))
        assertEquals(END, intent.getLongExtra(CalendarContract.EXTRA_EVENT_END_TIME, -1L))
        assertFalse(intent.getBooleanExtra(CalendarContract.EXTRA_EVENT_ALL_DAY, true))
        assertEquals(
            "Synthetic description\nSecond line",
            intent.getStringExtra(CalendarContract.Events.DESCRIPTION)
        )
        assertEquals(
            "Synthetic room",
            intent.getStringExtra(CalendarContract.Events.EVENT_LOCATION)
        )
        assertEquals(
            "Asia/Kolkata",
            intent.getStringExtra(CalendarContract.Events.EVENT_TIMEZONE)
        )
        assertEquals(
            "FREQ=WEEKLY;COUNT=3",
            intent.getStringExtra(CalendarContract.Events.RRULE)
        )

        assertEquals(
            setOf(
                CalendarContract.Events.TITLE,
                CalendarContract.EXTRA_EVENT_BEGIN_TIME,
                CalendarContract.EXTRA_EVENT_END_TIME,
                CalendarContract.EXTRA_EVENT_ALL_DAY,
                CalendarContract.Events.DESCRIPTION,
                CalendarContract.Events.EVENT_LOCATION,
                CalendarContract.Events.EVENT_TIMEZONE,
                CalendarContract.Events.RRULE
            ),
            extraKeys(intent)
        )
        assertTrue(requireNotNull(intent.extras).get(CalendarContract.EXTRA_EVENT_BEGIN_TIME) is Long)
        assertTrue(requireNotNull(intent.extras).get(CalendarContract.EXTRA_EVENT_END_TIME) is Long)
    }

    @Test
    fun omittedOptionalFieldsDoNotProduceExtraEditorValues() {
        val intent = NativeCalendarIntents.forRequest(create())

        assertEquals(
            setOf(
                CalendarContract.Events.TITLE,
                CalendarContract.EXTRA_EVENT_BEGIN_TIME,
                CalendarContract.EXTRA_EVENT_END_TIME,
                CalendarContract.EXTRA_EVENT_ALL_DAY
            ),
            extraKeys(intent)
        )
        assertFalse(intent.hasExtra("id"))
    }

    @Test
    fun allDayEditorPreservesDatesEastAndWestOfUtc() {
        assertAllDayEditor(
            "Asia/Kolkata",
            "2026-10-07T00:00:00Z", "2026-10-08T00:00:00Z",
            "2026-10-06T18:30:00Z", "2026-10-07T18:30:00Z"
        )
        assertAllDayEditor(
            "America/Los_Angeles",
            "2026-10-07T00:00:00Z", "2026-10-08T00:00:00Z",
            "2026-10-07T07:00:00Z", "2026-10-08T07:00:00Z"
        )
        assertAllDayEditor(
            "UTC",
            "2026-10-07T00:00:00Z", "2026-10-08T00:00:00Z",
            "2026-10-07T00:00:00Z", "2026-10-08T00:00:00Z"
        )
    }

    @Test
    fun allDayEditorPreservesExclusiveEndAcrossDaylightSavingChanges() {
        assertAllDayEditor(
            "America/New_York",
            "2026-03-08T00:00:00Z", "2026-03-09T00:00:00Z",
            "2026-03-08T05:00:00Z", "2026-03-09T04:00:00Z"
        )
        assertAllDayEditor(
            "America/New_York",
            "2026-11-01T00:00:00Z", "2026-11-02T00:00:00Z",
            "2026-11-01T04:00:00Z", "2026-11-02T05:00:00Z"
        )
    }

    @Test
    fun allDayEditorPreservesMultipleRequestedDays() {
        assertAllDayEditor(
            "Asia/Kolkata",
            "2026-10-07T00:00:00Z", "2026-10-10T00:00:00Z",
            "2026-10-06T18:30:00Z", "2026-10-09T18:30:00Z"
        )
    }

    private fun assertAllDayEditor(
        zone: String,
        startUtc: String,
        endUtc: String,
        expectedStart: String,
        expectedEnd: String
    ) {
        val start = Instant.parse(startUtc).toEpochMilli()
        val end = Instant.parse(endUtc).toEpochMilli()
        val request = create(
            "startTimeMs" to start,
            "endTimeMs" to end,
            "allDay" to true,
            "timeZone" to "UTC"
        )
        val intent = NativeCalendarIntents.forRequest(
            request,
            ZoneId.of(zone)
        )

        assertTrue(intent.getBooleanExtra(CalendarContract.EXTRA_EVENT_ALL_DAY, false))
        assertEquals(
            "All-day start in $zone",
            Instant.parse(expectedStart).toEpochMilli(),
            intent.getLongExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, -1L)
        )
        assertEquals(
            "All-day exclusive end in $zone",
            Instant.parse(expectedEnd).toEpochMilli(),
            intent.getLongExtra(CalendarContract.EXTRA_EVENT_END_TIME, -1L)
        )
        assertFalse(intent.hasExtra(CalendarContract.Events.EVENT_TIMEZONE))
        assertEquals(
            setOf(
                CalendarContract.Events.TITLE,
                CalendarContract.EXTRA_EVENT_BEGIN_TIME,
                CalendarContract.EXTRA_EVENT_END_TIME,
                CalendarContract.EXTRA_EVENT_ALL_DAY
            ),
            extraKeys(intent)
        )
        assertEquals(start, requireNotNull(request.startTimeMs))
        assertEquals(end, requireNotNull(request.endTimeMs))
        assertEquals("UTC", request.timeZone)
    }

    @Test
    fun dateViewerUsesTheExactTimeUriWithoutEditorExtras() {
        for (date in listOf(0L, START, NativeCalendarContract.MAX_EPOCH_MS)) {
            val intent = NativeCalendarIntents.forRequest(
                open("dateMs" to date)
            )
            assertEquals(Intent.ACTION_VIEW, intent.action)
            assertEquals("time/epoch", intent.type)
            assertEquals("content://com.android.calendar/time/$date", intent.dataString)
            assertTrue(extraKeys(intent).isEmpty())
        }
    }

    @Test
    fun eventViewerPreservesTheExactSafeIntegerIdInItsUri() {
        for (id in listOf(1L, NativeCalendarContract.MAX_EVENT_ID)) {
            val intent = NativeCalendarIntents.forRequest(
                open("eventId" to id)
            )
            assertEquals(Intent.ACTION_VIEW, intent.action)
            assertEquals("vnd.android.cursor.item/event", intent.type)
            assertEquals("content://com.android.calendar/events/$id", intent.dataString)
            assertTrue(extraKeys(intent).isEmpty())
        }
    }

    @Test
    fun eventOccurrenceAddsOnlyTheRequestedBeginAndEndTimes() {
        val intent = NativeCalendarIntents.forRequest(
            open(
                "eventId" to 1L,
                "startTimeMs" to START,
                "endTimeMs" to END
            )
        )

        assertEquals("content://com.android.calendar/events/1", intent.dataString)
        assertEquals(
            setOf(
                CalendarContract.EXTRA_EVENT_BEGIN_TIME,
                CalendarContract.EXTRA_EVENT_END_TIME
            ),
            extraKeys(intent)
        )
        assertEquals(START, intent.getLongExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, -1L))
        assertEquals(END, intent.getLongExtra(CalendarContract.EXTRA_EVENT_END_TIME, -1L))
    }

    @Test
    fun generatedIntentsHaveNoUriGrantsTaskFlagsOrPrivateRoutingPayloads() {
        val intents = listOf(
            NativeCalendarIntents.forRequest(create()),
            NativeCalendarIntents.forRequest(open("dateMs" to START)),
            NativeCalendarIntents.forRequest(open("eventId" to 1L))
        )

        for (intent in intents) {
            assertEquals(0, intent.flags)
            assertNull(intent.clipData)
            assertNull(intent.component)
            assertNull(intent.`package`)
            assertNull(intent.selector)
            assertNull(intent.categories)
            assertFalse(intent.hasExtra("id"))
            assertFalse(intent.hasExtra("requestId"))
            assertFalse(intent.hasExtra("token"))
        }
    }

    private fun create(vararg changes: Pair<String, Any?>): NativeCalendarRequest {
        val options = linkedMapOf<String, Any?>(
            "id" to ID,
            "title" to "Synthetic event",
            "startTimeMs" to START,
            "endTimeMs" to END
        ).apply { putAll(changes) }

        return valid(NativeCalendarRequest.parseCreate(options))
    }

    private fun open(vararg target: Pair<String, Any?>): NativeCalendarRequest =
        valid(
            NativeCalendarRequest.parseOpen(
                linkedMapOf<String, Any?>("id" to ID).apply { putAll(target) }
            )
        )

    private fun valid(result: NativeCalendarRequestParseResult): NativeCalendarRequest {
        assertTrue("Synthetic intent input must be valid.", result is NativeCalendarRequestParseResult.Valid)
        return (result as NativeCalendarRequestParseResult.Valid).request
    }

    private fun extraKeys(intent: Intent): Set<String> =
        intent.extras?.keySet()?.toSet() ?: emptySet()

    companion object {
        private const val ID = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"
        private const val START = 1_700_000_000_000L
        private const val END = START + 3_600_000L
    }
}
