package com.bbs.plugins.firebase_remote_config

import android.content.Context
import android.os.Build
import android.os.Looper
import java.io.File
import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.ScheduledThreadPoolExecutor
import java.util.concurrent.ThreadFactory
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

internal class RemoteConfigRuntimeBundle(val coordinator: RemoteConfigCoordinator, val dispose: () -> Unit)

/** Process ownership and sticky creation failure; never substitutes an empty store after corruption. */
internal class RemoteConfigRuntimeOwner(private val factory: (Context) -> RemoteConfigRuntimeBundle) {
    private var instance: RemoteConfigRuntimeBundle? = null
    private var failure: String? = null

    @Synchronized fun get(context: Context): RemoteConfigCoordinator {
        check(Looper.myLooper() != Looper.getMainLooper())
        failure?.let { throw RemoteConfigCoordinatorException(it) }
        instance?.let { return it.coordinator }
        var created: RemoteConfigRuntimeBundle? = null
        try {
            val application = requireNotNull(context.applicationContext)
            created = factory(application)
            created.coordinator.initialize()
            instance = created
            return created.coordinator
        } catch (error: Throwable) {
            val code = when (error) {
                is RemoteConfigStorageException -> Contract.PERSIST_FAILED
                is RemoteConfigCoordinatorException -> error.code
                is OutOfMemoryError -> Contract.LIMIT_EXCEEDED
                else -> Contract.NATIVE_UNAVAILABLE
            }
            failure = code
            created?.let { try { it.dispose() } catch (_: Throwable) { /* Keep the original controlled failure. */ } }
            if (error is Error && error !is OutOfMemoryError && error !is LinkageError) throw error
            throw RemoteConfigCoordinatorException(code)
        }
    }
}

internal object RemoteConfigRuntime {
    private val owner = RemoteConfigRuntimeOwner(::create)

    fun get(context: Context): RemoteConfigCoordinator = owner.get(context)

    internal fun storageDirectory(context: Context): File = File(context.noBackupFilesDir, "firebase-remote-config")

    private fun create(context: Context): RemoteConfigRuntimeBundle {
        require(Build.VERSION.SDK_INT >= Contract.MIN_API_LEVEL)
        val worker = ThreadPoolExecutor(1, 1, 0, TimeUnit.MILLISECONDS, ArrayBlockingQueue<Runnable>(64),
            threads("FirebaseRemoteConfig-worker"), ThreadPoolExecutor.AbortPolicy())
        val timer = ScheduledThreadPoolExecutor(1, threads("FirebaseRemoteConfig-deadline"))
        timer.removeOnCancelPolicy = true
        timer.executeExistingDelayedTasksAfterShutdownPolicy = false
        try {
            val scheduler = RemoteConfigExecutorScheduler(worker, timer)
            val store = RemoteConfigStore(RemoteConfigStorage(storageDirectory(context)))
            val sdk = FirebaseRemoteConfigSdk.create(scheduler)
            val coordinator = RemoteConfigCoordinator(sdk, store, scheduler)
            return RemoteConfigRuntimeBundle(coordinator) { worker.shutdownNow(); timer.shutdownNow() }
        } catch (error: Throwable) {
            worker.shutdownNow()
            timer.shutdownNow()
            throw error
        }
    }

    private fun threads(prefix: String): ThreadFactory {
        val sequence = AtomicInteger()
        return ThreadFactory { action -> Thread(action, "$prefix-${sequence.incrementAndGet()}").apply { isDaemon = true } }
    }
}
