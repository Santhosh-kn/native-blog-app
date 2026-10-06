package com.bbs.plugins.native_calendar

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract
import java.io.File
import java.util.UUID
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeCalendarAtomicStateFileInstrumentedTest {

    private lateinit var fixture: File
    private lateinit var directory: File

    @Before
    fun setUp() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        fixture = File(context.cacheDir, "bbs-calendar-storage-test-${UUID.randomUUID()}")
        check(fixture.mkdir())
        directory = File(fixture, "state")
    }

    @After
    fun tearDown() {
        if (::fixture.isInitialized && fixture.exists()) {
            assertTrue("The isolated fixture must be removed.", fixture.deleteRecursively())
        }
    }

    @Test
    fun missingStateReturnsNullInsideTransaction() {
        val disk = NativeCalendarAtomicStateFile(directory)

        assertNull(disk.locked { disk.read() })
        assertTrue(directory.isDirectory)
        assertTrue(File(directory, "state.lock").isFile)
        assertFalse(File(directory, "state.json").exists())
    }

    @Test
    fun readsAndWritesOutsideTransactionFailWithControlledErrors() {
        val disk = NativeCalendarAtomicStateFile(directory)

        storageFailure { disk.read() }
        storageFailure { disk.writeVerified("fixture".toByteArray(Charsets.UTF_8)) }
        assertFalse(File(directory, "state.json").exists())

        disk.locked {
            disk.writeVerified("healthy".toByteArray(Charsets.UTF_8))
            assertEquals("healthy", requireNotNull(disk.read()).toString(Charsets.UTF_8))
        }
    }

    @Test
    fun verifiedWriteIsVisibleToIndependentInstancesWithoutTemporaryCopies() {
        val first = NativeCalendarAtomicStateFile(directory)
        val initial = "first fixture".toByteArray(Charsets.UTF_8)
        val replacement = "replacement fixture".toByteArray(Charsets.UTF_8)

        first.locked { first.writeVerified(initial) }

        val second = NativeCalendarAtomicStateFile(directory)
        assertArrayEquals(initial, second.locked { second.read() })

        second.locked { second.writeVerified(replacement) }

        assertArrayEquals(replacement, first.locked { first.read() })
        assertArrayEquals(replacement, File(directory, "state.json").readBytes())
        assertFalse(File(directory, "state.json.new").exists())
        assertFalse(File(directory, "state.json.bak").exists())
    }

    @Test
    fun invalidWriteSizesPreservePreviouslyCommittedState() {
        val disk = NativeCalendarAtomicStateFile(directory)
        val committed = "committed fixture".toByteArray(Charsets.UTF_8)

        disk.locked { disk.writeVerified(committed) }

        disk.locked {
            storageFailure { disk.writeVerified(ByteArray(0)) }
            storageFailure { disk.writeVerified(ByteArray(MAX_STATE_BYTES + 1)) }
            assertArrayEquals(committed, disk.read())
        }

        assertArrayEquals(committed, File(directory, "state.json").readBytes())
    }

    @Test
    fun emptyAndOversizedCommittedFilesFailWithoutResettingState() {
        for (size in listOf(0, MAX_STATE_BYTES + 1)) {
            val child = File(fixture, "invalid-$size")
            check(child.mkdir())

            val base = File(child, "state.json")
            val bytes = ByteArray(size)
            base.writeBytes(bytes)

            val disk = NativeCalendarAtomicStateFile(child)
            storageFailure { disk.locked { disk.read() } }

            assertArrayEquals(bytes, base.readBytes())
        }
    }

    @Test
    fun unfinishedFirstWriteIsDiscardedWithoutBecomingCommittedState() {
        check(directory.mkdir())
        val unfinished = File(directory, "state.json.new")
        unfinished.writeText("uncommitted fixture", Charsets.UTF_8)

        val disk = NativeCalendarAtomicStateFile(directory)

        assertNull(disk.locked { disk.read() })
        assertFalse(unfinished.exists())
        assertFalse(File(directory, "state.json").exists())
        assertFalse(File(directory, "state.json.bak").exists())
    }

    @Test
    fun nestedTransactionFailsAndLaterTransactionsStillWork() {
        val disk = NativeCalendarAtomicStateFile(directory)

        storageFailure {
            disk.locked {
                disk.locked { disk.read() }
            }
        }

        disk.locked {
            disk.writeVerified("recovered fixture".toByteArray(Charsets.UTF_8))
        }

        assertEquals(
            "recovered fixture",
            requireNotNull(disk.locked { disk.read() }).toString(Charsets.UTF_8)
        )
    }

    @Test
    fun regularFileCannotBeUsedAsStorageDirectory() {
        directory.writeText("directory fixture", Charsets.UTF_8)
        val disk = NativeCalendarAtomicStateFile(directory)

        storageFailure { disk.locked { disk.read() } }

        assertTrue(directory.isFile)
        assertEquals("directory fixture", directory.readText(Charsets.UTF_8))
    }

    @Test
    fun independentInstancesSerializeReadModifyWriteTransactions() {
        val first = NativeCalendarAtomicStateFile(directory)
        val second = NativeCalendarAtomicStateFile(directory)
        first.locked { first.writeVerified("0".toByteArray(Charsets.UTF_8)) }

        val executor = Executors.newFixedThreadPool(2)

        try {
            val futures = listOf(first, second).map { disk ->
                executor.submit<Unit> {
                    repeat(10) {
                        disk.locked {
                            val value = requireNotNull(disk.read())
                                .toString(Charsets.UTF_8)
                                .toInt()

                            disk.writeVerified(
                                (value + 1).toString().toByteArray(Charsets.UTF_8)
                            )
                        }
                    }
                }
            }

            futures.forEach { it.get(10, TimeUnit.SECONDS) }

            assertEquals(
                "20",
                requireNotNull(first.locked { first.read() }).toString(Charsets.UTF_8)
            )
        } finally {
            executor.shutdownNow()
        }
    }

    @Test
    fun pendingLaunchRecoversAsUnknownWithoutEventDetailsOrReplay() {
        val now = 1_700_000_000_000L
        val id = UUID.randomUUID().toString()
        val request = (
            NativeCalendarRequest.parseCreate(
                mapOf(
                    "id" to id,
                    "title" to PRIVATE_TITLE,
                    "description" to PRIVATE_DESCRIPTION,
                    "location" to PRIVATE_LOCATION,
                    "startTimeMs" to now,
                    "endTimeMs" to now + 3_600_000L,
                    "allDay" to false,
                    "timeZone" to "UTC",
                    "recurrence" to "FREQ=WEEKLY;COUNT=2"
                )
            ) as NativeCalendarRequestParseResult.Valid
        ).request

        val first = NativeCalendarStore(
            NativeCalendarAtomicStateFile(directory),
            clock = { now },
            processId = UUID.randomUUID().toString()
        )

        val pending = (
            first.begin(request.id, request.operation, request.target)
                as NativeCalendarBeginResult.Started
        ).pending

        val launching = requireNotNull(first.markLaunching(pending.handle))
        val base = File(directory, "state.json")
        val persisted = base.readText(Charsets.UTF_8)
        val json = JSONObject(persisted)
        val metadata = json.getJSONArray("results").getJSONObject(0)

        assertEquals(
            setOf(
                "id", "operation", "target", "status", "accepted", "success",
                "errorCode", "errorMessage", "createdAtMs", "completedAtMs"
            ),
            metadata.keys().asSequence().toSet()
        )
        assertEquals(Contract.STATUS_PENDING, metadata.getString("status"))
        assertEquals(
            NativeCalendarStore.PHASE_LAUNCHING,
            json.getJSONObject("active").getString("phase")
        )

        for (privateValue in listOf(
            PRIVATE_TITLE,
            PRIVATE_DESCRIPTION,
            PRIVATE_LOCATION,
            "FREQ=WEEKLY;COUNT=2"
        )) {
            assertFalse(persisted.contains(privateValue))
        }

        for (privateKey in listOf(
            "title", "description", "location", "startTimeMs", "endTimeMs",
            "allDay", "timeZone", "recurrence", "dateMs", "eventId"
        )) {
            assertFalse(metadata.has(privateKey))
        }

        val reopened = NativeCalendarStore(
            NativeCalendarAtomicStateFile(directory),
            clock = { now + 1L },
            processId = UUID.randomUUID().toString()
        )

        val recovered = reopened.getStatus(id)
        assertEquals(Contract.STATUS_UNKNOWN, recovered.status)
        assertEquals(Contract.INTERRUPTED, recovered.errorCode)
        assertTrue(recovered.accepted)
        assertFalse(recovered.success)
        assertTrue(JSONObject(base.readText(Charsets.UTF_8)).isNull("active"))

        assertNull(reopened.markLaunching(launching))
        assertNull(reopened.completeLaunched(launching))

        val third = NativeCalendarStore(
            NativeCalendarAtomicStateFile(directory),
            clock = { now + 2L },
            processId = UUID.randomUUID().toString()
        )

        assertEquals(Contract.STATUS_UNKNOWN, third.getStatus(id).status)
        assertEquals(recovered.completedAtMs, third.getStatus(id).completedAtMs)
        assertFalse(File(directory, "state.json.new").exists())
        assertFalse(File(directory, "state.json.bak").exists())
    }

    private fun storageFailure(action: () -> Any?) {
        try {
            action()
        } catch (failure: NativeCalendarStorageException) {
            assertEquals(Contract.PERSIST_FAILED, failure.errorCode)
            assertEquals("Native Calendar storage is unavailable.", failure.message)
            assertNull(failure.cause)
            return
        }

        throw AssertionError("Expected a controlled storage failure.")
    }

    companion object {
        private const val MAX_STATE_BYTES =
            Contract.MAX_STORED_RESULTS * Contract.MAX_RESULT_BYTES + 4_096

        private const val PRIVATE_TITLE = "Atomic Calendar Private Title"
        private const val PRIVATE_DESCRIPTION = "Atomic Calendar Private Description"
        private const val PRIVATE_LOCATION = "Atomic Calendar Private Location"
    }
}
