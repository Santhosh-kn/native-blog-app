package com.bbs.plugins.native_contacts

import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.Future
import java.util.concurrent.RejectedExecutionException
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.ScheduledThreadPoolExecutor
import java.util.concurrent.SynchronousQueue
import java.util.concurrent.ThreadFactory
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.TimeUnit

/**
 * Process-lifetime workers.
 *
 * State tasks run sequentially. Contact-provider reads use separate workers
 * and have no waiting queue, limiting retained URI/task references if a
 * provider ignores cancellation.
 *
 * Callers must handle expected failures and persist controlled result codes.
 * Task exceptions are captured by futures and are never logged here.
 */
internal object NativeContactsWork {

    private val stateExecutor = ThreadPoolExecutor(
        1,
        1,
        0L,
        TimeUnit.MILLISECONDS,
        ArrayBlockingQueue<Runnable>(64),
        threadFactory("NativeContacts-State"),
        ThreadPoolExecutor.AbortPolicy()
    )

    private val readExecutor = ThreadPoolExecutor(
        0,
        2,
        30L,
        TimeUnit.SECONDS,
        SynchronousQueue<Runnable>(),
        threadFactory("NativeContacts-Read"),
        ThreadPoolExecutor.AbortPolicy()
    )

    private val deadlines = ScheduledThreadPoolExecutor(
        1,
        threadFactory("NativeContacts-Deadline")
    ).apply {
        removeOnCancelPolicy = true
    }

    fun state(action: () -> Unit): Boolean =
        try {
            stateExecutor.submit(Runnable { action() })
            true
        } catch (_: RejectedExecutionException) {
            false
        }

    fun read(action: () -> Unit): Future<*>? =
        try {
            readExecutor.submit(Runnable { action() })
        } catch (_: RejectedExecutionException) {
            null
        }

    fun after(
        delayMs: Long,
        action: () -> Unit
    ): ScheduledFuture<*>? {
        require(delayMs > 0L)

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