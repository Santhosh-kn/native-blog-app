package com.bbs.plugins.firebase_remote_config

import java.util.concurrent.RejectedExecutionException
import com.google.firebase.remoteconfig.FirebaseRemoteConfig as FirebaseSdk
import com.bbs.plugins.firebase_remote_config.RemoteConfigStorageTestFixture as StoreFixture

internal class RemoteConfigManualScheduler : RemoteConfigScheduler {
    private data class Timer(val due: Long, val action: () -> Unit, var cancelled: Boolean = false)
    private val workers = ArrayDeque<Runnable>()
    private val timers = mutableListOf<Timer>()
    var elapsed = 0L
    var wall = 1000L
    var rejectExecute = false
    var rejectSchedule = false
    override fun elapsedMs() = elapsed
    override fun execute(command: Runnable) {
        if (rejectExecute) throw RejectedExecutionException("private test diagnostics")
        workers.addLast(command)
    }
    override fun schedule(delayMs: Long, action: () -> Unit): RemoteConfigCancellation {
        if (rejectSchedule) throw RejectedExecutionException("private test diagnostics")
        val timer = Timer(elapsed + delayMs, action)
        timers.add(timer)
        return RemoteConfigCancellation { timer.cancelled = true }
    }
    fun runWorker() {
        var count = 0
        while (workers.isNotEmpty()) {
            check(++count < 500)
            workers.removeFirst().run()
        }
    }
    fun advance(ms: Long, deliverTimers: Boolean = true) {
        elapsed += ms
        wall += ms
        if (deliverTimers) fireDueTimers()
    }
    fun fireDueTimers() {
        while (true) {
            val due = timers.firstOrNull { !it.cancelled && it.due <= elapsed } ?: return
            due.cancelled = true
            due.action()
        }
    }
}

internal class RemoteConfigControlledSdk : RemoteConfigSdk {
    var initializes = 0
    var configurations = 0
    var defaultRegistrations = 0
    var fetches = 0
    var activations = 0
    var reads = 0
    var failInfo = false
    var fetchThrow: Throwable? = null
    var activateThrow: Throwable? = null
    var metadata = RemoteConfigSdkInfo("no_fetch_yet", null, 43200, 60)
    lateinit var initializeCallback: (RemoteConfigSdkOutcome<Unit>) -> Unit
    lateinit var configureCallback: (RemoteConfigSdkOutcome<Unit>) -> Unit
    lateinit var defaultsCallback: (RemoteConfigSdkOutcome<Unit>) -> Unit
    lateinit var fetchCallback: (RemoteConfigSdkOutcome<Unit>) -> Unit
    lateinit var activationCallback: (RemoteConfigSdkOutcome<Boolean>) -> Unit
    var configured: RemoteConfigSettings? = null
    var registered = emptyMap<String, RemoteConfigDefault>()

    override fun initialize(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) { initializes++; initializeCallback = callback }
    override fun configure(settings: RemoteConfigSettings, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) {
        configurations++; configured = settings; configureCallback = callback
    }
    override fun setDefaults(defaults: Map<String, RemoteConfigDefault>, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) {
        defaultRegistrations++; registered = defaults.toMap(); defaultsCallback = callback
    }
    override fun fetch(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) {
        fetches++; fetchThrow?.let { throw it }; fetchCallback = callback
    }
    override fun activate(callback: (RemoteConfigSdkOutcome<Boolean>) -> Unit) {
        activations++; activateThrow?.let { throw it }; activationCallback = callback
    }
    override fun value(key: String): RemoteConfigRawValue {
        reads++
        val entry = registered[key]
        return object : RemoteConfigRawValue {
            override val source = if (entry == null) FirebaseSdk.VALUE_SOURCE_STATIC else FirebaseSdk.VALUE_SOURCE_DEFAULT
            override fun convert(type: String): Any = entry?.value ?: when (type) {
                "string" -> ""
                "boolean" -> false
                "integer" -> 0L
                else -> 0.0
            }
        }
    }
    override fun info(): RemoteConfigSdkInfo {
        if (failInfo) throw IllegalStateException("private metadata details")
        return metadata
    }
}

internal class RemoteConfigCoordinatorFixture {
    val scheduler = RemoteConfigManualScheduler()
    val sdk = RemoteConfigControlledSdk()
    val storage = RemoteConfigMemoryStorage()
    var beforeSave: ((List<RemoteConfigStoredRecord>) -> Unit)? = null
    private val observedStorage = object : RemoteConfigRecordStorage {
        override fun load() = storage.load()
        override fun save(records: List<RemoteConfigStoredRecord>) { beforeSave?.invoke(records); storage.save(records) }
    }
    val store = RemoteConfigStore(observedStorage) { scheduler.wall }
    val coordinator = RemoteConfigCoordinator(sdk, store, scheduler) { scheduler.wall }

    fun ready() {
        coordinator.initialize()
        scheduler.runWorker()
        sdk.initializeCallback(RemoteConfigSdkOutcome.Success(Unit))
        scheduler.runWorker()
    }
    fun defaultsRequest(number: Int = 1) = RemoteConfigRequest("SetDefaults", StoreFixture.id(number), defaults =
        mapOf("quality" to RemoteConfigDefault("integer", 80L), "show" to RemoteConfigDefault("boolean", true)))
    fun defaults(number: Int = 1) {
        coordinator.start(defaultsRequest(number))
        scheduler.runWorker()
        sdk.defaultsCallback(RemoteConfigSdkOutcome.Success(Unit))
        scheduler.runWorker()
    }
    fun initializedDefaults() { ready(); defaults() }
    fun start(operation: String = "Fetch", number: Int = 2): RemoteConfigOperationResult =
        coordinator.start(StoreFixture.request(operation, number))
    fun status(number: Int = 2) = coordinator.getStatus(StoreFixture.id(number))
    fun fetchSuccess() { sdk.fetchCallback(RemoteConfigSdkOutcome.Success(Unit)); scheduler.runWorker() }
    fun activateSuccess(changed: Boolean) { sdk.activationCallback(RemoteConfigSdkOutcome.Success(changed)); scheduler.runWorker() }
}
