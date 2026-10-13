package com.bbs.plugins.firebase_remote_config

import android.os.Looper
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import org.junit.Assert.*
import org.junit.Test

class RemoteConfigExecutorSchedulerInstrumentedTest {
    @Test fun deadlineStillRunsWhileTheSdkWorkerQueueIsBlocked() {
        val worker = Executors.newSingleThreadExecutor()
        val timer = Executors.newSingleThreadScheduledExecutor()
        val entered = CountDownLatch(1)
        val unblock = CountDownLatch(1)
        val deadline = CountDownLatch(1)
        var uiThread = true
        try {
            worker.execute {
                entered.countDown()
                try { unblock.await(5, TimeUnit.SECONDS) }
                catch (_: InterruptedException) {
                    // Cleanup may interrupt a waiting test worker. Android treats an
                    // uncaught worker exception as a process crash, beyond this test.
                    Thread.currentThread().interrupt()
                }
            }
            assertTrue(entered.await(2, TimeUnit.SECONDS))
            val scheduler = RemoteConfigExecutorScheduler(worker, timer)
            scheduler.schedule(20) { uiThread = Looper.myLooper() == Looper.getMainLooper(); deadline.countDown() }
            assertTrue(deadline.await(2, TimeUnit.SECONDS))
            assertFalse(uiThread)
            assertEquals(1L, unblock.count)
        } finally {
            unblock.countDown()
            worker.shutdown()
            timer.shutdownNow()
            if (!worker.awaitTermination(2, TimeUnit.SECONDS)) worker.shutdownNow()
        }
    }
    @Test fun sdkActionsRunOnTheSuppliedWorkerInsteadOfTheCallingThread() {
        val worker = Executors.newSingleThreadExecutor()
        val timer = Executors.newSingleThreadScheduledExecutor()
        val completed = CountDownLatch(1)
        val caller = Thread.currentThread()
        var actual: Thread? = null
        try {
            RemoteConfigExecutorScheduler(worker, timer).execute { actual = Thread.currentThread(); completed.countDown() }
            assertTrue(completed.await(2, TimeUnit.SECONDS))
            assertNotSame(caller, actual)
        } finally { worker.shutdownNow(); timer.shutdownNow() }
    }
}
