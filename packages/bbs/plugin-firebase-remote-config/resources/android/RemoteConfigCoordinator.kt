package com.bbs.plugins.firebase_remote_config

import android.os.Looper
import org.json.JSONObject
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

internal class RemoteConfigCoordinatorException(val code: String) : Exception("Remote Config operation unavailable") {
    init { require(code in Contract.ERRORS) }
}

/** All public calls are worker-only. One instance owns the SDK and store for this process. */
internal class RemoteConfigCoordinator(
    private val sdk: RemoteConfigSdk,
    private val store: RemoteConfigStore,
    private val scheduler: RemoteConfigScheduler,
    private val clock: () -> Long = System::currentTimeMillis
) {
    private enum class Phase { QUEUED, CONFIGURE, DEFAULTS, FETCH, ACTIVATE }
    private class Flight(val request: RemoteConfigRequest, var record: RemoteConfigOperationResult, val deadline: Long) {
        var phase = Phase.QUEUED
        var generation = 0
        var outstanding = false
        var timer: RemoteConfigCancellation? = null
    }

    private var ready = false
    private var defaultsReady = false
    private var initializationStarted = false
    private var initializationBusy = false
    private var initializationOutstanding = false
    private var initializationExpired = false
    private var initializationError: String? = null
    private var initializationDeadline = 0L
    private var initializationTimer: RemoteConfigCancellation? = null
    private var flight: Flight? = null
    private var fault: String? = null

    init { requireWorker() }

    /** Initializes only SDK caches; never fetches, activates or registers implicit defaults. */
    @Synchronized fun initialize() {
        ensureHealthy()
        if (initializationStarted) return
        initializationStarted = true
        initializationBusy = true
        initializationDeadline = deadline(LOCAL_DEADLINE_MS)
        try {
            initializationTimer = scheduler.schedule(LOCAL_DEADLINE_MS) { guardedCallback { initializationTimeout() } }
            scheduler.execute { guardedCallback { startInitialization() } }
        } catch (_: Exception) {
            cancelInitializationTimer()
            initializationBusy = false
            initializationError = Contract.NATIVE_UNAVAILABLE
        }
    }

    @Synchronized fun availability(): Map<String, Any> {
        requireWorker()
        val error = fault ?: initializationError
        return mapOf("platform" to "android", "available" to (error == null), "errorCode" to (error ?: JSONObject.NULL))
    }

    @Synchronized fun start(request: RemoteConfigRequest): RemoteConfigOperationResult {
        ensureHealthy()
        val input = RemoteConfigFingerprint.validated(request)
        val id = requireNotNull(input.id)
        // Replay/conflict is checked before readiness and SDK-busy gates, including late Tasks.
        if (stored { store.get(id) }.status != "not_found") {
            return when (val admission = stored { store.admit(input) }) {
                is RemoteConfigAdmission.Replay -> admission.result
                is RemoteConfigAdmission.Rejected -> admission.result
                is RemoteConfigAdmission.Accepted -> throw IllegalStateException()
            }
        }
        if (!ready) return reject(input, Contract.NOT_INITIALIZED)
        if (initializationBusy || flight != null) return reject(input, Contract.BUSY)
        if (input.operation in setOf("Fetch", "Activate", "FetchAndActivate") && !defaultsReady) {
            return reject(input, Contract.DEFAULTS_NOT_READY)
        }
        val end = deadline(duration(input.operation)) // Includes admission, queue and callbacks.
        val admission = stored { store.admit(input) }
        if (admission !is RemoteConfigAdmission.Accepted) return when (admission) {
            is RemoteConfigAdmission.Replay -> admission.result
            is RemoteConfigAdmission.Rejected -> admission.result
            else -> throw IllegalStateException()
        }
        val current = Flight(input, admission.result, end)
        flight = current
        if (input.operation == "SetDefaults") defaultsReady = false
        try {
            current.timer = scheduler.schedule(remaining(end)) { guardedCallback { timeout(current) } }
            scheduler.execute { guardedCallback { begin(current) } }
        } catch (_: Exception) {
            finishFailure(current, Contract.NATIVE_UNAVAILABLE)
            releaseIfSettled(current)
        }
        return stored { store.get(id) }
    }

    @Synchronized fun getStatus(id: String): RemoteConfigOperationResult {
        ensureHealthy()
        return stored { store.get(id) }
    }

    @Synchronized fun getInfo(): Map<String, Any> {
        requireWorker()
        fault?.let { return RemoteConfigSdkInfo.failure(it) }
        if (!ready && initializationError != null) return RemoteConfigSdkInfo.failure(requireNotNull(initializationError))
        val metadata = if (ready) readInfo() ?: return RemoteConfigSdkInfo.failure(Contract.INVALID_NATIVE_RESPONSE)
            else RemoteConfigSdkInfo("no_fetch_yet", null, Contract.DEFAULT_FETCH_INTERVAL_SECONDS, Contract.DEFAULT_FETCH_TIMEOUT_SECONDS)
        return metadata.toBridgeMap(ready, defaultsReady, flight?.record?.takeUnless { it.terminal }?.id,
            initializationBusy || flight != null)
    }

    @Synchronized fun getValues(values: Map<String, String>): Map<String, Any> {
        ensureHealthy()
        val current = flight
        if (current != null && (current.phase in setOf(Phase.DEFAULTS, Phase.ACTIVATE) ||
            current.phase == Phase.QUEUED && current.request.operation in setOf("SetDefaults", "Activate"))) {
            return RemoteConfigValuesReader.failure(Contract.BUSY)
        }
        // The same lock prevents activation/defaults admission during a multi-key read.
        return RemoteConfigValuesReader.read(values, sdk, ready, defaultsReady)
    }

    @Synchronized private fun startInitialization() {
        if (fault != null || !initializationBusy || initializationExpired) return
        if (expired(initializationDeadline)) { initializationTimeout(); return }
        initializationOutstanding = true
        try { sdk.initialize { outcome -> dispatch { initialized(outcome) } } }
        catch (_: LinkageError) { initialized(RemoteConfigSdkOutcome.Failure(Contract.NATIVE_UNAVAILABLE)) }
        catch (_: OutOfMemoryError) { initialized(RemoteConfigSdkOutcome.Failure(Contract.LIMIT_EXCEEDED)) }
        catch (_: Exception) { initialized(RemoteConfigSdkOutcome.Failure(Contract.NOT_INITIALIZED)) }
    }

    @Synchronized private fun initialized(outcome: RemoteConfigSdkOutcome<Unit>) {
        if (!initializationOutstanding) return // Duplicate initialization callback.
        initializationOutstanding = false
        initializationBusy = false
        cancelInitializationTimer()
        if (fault != null) return
        when (outcome) {
            is RemoteConfigSdkOutcome.Success -> { ready = true; initializationError = null }
            is RemoteConfigSdkOutcome.Failure -> { ready = false; initializationError = controlled(outcome).code }
        }
        // Late successful cache initialization is usable, but does not register defaults.
    }

    @Synchronized private fun initializationTimeout() {
        if (!initializationBusy || initializationExpired) return
        if (!expired(initializationDeadline)) {
            try { initializationTimer = scheduler.schedule(remaining(initializationDeadline)) { guardedCallback { initializationTimeout() } } }
            catch (_: Exception) {
                initializationError = Contract.NATIVE_UNAVAILABLE
                initializationExpired = true
                if (!initializationOutstanding) initializationBusy = false
            }
            return
        }
        initializationExpired = true
        initializationError = Contract.OPERATION_TIMEOUT
        initializationTimer = null
        if (!initializationOutstanding) initializationBusy = false
    }

    @Synchronized private fun begin(current: Flight) {
        if (flight !== current || fault != null || current.record.terminal || current.phase != Phase.QUEUED) return
        if (expired(current.deadline)) { timeout(current); return }
        transition(current, current.record.running(now()))
        when (current.request.operation) {
            "Configure" -> invokeUnit(current, Phase.CONFIGURE, Contract.CONFIGURE_FAILED) { callback ->
                sdk.configure(requireNotNull(current.request.settings), callback)
            }
            "SetDefaults" -> invokeUnit(current, Phase.DEFAULTS, Contract.DEFAULTS_FAILED) { callback ->
                sdk.setDefaults(current.request.defaults, callback)
            }
            "Fetch", "FetchAndActivate" -> invokeUnit(current, Phase.FETCH, Contract.FETCH_FAILED, sdk::fetch)
            "Activate" -> invokeActivation(current)
        }
    }

    private fun invokeUnit(current: Flight, phase: Phase, failureCode: String,
        action: ((RemoteConfigSdkOutcome<Unit>) -> Unit) -> Unit) {
        if (expired(current.deadline)) { timeout(current); return }
        current.phase = phase
        val generation = ++current.generation
        current.outstanding = true
        try { action { outcome -> dispatch { completedUnit(current, generation, outcome) } } }
        catch (_: LinkageError) { completedUnit(current, generation, RemoteConfigSdkOutcome.Failure(Contract.NATIVE_UNAVAILABLE)) }
        catch (_: OutOfMemoryError) { completedUnit(current, generation, RemoteConfigSdkOutcome.Failure(Contract.LIMIT_EXCEEDED)) }
        catch (error: Exception) { completedUnit(current, generation, RemoteConfigTaskCompletion.failure(error, failureCode)) }
    }

    private fun invokeActivation(current: Flight) {
        if (expired(current.deadline)) { timeout(current); return }
        current.phase = Phase.ACTIVATE
        val generation = ++current.generation
        current.outstanding = true
        try { sdk.activate { outcome -> dispatch { completedActivation(current, generation, outcome) } } }
        catch (_: LinkageError) { completedActivation(current, generation, RemoteConfigSdkOutcome.Failure(Contract.NATIVE_UNAVAILABLE)) }
        catch (_: OutOfMemoryError) { completedActivation(current, generation, RemoteConfigSdkOutcome.Failure(Contract.LIMIT_EXCEEDED)) }
        catch (error: Exception) { completedActivation(current, generation, RemoteConfigTaskCompletion.failure(error, Contract.ACTIVATION_FAILED)) }
    }

    @Synchronized private fun completedUnit(current: Flight, generation: Int, outcome: RemoteConfigSdkOutcome<Unit>) {
        if (!settleCallback(current, generation)) return
        if (outcome is RemoteConfigSdkOutcome.Failure) {
            val failure = controlled(outcome)
            finishFailure(current, failure.code, failure.throttleEndTimeMs)
        } else if (current.phase == Phase.FETCH && current.request.operation == "FetchAndActivate") {
            transition(current, current.record.markFetched())
            // Re-check the total deadline after persistence, immediately before activation.
            invokeActivation(current)
            return
        } else {
            transition(current, current.record.succeeded(now()))
            if (current.phase == Phase.DEFAULTS) defaultsReady = true
        }
        releaseIfSettled(current)
    }

    @Synchronized private fun completedActivation(current: Flight, generation: Int, outcome: RemoteConfigSdkOutcome<Boolean>) {
        if (!settleCallback(current, generation)) return
        when (outcome) {
            is RemoteConfigSdkOutcome.Success -> transition(current, current.record.succeeded(now(), outcome.value))
            is RemoteConfigSdkOutcome.Failure -> {
                val failure = controlled(outcome)
                finishFailure(current, failure.code, failure.throttleEndTimeMs)
            }
        }
        releaseIfSettled(current)
    }

    private fun settleCallback(current: Flight, generation: Int): Boolean {
        if (flight !== current || current.generation != generation || !current.outstanding) return false
        current.outstanding = false
        if (fault != null) { releaseIfSettled(current); return false }
        if (!current.record.terminal && expired(current.deadline)) timeout(current)
        if (current.record.terminal) { releaseIfSettled(current); return false }
        return true
    }

    @Synchronized private fun timeout(current: Flight) {
        if (flight !== current || fault != null || current.record.terminal) return
        if (!expired(current.deadline)) {
            try { current.timer = scheduler.schedule(remaining(current.deadline)) { guardedCallback { timeout(current) } } }
            catch (_: Exception) { finishFailure(current, Contract.NATIVE_UNAVAILABLE); releaseIfSettled(current) }
            return
        }
        finishFailure(current, Contract.OPERATION_TIMEOUT)
        releaseIfSettled(current) // Keep SDK gate/read block while an uncancellable Task is outstanding.
    }

    private fun finishFailure(current: Flight, code: String, throttle: Long? = null) {
        if (current.record.terminal) return
        if (current.record.status == "pending") transition(current, current.record.running(now()))
        transition(current, current.record.failed(code, now(), throttle))
    }

    private fun transition(current: Flight, next: RemoteConfigOperationResult) {
        val replaced = stored { store.replace(current.record, next) }
        if (!replaced) {
            fault = Contract.PERSIST_FAILED
            throw RemoteConfigCoordinatorException(Contract.PERSIST_FAILED)
        }
        current.record = next
    }

    private fun releaseIfSettled(current: Flight) {
        if (flight !== current || current.outstanding) return
        current.timer?.let { try { it.cancel() } catch (_: Exception) { } }
        current.timer = null
        flight = null
    }

    private fun dispatch(action: () -> Unit) {
        try { scheduler.execute { guardedCallback(action) } }
        catch (_: Exception) { synchronized(this) { fault = Contract.NATIVE_UNAVAILABLE } }
    }

    private fun guardedCallback(action: () -> Unit) {
        try { action() }
        catch (error: RemoteConfigCoordinatorException) { synchronized(this) { fault = error.code } }
        catch (_: OutOfMemoryError) { synchronized(this) { fault = Contract.LIMIT_EXCEEDED } }
        catch (_: LinkageError) { synchronized(this) { fault = Contract.NATIVE_UNAVAILABLE } }
        catch (_: Exception) { synchronized(this) { fault = Contract.NATIVE_UNAVAILABLE } }
    }

    private fun <T> stored(action: () -> T): T = try { action() }
    catch (_: RemoteConfigStorageException) { fault = Contract.PERSIST_FAILED; throw RemoteConfigCoordinatorException(Contract.PERSIST_FAILED) }
    catch (_: OutOfMemoryError) { fault = Contract.LIMIT_EXCEEDED; throw RemoteConfigCoordinatorException(Contract.LIMIT_EXCEEDED) }

    private fun duration(operation: String): Long {
        if (operation !in setOf("Fetch", "FetchAndActivate")) return LOCAL_DEADLINE_MS
        val info = readInfo() ?: throw RemoteConfigCoordinatorException(Contract.INVALID_NATIVE_RESPONSE)
        return info.fetchTimeoutSeconds * 1000L + if (operation == "FetchAndActivate") 20000L else 5000L
    }

    private fun readInfo(): RemoteConfigSdkInfo? = try {
        sdk.info().takeIf { it.toBridgeMap(ready, defaultsReady, null, false)["success"] == true }
    } catch (_: LinkageError) { null } catch (_: OutOfMemoryError) { null } catch (_: Exception) { null }

    private fun controlled(failure: RemoteConfigSdkOutcome.Failure): RemoteConfigSdkOutcome.Failure {
        if (failure.code !in Contract.ERRORS || failure.throttleEndTimeMs != null &&
            (failure.code != Contract.THROTTLED || failure.throttleEndTimeMs !in 1..Contract.MAX_SAFE_INTEGER)) {
            return RemoteConfigSdkOutcome.Failure(Contract.INVALID_NATIVE_RESPONSE)
        }
        return failure
    }

    private fun reject(request: RemoteConfigRequest, code: String) = RemoteConfigOperationResult.rejected(request.id, request.operation, code)
    private fun ensureHealthy() { requireWorker(); fault?.let { throw RemoteConfigCoordinatorException(it) } }
    private fun requireWorker() { check(Looper.myLooper() != Looper.getMainLooper()) }
    private fun now(): Long = clock().coerceIn(1, Contract.MAX_SAFE_INTEGER)
    private fun deadline(duration: Long): Long = Math.addExact(scheduler.elapsedMs(), duration)
    private fun remaining(end: Long): Long = (end - scheduler.elapsedMs()).coerceAtLeast(0)
    private fun expired(end: Long): Boolean = scheduler.elapsedMs() >= end
    private fun cancelInitializationTimer() {
        initializationTimer?.let { try { it.cancel() } catch (_: Exception) { } }
        initializationTimer = null
    }

    companion object { const val LOCAL_DEADLINE_MS = 15000L }
}
