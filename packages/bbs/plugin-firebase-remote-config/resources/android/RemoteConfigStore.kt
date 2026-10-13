package com.bbs.plugins.firebase_remote_config

import android.os.Looper
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

internal sealed interface RemoteConfigAdmission {
    data class Accepted(val result: RemoteConfigOperationResult) : RemoteConfigAdmission
    data class Replay(val result: RemoteConfigOperationResult) : RemoteConfigAdmission
    data class Rejected(val result: RemoteConfigOperationResult) : RemoteConfigAdmission
}

/** Save before acknowledging; stale callbacks cannot change an already-terminal record. */
internal class RemoteConfigStore(
    private val storage: RemoteConfigRecordStorage,
    private val clock: () -> Long = System::currentTimeMillis
) {
    private var records = linkedMapOf<String, RemoteConfigStoredRecord>()
    private var faulted = false

    init {
        requireWorker()
        val loaded = storage.load()
        // Validate even an injected storage provider before exposing records.
        RemoteConfigRecordCodec.encode(loaded)
        records = loaded.associateByTo(linkedMapOf()) { requireNotNull(it.result.id) }
        if (loaded.any { !it.result.terminal }) {
            val now = clock()
            require(now in 1..Contract.MAX_SAFE_INTEGER)
            val recovered = records.mapValuesTo(linkedMapOf()) { (_, entry) ->
                if (entry.result.terminal) entry else {
                    val running = if (entry.result.status == "pending") entry.result.running(now) else entry.result
                    entry.copy(result = running.failed(Contract.PROCESS_INTERRUPTED, now))
                }
            }
            commit(recovered)
        }
    }

    @Synchronized fun admit(request: RemoteConfigRequest): RemoteConfigAdmission {
        checkHealthy()
        val input = RemoteConfigFingerprint.validated(request)
        val id = requireNotNull(input.id)
        val fingerprint = RemoteConfigFingerprint.of(input)
        records[id]?.let { existing ->
            return if (existing.fingerprint == fingerprint) RemoteConfigAdmission.Replay(existing.result)
            else rejected(input, Contract.REQUEST_ID_CONFLICT)
        }
        if (records.values.any { !it.result.terminal }) return rejected(input, Contract.BUSY)
        val candidate = LinkedHashMap(records)
        while (candidate.size >= RemoteConfigRecordCodec.MAX_RECORDS) {
            val oldest = candidate.entries.firstOrNull { it.value.result.terminal }
                ?: return rejected(input, Contract.BUSY)
            candidate.remove(oldest.key)
        }
        val result = RemoteConfigOperationResult.pending(input)
        candidate[id] = RemoteConfigStoredRecord(fingerprint, result)
        commit(candidate)
        return RemoteConfigAdmission.Accepted(result)
    }

    @Synchronized fun get(id: String): RemoteConfigOperationResult {
        checkHealthy()
        require(Contract.requestId(id) != null)
        return records[id]?.result ?: RemoteConfigOperationResult.notFound(id)
    }

    @Synchronized fun activeId(): String? {
        checkHealthy()
        return records.values.firstOrNull { !it.result.terminal }?.result?.id
    }

    /** Compare the complete previous record, not just its ID, before changing it. */
    @Synchronized fun replace(expected: RemoteConfigOperationResult, next: RemoteConfigOperationResult): Boolean {
        checkHealthy()
        val entry = records[expected.id] ?: return false
        if (entry.result != expected || expected.terminal || next == expected) return false
        require(next.id == expected.id && next.operation == expected.operation)
        require(RemoteConfigRecordCodec.decodeResult(next.toBridgeMap()) == next)
        if (expected.status == "pending") {
            require(next.status == "running" && next.fetched == null)
        } else {
            require(next.startedAtMs == expected.startedAtMs)
            require(expected.fetched != true || next.fetched == true)
            require(next.terminal || next == expected.markFetched())
        }
        val candidate = LinkedHashMap(records)
        candidate[requireNotNull(next.id)] = entry.copy(result = next)
        commit(candidate)
        return true
    }

    private fun rejected(request: RemoteConfigRequest, code: String) = RemoteConfigAdmission.Rejected(
        RemoteConfigOperationResult.rejected(request.id, request.operation, code))

    private fun commit(candidate: LinkedHashMap<String, RemoteConfigStoredRecord>) {
        try {
            storage.save(candidate.values.toList())
            records = candidate
        } catch (failure: Throwable) {
            // A failed acknowledgement can hide a committed write. Freeze this store
            // instead of overwriting durable history using possibly-stale memory.
            faulted = true
            if (failure is Error) throw failure
            throw RemoteConfigStorageException()
        }
    }

    private fun checkHealthy() {
        requireWorker()
        if (faulted) throw RemoteConfigStorageException()
    }

    private fun requireWorker() { check(Looper.myLooper() != Looper.getMainLooper()) }
}
