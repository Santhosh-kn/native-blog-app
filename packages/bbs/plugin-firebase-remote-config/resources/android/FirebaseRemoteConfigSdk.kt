package com.bbs.plugins.firebase_remote_config

import android.os.Looper
import com.google.android.gms.tasks.Task
import com.google.firebase.FirebaseApp
import com.google.firebase.remoteconfig.FirebaseRemoteConfig as FirebaseSdk
import com.google.firebase.remoteconfig.FirebaseRemoteConfigSettings
import com.google.firebase.remoteconfig.FirebaseRemoteConfigValue
import java.util.concurrent.Executor
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

/** Android Firebase SDK only. No Analytics, automatic fetch, listener or activation. */
internal class FirebaseRemoteConfigSdk(
    private val remote: FirebaseSdk,
    private val callbackExecutor: Executor
) : RemoteConfigSdk {
    override fun initialize(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) {
        start(Contract.NOT_INITIALIZED, { remote.ensureInitialized() }, { completed ->
            requireNotNull(completed.result)
            Unit
        }, callback)
    }

    override fun configure(settings: RemoteConfigSettings, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) {
        if (settings.minimumFetchIntervalSeconds !in 0..Contract.MAX_FETCH_INTERVAL_SECONDS ||
            settings.fetchTimeoutSeconds !in 1..Contract.MAX_FETCH_TIMEOUT_SECONDS) {
            emit(RemoteConfigSdkOutcome.Failure(Contract.INVALID_SETTINGS), callback)
            return
        }
        start(Contract.CONFIGURE_FAILED, {
            remote.setConfigSettingsAsync(FirebaseRemoteConfigSettings.Builder()
                .setMinimumFetchIntervalInSeconds(settings.minimumFetchIntervalSeconds)
                .setFetchTimeoutInSeconds(settings.fetchTimeoutSeconds).build())
        }, { Unit }, callback)
    }

    override fun setDefaults(defaults: Map<String, RemoteConfigDefault>, callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) {
        val error = defaultsError(defaults)
        if (error != null) {
            emit(RemoteConfigSdkOutcome.Failure(error), callback)
            return
        }
        // Long and Double retain the explicitly registered PHP type; SDK persists strings.
        val values = defaults.mapValues { (_, entry) -> entry.value }
        start(Contract.DEFAULTS_FAILED, { remote.setDefaultsAsync(values) }, { Unit }, callback)
    }

    override fun fetch(callback: (RemoteConfigSdkOutcome<Unit>) -> Unit) {
        start(Contract.FETCH_FAILED, { remote.fetch() }, { Unit }, callback)
    }

    override fun activate(callback: (RemoteConfigSdkOutcome<Boolean>) -> Unit) {
        start(Contract.ACTIVATION_FAILED, { remote.activate() }, { completed -> requireNotNull(completed.result) }, callback)
    }

    override fun value(key: String): RemoteConfigRawValue {
        requireWorker()
        require(Contract.key(key) != null)
        return FirebaseRemoteConfigRawValue(remote.getValue(key))
    }

    override fun info(): RemoteConfigSdkInfo {
        requireWorker()
        val info = remote.info
        val status = when (info.lastFetchStatus) {
            FirebaseSdk.LAST_FETCH_STATUS_NO_FETCH_YET -> "no_fetch_yet"
            FirebaseSdk.LAST_FETCH_STATUS_SUCCESS -> "success"
            FirebaseSdk.LAST_FETCH_STATUS_FAILURE -> "failure"
            FirebaseSdk.LAST_FETCH_STATUS_THROTTLED -> "throttled"
            else -> "unknown"
        }
        return RemoteConfigSdkInfo(status, info.fetchTimeMillis.takeIf { it > 0 },
            info.configSettings.minimumFetchIntervalInSeconds, info.configSettings.fetchTimeoutInSeconds)
    }

    private fun <T, R> start(
        failureCode: String, factory: () -> Task<T>, mapper: (Task<T>) -> R,
        callback: (RemoteConfigSdkOutcome<R>) -> Unit
    ) {
        if (Looper.myLooper() == Looper.getMainLooper()) {
            emit(RemoteConfigSdkOutcome.Failure(Contract.NATIVE_UNAVAILABLE), callback)
            return
        }
        try { RemoteConfigTaskCompletion.observe(factory(), callbackExecutor, failureCode, mapper, callback) }
        catch (_: LinkageError) { emit(RemoteConfigSdkOutcome.Failure(Contract.NATIVE_UNAVAILABLE), callback) }
        catch (_: OutOfMemoryError) { emit(RemoteConfigSdkOutcome.Failure(Contract.LIMIT_EXCEEDED), callback) }
        catch (error: Exception) { emit(RemoteConfigTaskCompletion.failure(error, failureCode), callback) }
    }

    private fun <T> emit(outcome: RemoteConfigSdkOutcome<T>, callback: (RemoteConfigSdkOutcome<T>) -> Unit) {
        callbackExecutor.execute { callback(outcome) }
    }

    companion object {
        fun create(callbackExecutor: Executor): FirebaseRemoteConfigSdk {
            requireWorker()
            // Reuse the existing default Firebase project and generated Google resources.
            return FirebaseRemoteConfigSdk(FirebaseSdk.getInstance(FirebaseApp.getInstance()), callbackExecutor)
        }

        internal fun defaultsError(defaults: Map<String, RemoteConfigDefault>): String? {
            if (defaults.isEmpty()) return Contract.INVALID_OPTIONS
            if (defaults.size > Contract.MAX_PARAMETERS) return Contract.LIMIT_EXCEEDED
            for ((key, entry) in defaults) {
                if (Contract.key(key) == null) return Contract.INVALID_KEY
                if (entry.type !in Contract.TYPES) return Contract.INVALID_TYPE
                if (Contract.typedValue(entry.type, entry.value) == null) return Contract.INVALID_VALUE
            }
            val wireDefaults = defaults.mapValues { (_, entry) -> mapOf("type" to entry.type, "value" to entry.value) }
            return Contract.sizeError(mapOf("id" to "123e4567-e89b-42d3-a456-426614174000", "defaults" to wireDefaults))
        }

        private fun requireWorker() { check(Looper.myLooper() != Looper.getMainLooper()) }
    }
}

internal class FirebaseRemoteConfigRawValue(private val value: FirebaseRemoteConfigValue) : RemoteConfigRawValue {
    override val source: Int get() = value.source
    override fun convert(type: String): Any = when (type) {
        "string" -> value.asString()
        "boolean" -> value.asBoolean()
        "integer" -> value.asLong()
        "decimal" -> value.asDouble()
        else -> throw IllegalArgumentException()
    }
}
