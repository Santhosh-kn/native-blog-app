package com.bbs.plugins.native_calendar

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeCalendarRequestInstrumentedTest {

    @Test
    fun bridgeJsonPreservesIntegralValuesWithoutLosingPrecision() {
        val json = JSONObject(
            """{"small":2147483647,"start":1700000000000,"event":9007199254740991}"""
        )
        assertTrue(json.get("small") is Int)
        assertTrue(json.get("start") is Long)
        assertTrue(json.get("event") is Long)

        val request = valid(
            NativeCalendarRequest.parseOpen(
                mapOf("id" to ID, "eventId" to json.get("event"))
            )
        )
        assertEquals(Contract.MAX_EVENT_ID, requireNotNull(request.eventId))
    }

    @Test
    fun floatingPointStringsAndBooleansCannotBecomeTimestampsOrEventIds() {
        val json = JSONObject("""{"fraction":1700000000000.0}""")
        for (value in listOf(json.get("fraction"), "1700000000000", true, 1.0f)) {
            invalid(
                NativeCalendarRequest.parseCreate(create("startTimeMs" to value)),
                Contract.INVALID_TIME_RANGE
            )
            invalid(
                NativeCalendarRequest.parseOpen(mapOf("id" to ID, "eventId" to value)),
                Contract.INVALID_EVENT_ID
            )
        }
    }

    @Test
    fun timestampBoundsAndStrictlyIncreasingRangesAreEnforced() {
        valid(NativeCalendarRequest.parseCreate(create("startTimeMs" to 0L)))
        valid(
            NativeCalendarRequest.parseCreate(
                create(
                    "startTimeMs" to Contract.MAX_EPOCH_MS - 1L,
                    "endTimeMs" to Contract.MAX_EPOCH_MS
                )
            )
        )
        for (start in listOf(-1L, END, Contract.MAX_EPOCH_MS + 1L)) {
            invalid(
                NativeCalendarRequest.parseCreate(create("startTimeMs" to start)),
                Contract.INVALID_TIME_RANGE
            )
        }
        invalid(
            NativeCalendarRequest.parseCreate(create("endTimeMs" to START)),
            Contract.INVALID_TIME_RANGE
        )
    }

    @Test
    fun eventIdsStayWithinTheCrossLanguageSafeIntegerRange() {
        for (id in listOf(1L, Contract.MAX_EVENT_ID)) {
            valid(NativeCalendarRequest.parseOpen(mapOf("id" to ID, "eventId" to id)))
        }
        for (id in listOf(-1L, 0L, Contract.MAX_EVENT_ID + 1L, Long.MAX_VALUE)) {
            invalid(
                NativeCalendarRequest.parseOpen(mapOf("id" to ID, "eventId" to id)),
                Contract.INVALID_EVENT_ID
            )
        }
    }

    @Test
    fun requestIdsRequireCanonicalLowercaseVersionFourUuids() {
        valid(NativeCalendarRequest.parseCreate(create()))
        for (id in listOf(ID.uppercase(), ID.replace("-4aaa-", "-1aaa-"), "", 123)) {
            invalid(
                NativeCalendarRequest.parseCreate(create("id" to id)),
                Contract.INVALID_REQUEST_ID
            )
        }
        val options = create()
        options.remove("id")
        invalid(
            NativeCalendarRequest.parseCreate(options),
            Contract.INVALID_REQUEST_ID
        )
    }

    @Test
    fun unknownAndNonStringKeysCannotEnterARequest() {
        invalid(
            NativeCalendarRequest.parseCreate(create("attendees" to listOf("private"))),
            Contract.INVALID_OPTIONS
        )
        val options = mutableMapOf<Any, Any?>()
        options.putAll(create())
        options[42] = "private"
        invalid(NativeCalendarRequest.parseCreate(options), Contract.INVALID_OPTIONS)
    }

    @Test
    fun titleLimitsCountUnicodeCodePointsInsteadOfUtf16Units() {
        val emoji = "\uD83D\uDE00"
        valid(NativeCalendarRequest.parseCreate(create("title" to emoji.repeat(120))))
        invalid(
            NativeCalendarRequest.parseCreate(create("title" to emoji.repeat(121))),
            Contract.INVALID_TITLE
        )
        invalid(
            NativeCalendarRequest.parseCreate(create("title" to "\u00A0 \t")),
            Contract.INVALID_TITLE
        )
    }

    @Test
    fun malformedUtf16CannotEnterEventFields() {
        for (text in listOf("\uD800", "\uDC00", "x\uD800y")) {
            invalid(
                NativeCalendarRequest.parseCreate(create("title" to text)),
                Contract.INVALID_TITLE
            )
            invalid(
                NativeCalendarRequest.parseCreate(create("description" to text)),
                Contract.INVALID_DESCRIPTION
            )
            invalid(
                NativeCalendarRequest.parseCreate(create("location" to text)),
                Contract.INVALID_LOCATION
            )
        }
    }

    @Test
    fun multilineDescriptionsAllowOnlyTheSupportedControlCharacters() {
        valid(
            NativeCalendarRequest.parseCreate(
                create("description" to "line one\nline two\r\n\tindented")
            )
        )
        invalid(
            NativeCalendarRequest.parseCreate(create("description" to "private\u0000")),
            Contract.INVALID_DESCRIPTION
        )
        invalid(
            NativeCalendarRequest.parseCreate(create("location" to "room\none")),
            Contract.INVALID_LOCATION
        )
        invalid(
            NativeCalendarRequest.parseCreate(create("title" to "event\u0085")),
            Contract.INVALID_TITLE
        )
    }

    @Test
    fun allDayEventsUseUtcMidnightAndAnExclusiveLaterEnd() {
        val request = valid(
            NativeCalendarRequest.parseCreate(
                create(
                    "startTimeMs" to 0L,
                    "endTimeMs" to Contract.DAY_MS,
                    "allDay" to true
                )
            )
        )
        assertTrue(request.allDay)
        assertEquals("UTC", request.timeZone)

        invalid(
            NativeCalendarRequest.parseCreate(
                create(
                    "startTimeMs" to 1L,
                    "endTimeMs" to Contract.DAY_MS,
                    "allDay" to true
                )
            ),
            Contract.INVALID_ALL_DAY
        )
        invalid(
            NativeCalendarRequest.parseCreate(
                create(
                    "startTimeMs" to 0L,
                    "endTimeMs" to Contract.DAY_MS + 1L,
                    "allDay" to true
                )
            ),
            Contract.INVALID_ALL_DAY
        )
    }

    @Test
    fun allDayDoesNotCoerceNumericOrStringFlags() {
        assertFalse(valid(NativeCalendarRequest.parseCreate(create())).allDay)
        for (value in listOf(0, 1, "true", JSONObject.NULL)) {
            invalid(
                NativeCalendarRequest.parseCreate(create("allDay" to value)),
                Contract.INVALID_ALL_DAY
            )
        }
    }

    @Test
    fun invalidTimeZonesCannotSilentlyFallBackToGmt() {
        valid(
            NativeCalendarRequest.parseCreate(create("timeZone" to "Asia/Kolkata"))
        )
        invalid(
            NativeCalendarRequest.parseCreate(create("timeZone" to "Private/Unknown")),
            Contract.INVALID_TIME_ZONE
        )
        invalid(
            NativeCalendarRequest.parseCreate(
                create(
                    "startTimeMs" to 0L,
                    "endTimeMs" to Contract.DAY_MS,
                    "allDay" to true,
                    "timeZone" to "Asia/Kolkata"
                )
            ),
            Contract.INVALID_TIME_ZONE
        )
    }

    @Test
    fun recurrenceAcceptsOnlyTheDocumentedBoundedCanonicalSubset() {
        for (rule in listOf(
            "FREQ=DAILY",
            "FREQ=WEEKLY;COUNT=3",
            "FREQ=YEARLY;INTERVAL=365;COUNT=1000"
        )) {
            valid(NativeCalendarRequest.parseCreate(create("recurrence" to rule)))
        }
        for (rule in listOf(
            "FREQ=DAILY;INTERVAL=0",
            "FREQ=DAILY;INTERVAL=366",
            "FREQ=DAILY;COUNT=1001",
            "FREQ=WEEKLY;BYDAY=MO",
            "FREQ=DAILY;UNTIL=20270101",
            "freq=daily",
            "FREQ=DAILY;COUNT=2;INTERVAL=1"
        )) {
            invalid(
                NativeCalendarRequest.parseCreate(create("recurrence" to rule)),
                Contract.INVALID_RECURRENCE
            )
        }
    }

    @Test
    fun asciiWireSizeAcceptsExactly8192Bytes() {
        // {"x":"..."} contributes eight bytes around the string contents.
        assertNull(Contract.requestSizeError(mapOf("x" to "a".repeat(8184))))
        assertEquals(
            Contract.REQUEST_TOO_LARGE,
            Contract.requestSizeError(mapOf("x" to "a".repeat(8185)))
        )
        assertNull(Contract.requestSizeError(mapOf("x" to "/".repeat(8184))))
    }

    @Test
    fun wireSizeMatchesPhpUnicodeEscapingForBmpAndSupplementaryCharacters() {
        assertNull(Contract.requestSizeError(mapOf("x" to "\u0C85".repeat(1364))))
        assertEquals(
            Contract.REQUEST_TOO_LARGE,
            Contract.requestSizeError(mapOf("x" to "\u0C85".repeat(1365)))
        )
        val emoji = "\uD83D\uDE00"
        assertNull(Contract.requestSizeError(mapOf("x" to emoji.repeat(682))))
        assertEquals(
            Contract.REQUEST_TOO_LARGE,
            Contract.requestSizeError(mapOf("x" to emoji.repeat(683)))
        )
    }

    @Test
    fun individuallyValidFieldsStillRespectTheCombinedWireLimit() {
        invalid(
            NativeCalendarRequest.parseCreate(
                create("description" to "\u0C85".repeat(2048))
            ),
            Contract.REQUEST_TOO_LARGE
        )
    }

    @Test
    fun dateAndEventTargetsAreMutuallyExclusive() {
        val date = valid(
            NativeCalendarRequest.parseOpen(mapOf("id" to ID, "dateMs" to 0L))
        )
        assertEquals(Contract.TARGET_DATE, date.target)

        invalid(
            NativeCalendarRequest.parseOpen(mapOf("id" to ID)),
            Contract.INVALID_TARGET
        )
        invalid(
            NativeCalendarRequest.parseOpen(
                mapOf("id" to ID, "dateMs" to START, "eventId" to 1L)
            ),
            Contract.INVALID_TARGET
        )
        invalid(
            NativeCalendarRequest.parseOpen(
                mapOf("id" to ID, "dateMs" to START, "startTimeMs" to START)
            ),
            Contract.INVALID_TARGET
        )
    }

    @Test
    fun eventOccurrenceRequiresBothTimesAndPreservesTheirIntegerValues() {
        val request = valid(
            NativeCalendarRequest.parseOpen(
                mapOf(
                    "id" to ID, "eventId" to 1L,
                    "startTimeMs" to START, "endTimeMs" to END
                )
            )
        )
        assertEquals(START, requireNotNull(request.startTimeMs))
        assertEquals(END, requireNotNull(request.endTimeMs))

        for (key in listOf("startTimeMs", "endTimeMs")) {
            invalid(
                NativeCalendarRequest.parseOpen(
                    mapOf("id" to ID, "eventId" to 1L, key to START)
                ),
                Contract.INVALID_TIME_RANGE
            )
        }
    }

    @Test
    fun explicitJsonNullIsNotTreatedAsAnOmittedOptionalField() {
        for ((key, error) in mapOf(
            "description" to Contract.INVALID_DESCRIPTION,
            "location" to Contract.INVALID_LOCATION,
            "timeZone" to Contract.INVALID_TIME_ZONE,
            "recurrence" to Contract.INVALID_RECURRENCE
        )) {
            invalid(
                NativeCalendarRequest.parseCreate(create(key to JSONObject.NULL)),
                error
            )
        }
    }

    @Test
    fun diagnosticStringsNeverContainEventFields() {
        val parsed = NativeCalendarRequest.parseCreate(
            create(
                "title" to "PRIVATE_TITLE",
                "description" to "PRIVATE_DESCRIPTION",
                "location" to "PRIVATE_LOCATION"
            )
        )
        val request = valid(parsed)
        for (value in listOf(parsed.toString(), request.toString())) {
            assertFalse(value.contains("PRIVATE_"))
            assertFalse(value.contains(ID))
        }
    }

    private fun create(vararg changes: Pair<String, Any?>): MutableMap<String, Any?> =
        linkedMapOf<String, Any?>(
            "id" to ID,
            "title" to "Calendar input fixture",
            "startTimeMs" to START,
            "endTimeMs" to END
        ).apply { putAll(changes) }

    private fun valid(result: NativeCalendarRequestParseResult): NativeCalendarRequest {
        assertTrue("Expected a valid synthetic request.", result is NativeCalendarRequestParseResult.Valid)
        return (result as NativeCalendarRequestParseResult.Valid).request
    }

    private fun invalid(result: NativeCalendarRequestParseResult, code: String) {
        assertTrue("Expected controlled validation failure.", result is NativeCalendarRequestParseResult.Invalid)
        assertEquals(code, (result as NativeCalendarRequestParseResult.Invalid).errorCode)
    }

    companion object {
        private const val ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
        private const val START = 1_700_000_000_000L
        private const val END = START + 3_600_000L
    }
}
