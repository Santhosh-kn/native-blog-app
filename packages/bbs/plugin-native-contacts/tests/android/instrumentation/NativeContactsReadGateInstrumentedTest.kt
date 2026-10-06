package com.bbs.plugins.native_contacts

import androidx.test.ext.junit.runners.AndroidJUnit4
import java.util.concurrent.Callable
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.FutureTask
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicInteger
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class NativeContactsReadGateInstrumentedTest {

    @Test
    fun readCompletionBeforeFutureBindingNeverCancelsTheWorker() {
        val gate = NativeContactsReadGate()
        val task = RecordingFuture()

        assertFalse(gate.isFinished())
        assertTrue(gate.completeRead())
        gate.bindRead(task)

        assertTrue(gate.isFinished())
        assertEquals(0, task.cancelCalls.get())
        assertFalse(task.isCancelled)
    }

    @Test
    fun readCompletionAfterFutureBindingNeverCancelsTheWorker() {
        val gate = NativeContactsReadGate()
        val task = RecordingFuture()

        gate.bindRead(task)
        assertTrue(gate.completeRead())

        assertEquals(0, task.cancelCalls.get())
        assertFalse(task.isCancelled)
    }

    @Test
    fun timeoutBeforeFutureBindingCancelsTheLatePublishedWorker() {
        val gate = NativeContactsReadGate()
        val task = RecordingFuture()

        assertTrue(gate.timeOut())
        gate.bindRead(task)

        assertTrue(gate.isFinished())
        assertEquals(1, task.cancelCalls.get())
        assertTrue(task.interruptionRequested.get())
        assertTrue(task.isCancelled)
    }

    @Test
    fun timeoutAfterFutureBindingCancelsTheWorker() {
        val gate = NativeContactsReadGate()
        val task = RecordingFuture()

        gate.bindRead(task)
        assertTrue(gate.timeOut())

        assertEquals(1, task.cancelCalls.get())
        assertTrue(task.interruptionRequested.get())
        assertTrue(task.isCancelled)
    }

    @Test
    fun readWinnerRejectsDuplicateCompletionAndLateDeadline() {
        val gate = NativeContactsReadGate()
        val task = RecordingFuture()
        gate.bindRead(task)

        assertTrue(gate.completeRead())
        assertFalse(gate.completeRead())
        assertFalse(gate.timeOut())
        assertFalse(gate.timeOut())

        assertEquals(0, task.cancelCalls.get())
        assertFalse(task.isCancelled)
    }

    @Test
    fun timeoutWinnerSuppressesLateReadAndDuplicateTimeout() {
        val gate = NativeContactsReadGate()
        val task = RecordingFuture()
        gate.bindRead(task)

        assertTrue(gate.timeOut())
        assertFalse(gate.completeRead())
        assertFalse(gate.completeRead())
        assertFalse(gate.timeOut())

        assertEquals(1, task.cancelCalls.get())
        assertTrue(task.isCancelled)
    }

    @Test
    fun concurrentReadAndDeadlineHaveExactlyOneWinner() {
        val executor = Executors.newFixedThreadPool(2)

        try {
            repeat(100) {
                val gate = NativeContactsReadGate()
                val task = RecordingFuture()
                val start = CountDownLatch(1)
                gate.bindRead(task)

                val readWinner = executor.submit<Boolean> {
                    check(start.await(5, TimeUnit.SECONDS))
                    gate.completeRead()
                }
                val timeoutWinner = executor.submit<Boolean> {
                    check(start.await(5, TimeUnit.SECONDS))
                    gate.timeOut()
                }

                start.countDown()

                val readWon = readWinner.get(5, TimeUnit.SECONDS)
                val timeoutWon = timeoutWinner.get(5, TimeUnit.SECONDS)

                assertTrue(readWon xor timeoutWon)
                assertTrue(gate.isFinished())
                assertEquals(if (timeoutWon) 1 else 0, task.cancelCalls.get())
                assertEquals(timeoutWon, task.isCancelled)
                assertFalse(gate.completeRead())
                assertFalse(gate.timeOut())
            }
        } finally {
            executor.shutdownNow()
        }
    }

    @Test
    fun fastReadCompletionActionIsNotInterruptedByLateFutureBinding() {
        val gate = NativeContactsReadGate()
        val completionStarted = CountDownLatch(1)
        val releaseCompletion = CountDownLatch(1)
        val interrupted = AtomicBoolean(false)
        val completed = AtomicBoolean(false)
        val executor = Executors.newSingleThreadExecutor()

        val task = FutureTask<Unit>(Callable {
            check(gate.completeRead())
            completionStarted.countDown()

            try {
                check(releaseCompletion.await(5, TimeUnit.SECONDS))
                completed.set(true)
            } catch (_: InterruptedException) {
                interrupted.set(true)
                throw AssertionError("The winning read completion was interrupted.")
            }

            Unit
        })

        try {
            executor.execute(task)
            assertTrue(completionStarted.await(5, TimeUnit.SECONDS))

            // Reproduce a read finishing before submit's Future is published,
            // while the same worker is still running its completion action.
            gate.bindRead(task)

            assertFalse(task.isCancelled)
            assertFalse(task.isDone)
            assertFalse(gate.timeOut())

            releaseCompletion.countDown()
            task.get(5, TimeUnit.SECONDS)

            assertTrue(completed.get())
            assertFalse(interrupted.get())
        } finally {
            releaseCompletion.countDown()
            executor.shutdownNow()
        }
    }

    @Test
    fun winningTimeoutInterruptsARunningWorkerWhoseFutureIsPublishedLate() {
        val gate = NativeContactsReadGate()
        val readStarted = CountDownLatch(1)
        val releaseRead = CountDownLatch(1)
        val readExited = CountDownLatch(1)
        val interrupted = AtomicBoolean(false)
        val executor = Executors.newSingleThreadExecutor()

        val task = FutureTask<Unit>(Callable {
            readStarted.countDown()

            try {
                check(releaseRead.await(5, TimeUnit.SECONDS))
            } catch (_: InterruptedException) {
                interrupted.set(true)
            } finally {
                readExited.countDown()
            }

            Unit
        })

        try {
            executor.execute(task)
            assertTrue(readStarted.await(5, TimeUnit.SECONDS))

            assertTrue(gate.timeOut())
            gate.bindRead(task)

            assertTrue(task.isCancelled)
            assertTrue(readExited.await(5, TimeUnit.SECONDS))
            assertTrue(interrupted.get())
            assertFalse(gate.completeRead())
        } finally {
            releaseRead.countDown()
            executor.shutdownNow()
        }
    }

    private class RecordingFuture : FutureTask<Unit>(Callable { Unit }) {
        val cancelCalls = AtomicInteger(0)
        val interruptionRequested = AtomicBoolean(false)

        override fun cancel(mayInterruptIfRunning: Boolean): Boolean {
            cancelCalls.incrementAndGet()
            interruptionRequested.set(mayInterruptIfRunning)
            return super.cancel(mayInterruptIfRunning)
        }
    }
}