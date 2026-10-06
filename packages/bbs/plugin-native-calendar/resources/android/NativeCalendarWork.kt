package com.bbs.plugins.native_calendar

import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.Callable
import java.util.concurrent.Future
import java.util.concurrent.RejectedExecutionException
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.ScheduledThreadPoolExecutor
import java.util.concurrent.ThreadFactory
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.TimeUnit

/**
 * Process-lifetime workers for Calendar metadata.
 *
 * State tasks run sequentially on a worker thread with a bounded queue.
 * Calendar intents are launched separately on the Android main thread.
 *
 * Deadline callbacks must remain short and enqueue any state-file work.
 * Callers must handle queue rejection, task failures and wait timeouts.
 * Submitted task exceptions are captured by futures and are not logged here.
 */
internal object NativeCalendarWork {

    private val stateExecutor = ThreadPoolExecutor(
        1,
        1,
        0L,
        TimeUnit.MILLISECONDS,
        ArrayBlockingQueue<Runnable>(64),
        threadFactory("NativeCalendar-State"),
        ThreadPoolExecutor.AbortPolicy()
    )

    private val deadlines = ScheduledThreadPoolExecutor(
        1,
        threadFactory("NativeCalendar-Deadline")
    ).apply {
        removeOnCancelPolicy = true
    }

    /**
     * True means the task was queued, not that its state was persisted.
     */
    fun state(action: () -> Unit): Boolean =
        submitState(action) != null

    /**
     * Callers must never wait for this future on the Android main thread
     * or from another task running on the state worker.
     */
    fun <T> submitState(action: () -> T): Future<T>? =
        try {
            stateExecutor.submit(Callable<T> { action() })
        } catch (_: RejectedExecutionException) {
            null
        }

    /**
     * The coordinator owns and cancels each request's deadline.
     * A scheduled callback does not establish that a request completed.
     */
    fun after(
        delayMs: Long,
        action: () -> Unit
    ): ScheduledFuture<*>? {
        require(delayMs > 0L) {
            "The Calendar deadline must be positive."
        }

        return try {
            deadlines.schedule(
                Runnable { action() },
                delayMs,
                TimeUnit.MILLISECONDS
            )
        } catch (_: RejectedExecutionException) {
            null
        }
    }

    private fun threadFactory(name: String): ThreadFactory =
        ThreadFactory { action ->
            Thread(action, name).apply {
                isDaemon = true
            }
        }
}
