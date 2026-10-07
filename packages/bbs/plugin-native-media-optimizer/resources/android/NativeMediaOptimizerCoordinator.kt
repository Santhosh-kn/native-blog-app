package com.bbs.plugins.native_media_optimizer

import android.os.Looper
import org.json.JSONObject
import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.Callable
import java.util.concurrent.ExecutionException
import java.util.concurrent.RejectedExecutionException
import java.util.concurrent.ThreadFactory
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicReference
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

/** One media worker and one bounded serial state worker per application process.
 * Polling remains available while a codec is working. No activity is retained.
 * A timed-out bridge acknowledgement can be resolved by querying the same ID.
 */
internal class NativeMediaOptimizerCoordinator(
    private val store: NativeMediaOptimizerStore,
    private val files: NativeMediaOptimizerFiles,
    private val resolve: (String) -> NativeMediaOptimizerPreparedSource,
    private val processor: NativeMediaOptimizerProcessor,
    private val emit: (NativeMediaOptimizerResult) -> Unit = {},
    private val bridgeWaitMs: Long = 5000
) {
    private val stateThread = AtomicReference<Thread>()
    private val state = executor("media-optimizer-state", 32, stateThread)
    private val media = executor("media-optimizer-media", 1)
    // Access to active and recovery sets is restricted to the state worker.
    private var active: Job? = null
    private val recovered = linkedSetOf<String>()
    private val notified = linkedSetOf<String>()
    override fun toString() = "NativeMediaOptimizerCoordinator(private)"

    private data class Progress(val phase: String, val percent: Int)
    private data class Outcome(val value: NativeMediaOptimizerEngineResult? = null, val code: String? = null, val cancelled: Boolean = false)
    private class Job(val request: NativeMediaOptimizerRequest, val source: NativeMediaOptimizerPreparedSource, val record: NativeMediaOptimizerRecord) {
        val cancellation = NativeMediaOptimizerCancellation()
        val latest = AtomicReference<Progress?>()
        val progressQueued = AtomicBoolean(false)
        val outcome = AtomicReference<Outcome?>()
        val storageFailed = AtomicBoolean(false)
    }

    fun start(request: NativeMediaOptimizerRequest): NativeMediaOptimizerResult = resultCall(request.id, request.operation) {
        maintain(requireCleanup = true)
        if (active != null) return@resultCall NativeMediaOptimizerResult.rejected(request.id, Contract.BUSY, request.operation)
        val source = resolve(request.sourceDocumentId)
        if (source.documentId != request.sourceDocumentId) throw NativeMediaOptimizerProcessingException(Contract.SOURCE_UNAVAILABLE)
        files.verifySource(source)
        when (val begin = store.begin(request, if (request.operation == "InspectMedia") null else source.outputDirectory.path)) {
            is NativeMediaOptimizerBeginResult.Rejected -> begin.result
            is NativeMediaOptimizerBeginResult.Started -> {
                val job = Job(request, source, begin.record)
                active = job
                try { media.execute { process(job) } }
                catch (_: RejectedExecutionException) {
                    job.outcome.set(Outcome(code = Contract.NATIVE_UNAVAILABLE))
                    finish(job)
                }
                // Acceptance acknowledges the durable pending record, not completion.
                begin.record.result
            }
        }
    }

    fun getStatus(id: String): NativeMediaOptimizerResult = resultCall(id) {
        maintain()
        val record = store.record(id) ?: return@resultCall NativeMediaOptimizerResult.rejected(id, Contract.RESULT_NOT_FOUND)
        val output = record.result.output
        if (record.result.outputAvailable && output != null &&
            (record.outputDirectory == null || files.verifiedOutput(record.outputDirectory, output) == null)) {
            if (!store.markOutputUnavailable(id)) throw NativeMediaOptimizerStorageException()
            return@resultCall store.getStatus(id)
        }
        record.result
    }

    fun cancel(id: String): NativeMediaOptimizerResult = resultCall(id) {
        maintain()
        val result = store.cancel(id)
        // Never signal the encoder until cancellation has been committed.
        if (result.status == "cancelling") active?.takeIf { it.request.id == id }?.cancellation?.cancel()
        result
    }

    fun deleteOutput(id: String): Map<String, Any> {
        if (Contract.requestId(id) != id) return deletion(id, false, Contract.INVALID_REQUEST_ID)
        return call(fallback = { code -> deletion(id, false, code) }) {
            maintain()
            val record = store.record(id) ?: return@call deletion(id, false, Contract.RESULT_NOT_FOUND)
            val result = record.result
            if (!result.isTerminal) return@call deletion(id, false, Contract.OUTPUT_IN_USE)
            val output = result.output
            val root = record.outputDirectory
            if (!result.outputAvailable || output == null || root == null) return@call deletion(id, false, Contract.OUTPUT_NOT_FOUND)
            if (files.verifiedOutput(root, output) == null) {
                if (!store.markOutputUnavailable(id)) throw NativeMediaOptimizerStorageException()
                return@call deletion(id, false, Contract.OUTPUT_NOT_FOUND)
            }
            if (!files.deleteOutput(root, output)) return@call deletion(id, false, Contract.OUTPUT_FAILED)
            // If this commit fails, the next query reconciles the missing file.
            if (!store.markOutputUnavailable(id)) throw NativeMediaOptimizerStorageException()
            deletion(id, true, null)
        }
    }

    fun availability(capabilities: () -> Map<String, Any>): Map<String, Any> = call(
        fallback = { NativeMediaOptimizerCapabilities.unavailable() }
    ) { maintain(); capabilities() }

    private fun process(job: Job) {
        val outcome = try {
            job.cancellation.check()
            val value = processor.run(job.request, job.source, job.record.handle, job.cancellation) { phase, percent ->
                progress(job, phase, percent)
            }
            job.cancellation.check(); files.verifySource(job.source)
            Outcome(value = value)
        } catch (_: NativeMediaOptimizerCancelled) { Outcome(cancelled = true) }
        catch (error: NativeMediaOptimizerProcessingException) { Outcome(code = error.code) }
        catch (_: OutOfMemoryError) { Outcome(code = Contract.LIMIT_EXCEEDED) }
        catch (_: Exception) { Outcome(code = Contract.ENCODE_FAILED) }
        catch (_: LinkageError) { Outcome(code = Contract.NATIVE_UNAVAILABLE) }
        job.outcome.set(outcome)
        // An occupied state queue cannot lose completion: each bridge call drains it.
        enqueueState { if (active === job) finish(job) }
    }

    private fun progress(job: Job, phase: String, percent: Int) {
        if (phase !in setOf("inspecting", "decoding", "encoding", "finalizing") || percent !in 0..99) return
        job.latest.set(Progress(phase, percent))
        if (!job.progressQueued.compareAndSet(false, true)) return
        if (!enqueueState {
            job.progressQueued.set(false)
            if (active !== job || job.outcome.get() != null) return@enqueueState
            val latest = job.latest.getAndSet(null) ?: return@enqueueState
            try {
                val previous = store.getStatus(job.request.id)
                if (previous.isTerminal || previous.status == "cancelling") return@enqueueState
                val candidate = previous.copy(status = "running", phase = latest.phase, progress = maxOf(previous.progress ?: 0, latest.percent))
                if (!store.update(job.record.handle, candidate)) throw NativeMediaOptimizerStorageException()
            } catch (_: Exception) {
                job.storageFailed.set(true)
                job.cancellation.cancel()
            }
        }) job.progressQueued.set(false)
    }

    private fun maintain(requireCleanup: Boolean = false) {
        active?.let { if (it.outcome.get() != null) finish(it) }
        var clean = true
        val abandoned = store.terminalCleanupRecords()
        val retained = abandoned.map { it.handle.id }.toSet()
        recovered.retainAll(retained); notified.retainAll(retained)
        abandoned.forEach { record ->
            if (record.handle.id !in recovered) {
                // InspectMedia creates no output and has no output directory in state.
                val deleted = discard(record)
                if (deleted) recovered.add(record.handle.id) else clean = false
            }
            if (record.result.status == "interrupted" && notified.add(record.handle.id)) notify(record.result)
        }
        if (requireCleanup && !clean) throw NativeMediaOptimizerProcessingException(Contract.OUTPUT_FAILED)
    }

    private fun finish(job: Job) {
        if (active !== job) return
        val outcome = job.outcome.get() ?: return
        try {
            val previous = store.getStatus(job.request.id)
            if (previous.isTerminal) { active = null; return }
            val cancelled = previous.status == "cancelling" || outcome.cancelled
            var code = if (job.storageFailed.get()) Contract.PERSIST_FAILED else outcome.code
            val value = outcome.value
            if (!cancelled && code == null && value == null) code = Contract.INVALID_NATIVE_RESPONSE
            if (!cancelled && code == null && value != null) {
                val output = value.output
                if (job.request.operation == "InspectMedia") {
                    if (output != null) code = Contract.INVALID_NATIVE_RESPONSE
                } else if (output == null || output.id != job.request.id ||
                    files.verifiedOutput(job.source.outputDirectory.path, output) == null) code = Contract.OUTPUT_FAILED
            }
            val success = !cancelled && code == null
            var terminal = if (success) previous.copy(
                status = "succeeded", phase = "completed", progress = 100,
                input = requireNotNull(value).input, output = value.output, outputAvailable = value.output != null
            ) else previous.copy(
                status = if (job.storageFailed.get() || !cancelled) "failed" else "cancelled",
                phase = if (job.storageFailed.get() || !cancelled) "failed" else "cancelled",
                errorCode = if (job.storageFailed.get()) Contract.PERSIST_FAILED else if (cancelled) null else code,
                input = null, output = null, outputAvailable = false
            )
            if (!success && !cleanup(job)) {
                // Keep ownership in the durable terminal record for another cleanup
                // attempt, and refuse new jobs while those files remain unresolved.
                terminal = previous.copy(status = "failed", phase = "failed", errorCode = Contract.OUTPUT_FAILED,
                    input = null, output = null, outputAvailable = false)
            }
            try {
                if (!store.update(job.record.handle, terminal)) throw NativeMediaOptimizerStorageException()
            } catch (_: NativeMediaOptimizerStorageException) {
                // Remove any unpublished-to-state output and retry a controlled failure
                // on the next bridge call. Never emit an uncommitted success.
                job.storageFailed.set(true)
                job.cancellation.cancel()
                job.outcome.set(Outcome(code = Contract.PERSIST_FAILED))
                cleanup(job)
                return
            }
            active = null
            notify(terminal)
        } catch (_: NativeMediaOptimizerStorageException) {
            job.storageFailed.set(true); job.cancellation.cancel(); cleanup(job)
        } catch (_: NativeMediaOptimizerProcessingException) {
            job.outcome.set(Outcome(code = Contract.OUTPUT_FAILED)); cleanup(job)
        } catch (_: IllegalArgumentException) {
            job.outcome.set(Outcome(code = Contract.INVALID_NATIVE_RESPONSE)); cleanup(job)
        }
    }

    private fun cleanup(job: Job): Boolean {
        return discard(job.record)
    }

    private fun discard(record: NativeMediaOptimizerRecord): Boolean {
        if (record.outputDirectory == null) return true
        // Abandoned failures use the same exact persisted ID/token ownership rules
        // as process interruption. No caller path and no directory-wide deletion.
        val abandoned = record.copy(result = record.result.copy(
            status = "interrupted", phase = "interrupted", errorCode = Contract.PROCESS_INTERRUPTED
        ))
        return try {
            // A removed directory contains no remaining output; safely recreate its
            // validated private slot rather than blocking every future request.
            files.directory(record.outputDirectory, true)
            files.discardInterrupted(abandoned)
        } catch (_: Exception) { false }
    }

    private fun notify(result: NativeMediaOptimizerResult) {
        try { emit(result) } catch (_: Exception) { } catch (_: LinkageError) { }
    }

    private fun enqueueState(action: () -> Unit): Boolean = try {
        state.execute { try { action() } catch (_: Exception) { } }; true
    } catch (_: RejectedExecutionException) { false }

    private fun resultCall(id: String, operation: String? = null, action: () -> NativeMediaOptimizerResult): NativeMediaOptimizerResult {
        if (Contract.requestId(id) != id) return NativeMediaOptimizerResult.rejected(id, Contract.INVALID_REQUEST_ID, operation)
        return call({ code -> NativeMediaOptimizerResult.rejected(id, code, operation) }, action)
    }

    private fun <T> call(fallback: (String) -> T, action: () -> T): T {
        if (Looper.myLooper() == Looper.getMainLooper()) return fallback(Contract.NATIVE_UNAVAILABLE)
        return try {
            if (Thread.currentThread() === stateThread.get()) action()
            else {
                val future = state.submit(Callable { action() })
                try { future.get(bridgeWaitMs, TimeUnit.MILLISECONDS) }
                catch (error: TimeoutException) { future.cancel(false); throw error }
                catch (error: InterruptedException) { future.cancel(false); Thread.currentThread().interrupt(); throw error }
            }
        } catch (error: Exception) {
            val cause = if (error is ExecutionException) error.cause else error
            fallback(when (cause) {
                is NativeMediaOptimizerStorageException -> Contract.PERSIST_FAILED
                is NativeMediaOptimizerProcessingException -> cause.code
                else -> Contract.NATIVE_UNAVAILABLE
            })
        }
    }

    /** Test-only shutdown; production lifetime is the application process. */
    internal fun closeForTests() {
        call({ Unit }) { active?.cancellation?.cancel() }
        media.shutdown()
        if (!media.awaitTermination(15, TimeUnit.SECONDS)) { media.shutdownNow(); check(media.awaitTermination(5, TimeUnit.SECONDS)) }
        call({ Unit }) { maintain() }
        state.shutdown(); check(state.awaitTermination(5, TimeUnit.SECONDS))
    }

    companion object {
        internal fun deletion(id: Any?, deleted: Boolean, code: String?): Map<String, Any> = linkedMapOf(
            "id" to (Contract.requestId(id) ?: JSONObject.NULL), "deleted" to deleted,
            "errorCode" to (code ?: JSONObject.NULL), "errorMessage" to (code?.let(Contract::message) ?: JSONObject.NULL)
        )
        private fun executor(name: String, capacity: Int, owner: AtomicReference<Thread>? = null): ThreadPoolExecutor = ThreadPoolExecutor(
            1, 1, 0, TimeUnit.MILLISECONDS, ArrayBlockingQueue<Runnable>(capacity),
            ThreadFactory { action -> Thread(action, name).apply { isDaemon = true; owner?.set(this) } },
            ThreadPoolExecutor.AbortPolicy()
        )
    }
}
