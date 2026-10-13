package com.bbs.plugins.firebase_remote_config

import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract
import com.bbs.plugins.firebase_remote_config.RemoteConfigStorageTestFixture as Fixture

class RemoteConfigRecordCodecInstrumentedTest {
    private fun invalid(result: RemoteConfigOperationResult) { assertNull(RemoteConfigRecordCodec.decodeResult(result.toBridgeMap())) }
    private fun corrupt(text: String) {
        try { RemoteConfigRecordCodec.decode(text.toByteArray(Charsets.UTF_8)); fail("Corrupt history accepted") }
        catch (_: RemoteConfigStorageException) { /* Expected. */ }
    }
    private fun document(result: RemoteConfigOperationResult = Fixture.pending()) =
        String(RemoteConfigRecordCodec.encode(listOf(Fixture.record(result))), Charsets.UTF_8)

    @Test fun everyMutationLifecycleRoundTripsWithNullAndFalseIntact() {
        for (operation in Contract.OPERATIONS) {
            val pending = Fixture.pending(operation)
            val running = pending.running(1000)
            val finalRunning = if (operation == "FetchAndActivate") running.markFetched() else running
            val activation = if (operation in setOf("Activate", "FetchAndActivate")) false else null
            val outcomes = listOf(pending, running, finalRunning.succeeded(2000, activation),
                finalRunning.failed(Contract.OPERATION_TIMEOUT, 2000), finalRunning.failed(Contract.PROCESS_INTERRUPTED, 2000),
                finalRunning.failed(Contract.THROTTLED, 2000, 3000))
            for (result in outcomes) {
                val records = listOf(Fixture.record(result))
                assertEquals(records, RemoteConfigRecordCodec.decode(RemoteConfigRecordCodec.encode(records)))
            }
        }
    }
    @Test fun activatedTrueAndCombinedFetchProgressRoundTrip() {
        val running = Fixture.pending("FetchAndActivate").running(1000).markFetched()
        for (result in listOf(running, running.succeeded(2000, true))) {
            assertEquals(result, RemoteConfigRecordCodec.decodeResult(JSONObject(result.toBridgeMap())))
        }
    }
    @Test fun emptyHistoryIsValid() { assertEquals(emptyList<RemoteConfigStoredRecord>(), RemoteConfigRecordCodec.decode(RemoteConfigRecordCodec.encode(emptyList()))) }
    @Test fun canonicalFormatRejectsDuplicatesAndTrailingInput() {
        val text = document()
        corrupt(text.replace("\"version\":1", "\"version\":1,\"version\":1"))
        corrupt(text + "{}")
        corrupt(text + " ")
    }
    @Test fun unsupportedVersionsAndUnknownFieldsFailClosed() {
        corrupt(document().replace("\"version\":1", "\"version\":2"))
        corrupt(document().replace("\"version\":1", "\"version\":1.0"))
        corrupt(document().replace("\"version\":1", "\"extra\":true,\"version\":1"))
    }
    @Test fun invalidFingerprintAndNumericTimestampAreRejected() {
        corrupt(document().replace("a".repeat(64), "A".repeat(64)))
        corrupt(document(Fixture.pending().running(1000)).replace("\"startedAtMs\":1000", "\"startedAtMs\":1000.0"))
    }
    @Test fun malformedUtf8DeepAndOversizedFilesAreRejectedBeforeParsing() {
        try { RemoteConfigRecordCodec.decode(byteArrayOf(0xc3.toByte(), 0x28)); fail() } catch (_: RemoteConfigStorageException) { }
        corrupt("[".repeat(10000) + "]".repeat(10000))
        corrupt(" ".repeat(RemoteConfigRecordCodec.MAX_STORAGE_BYTES + 1))
        corrupt("")
    }
    @Test(expected = IllegalArgumentException::class)
    fun duplicateIdsAreRejected() { RemoteConfigRecordCodec.encode(listOf(Fixture.record(), Fixture.record())) }
    @Test(expected = IllegalArgumentException::class)
    fun multipleActiveRecordsAreRejected() {
        RemoteConfigRecordCodec.encode(listOf(Fixture.record(), Fixture.record(Fixture.pending(number = 2))))
    }
    @Test(expected = IllegalArgumentException::class)
    fun historyCountIsBounded() {
        RemoteConfigRecordCodec.encode(List(51) { index -> Fixture.record(Fixture.pending(number = index + 1).running(1000).succeeded(2000)) })
    }
    @Test fun rejectedNotFoundAndSyntheticRecordsAreNeverDurable() {
        invalid(RemoteConfigOperationResult.rejected(Fixture.id(), "Fetch", Contract.BUSY))
        invalid(RemoteConfigOperationResult.notFound(Fixture.id()))
        invalid(Fixture.pending().copy(status = "unconfirmed", accepted = false))
    }
    @Test fun resultSchemaRejectsMissingUnknownOrCoercedFields() {
        val result = Fixture.pending().toBridgeMap()
        assertNull(RemoteConfigRecordCodec.decodeResult(result - "fetched"))
        assertNull(RemoteConfigRecordCodec.decodeResult(result + ("extra" to true)))
        assertNull(RemoteConfigRecordCodec.decodeResult(result + ("accepted" to 1)))
        assertNull(RemoteConfigRecordCodec.decodeResult(result + ("terminal" to "false")))
        assertNull(RemoteConfigRecordCodec.decodeResult(result + ("id" to "invalid")))
        assertNull(RemoteConfigRecordCodec.decodeResult(result + ("operation" to "GetInfo")))
        assertNull(RemoteConfigRecordCodec.decodeResult(result + ("fetched" to "true")))
    }
    @Test fun timestampsAndLifecycleFlagsMustBeConsistent() {
        val running = Fixture.pending().running(1000)
        invalid(running.copy(startedAtMs = 0))
        invalid(running.copy(terminal = true))
        invalid(running.copy(success = true))
        invalid(running.copy(finishedAtMs = 2000))
        invalid(running.succeeded(2000).copy(finishedAtMs = 999))
        invalid(running.copy(startedAtMs = Contract.MAX_SAFE_INTEGER + 1))
        invalid(Fixture.pending().copy(startedAtMs = 1000))
    }
    @Test fun outcomesCannotContradictTheirOperation() {
        val running = Fixture.pending().running(1000)
        invalid(running.succeeded(2000).copy(fetched = null))
        invalid(running.copy(activated = false))
        invalid(running.copy(fetched = false))
        invalid(Fixture.pending("Configure").running(1000).succeeded(2000).copy(fetched = true))
        invalid(Fixture.pending("Activate").running(1000).succeeded(2000, false).copy(status = "succeeded"))
        invalid(Fixture.pending("FetchAndActivate").running(1000).markFetched().succeeded(2000, true).copy(fetched = null))
    }
    @Test fun failuresCannotClaimActivationOrLeakExceptionText() {
        val failed = Fixture.pending().running(1000).failed(Contract.FETCH_FAILED, 2000)
        invalid(failed.copy(errorCode = "SDK details"))
        invalid(failed.copy(errorCode = null))
        invalid(failed.copy(activated = true))
        invalid(failed.copy(throttleEndTimeMs = 3000))
        invalid(failed.copy(status = "timed_out"))
        invalid(failed.copy(status = "interrupted"))
    }
}
