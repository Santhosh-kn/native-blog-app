package com.bbs.plugins.native_contacts

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.bbs.plugins.native_contacts.NativeContactsContract as Contract
import java.io.File
import java.util.UUID
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeContactsAtomicStateFileInstrumentedTest {

    private lateinit var fixture: File
    private lateinit var directory: File

    @Before
    fun setUp() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        fixture = File(context.cacheDir, "bbs-contacts-storage-test-${UUID.randomUUID()}")
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
        val disk = NativeContactsAtomicStateFile(directory)

        assertNull(disk.locked { disk.read() })
        assertTrue(directory.isDirectory)
        assertTrue(File(directory, "state.lock").isFile)
        assertFalse(File(directory, "state.json").exists())
    }

    @Test
    fun readsAndWritesOutsideTransactionFailWithControlledErrors() {
        val disk = NativeContactsAtomicStateFile(directory)

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
        val first = NativeContactsAtomicStateFile(directory)
        val initial = "first fixture".toByteArray(Charsets.UTF_8)
        val replacement = "replacement fixture".toByteArray(Charsets.UTF_8)

        first.locked { first.writeVerified(initial) }

        val second = NativeContactsAtomicStateFile(directory)
        assertArrayEquals(initial, second.locked { second.read() })

        second.locked { second.writeVerified(replacement) }

        assertArrayEquals(replacement, first.locked { first.read() })
        assertArrayEquals(replacement, File(directory, "state.json").readBytes())
        assertFalse(File(directory, "state.json.new").exists())
        assertFalse(File(directory, "state.json.bak").exists())
    }

    @Test
    fun invalidWriteSizesPreservePreviouslyCommittedState() {
        val disk = NativeContactsAtomicStateFile(directory)
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

            val disk = NativeContactsAtomicStateFile(child)
            storageFailure { disk.locked { disk.read() } }

            assertArrayEquals(bytes, base.readBytes())
        }
    }

    @Test
    fun unfinishedFirstWriteIsDiscardedWithoutBecomingCommittedState() {
        check(directory.mkdir())
        val unfinished = File(directory, "state.json.new")
        unfinished.writeText("uncommitted fixture", Charsets.UTF_8)

        val disk = NativeContactsAtomicStateFile(directory)

        assertNull(disk.locked { disk.read() })
        assertFalse(unfinished.exists())
        assertFalse(File(directory, "state.json").exists())
        assertFalse(File(directory, "state.json.bak").exists())
    }

    @Test
    fun nestedTransactionFailsAndLaterTransactionsStillWork() {
        val disk = NativeContactsAtomicStateFile(directory)

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
        val disk = NativeContactsAtomicStateFile(directory)

        storageFailure { disk.locked { disk.read() } }

        assertTrue(directory.isFile)
        assertEquals("directory fixture", directory.readText(Charsets.UTF_8))
    }

    @Test
    fun independentInstancesSerializeReadModifyWriteTransactions() {
        val first = NativeContactsAtomicStateFile(directory)
        val second = NativeContactsAtomicStateFile(directory)
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
    fun consumptionRedactsRealDiskBeforeDeliveryAndCannotReplayAfterReopening() {
        val now = 1_700_000_000_000L
        val id = UUID.randomUUID().toString()
        val request = (
            NativeContactsRequest.fromParameters(
                Contract.PICK,
                mapOf("id" to id, "mode" to Contract.MODE_PHONE)
            ) as NativeContactsRequestValidation.Valid
        ).request

        val store = NativeContactsStore(
            NativeContactsAtomicStateFile(directory),
            clock = { now },
            processId = UUID.randomUUID().toString()
        )

        val pending = (store.begin(request) as NativeContactsBeginResult.Started).pending
        val awaiting = requireNotNull(store.markAwaitingPicker(pending.handle))
        val reading = requireNotNull(store.markReadingPicker(awaiting))

        assertNotNull(
            store.complete(
                reading,
                Contract.STATUS_SELECTED,
                selection = NativeContactsSelection(
                    displayName = PRIVATE_NAME,
                    phoneNumber = PRIVATE_PHONE
                )
            )
        )

        val base = File(directory, "state.json")
        assertTrue(base.readText(Charsets.UTF_8).contains(PRIVATE_NAME))

        val delivered = store.consume(id) as NativeContactsConsumeResult.Delivered

        assertEquals(PRIVATE_NAME, delivered.selection.displayName)
        assertEquals(PRIVATE_PHONE, delivered.selection.phoneNumber)
        assertTrue(delivered.result.consumed)
        assertNull(delivered.result.selection)

        val committed = base.readText(Charsets.UTF_8)
        assertFalse(committed.contains(PRIVATE_NAME))
        assertFalse(committed.contains(PRIVATE_PHONE))
        assertFalse(File(directory, "state.json.new").exists())
        assertFalse(File(directory, "state.json.bak").exists())

        val reopened = NativeContactsStore(
            NativeContactsAtomicStateFile(directory),
            clock = { now },
            processId = UUID.randomUUID().toString()
        )

        assertTrue(reopened.getStatus(id).consumed)

        val repeated = reopened.consume(id) as NativeContactsConsumeResult.Metadata
        assertEquals(Contract.RESULT_ALREADY_CONSUMED, repeated.errorOverride)
        assertNull(repeated.result.selection)
    }

    private fun storageFailure(action: () -> Any?) {
        try {
            action()
        } catch (failure: NativeContactsStorageException) {
            assertEquals(Contract.RESULT_PERSISTENCE_FAILED, failure.errorCode)
            assertEquals("Native contacts storage is unavailable.", failure.message)
            assertNull(failure.cause)
            return
        }

        throw AssertionError("Expected a controlled storage failure.")
    }

    companion object {
        private const val MAX_STATE_BYTES =
            Contract.MAX_STORED_RESULTS * Contract.MAX_RESULT_BYTES + 4_096

        private const val PRIVATE_NAME = "Atomic Contacts Private Fixture"
        private const val PRIVATE_PHONE = "+1 202-555-0199"
    }
}