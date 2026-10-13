package com.bbs.plugins.firebase_remote_config

import android.os.SystemClock
import java.util.concurrent.Executor
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.TimeUnit

internal fun interface RemoteConfigCancellation { fun cancel() }

internal interface RemoteConfigScheduler : Executor {
    fun elapsedMs(): Long
    fun schedule(delayMs: Long, action: () -> Unit): RemoteConfigCancellation
}

/** Deadline delivery has its own worker, independent of the SDK callback queue. */
internal class RemoteConfigExecutorScheduler(
    private val worker: Executor,
    private val timer: ScheduledExecutorService
) : RemoteConfigScheduler {
    override fun execute(command: Runnable) { worker.execute(command) }
    override fun elapsedMs(): Long = SystemClock.elapsedRealtime()
    override fun schedule(delayMs: Long, action: () -> Unit): RemoteConfigCancellation {
        require(delayMs >= 0)
        val future = timer.schedule({ action() }, delayMs, TimeUnit.MILLISECONDS)
        return RemoteConfigCancellation { future.cancel(false) }
    }
}
