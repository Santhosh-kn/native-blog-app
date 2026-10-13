package com.bbs.plugins.firebase_remote_config

import android.os.Looper
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.google.android.gms.tasks.TaskCompletionSource
import com.google.android.gms.tasks.Tasks
import com.google.firebase.remoteconfig.FirebaseRemoteConfigFetchThrottledException
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executor
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicReference
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

@RunWith(AndroidJUnit4::class)
class RemoteConfigTaskCompletionInstrumentedTest {
    private val direct = Executor { it.run() }

    @Test fun successfulFetchCompletionCarriesUnit() {
        val source = TaskCompletionSource<Void>()
        var outcome: RemoteConfigSdkOutcome<Unit>? = null
        RemoteConfigTaskCompletion.observe(source.task, direct, Contract.FETCH_FAILED, { Unit }) { outcome = it }
        assertNull(outcome)
        source.setResult(null)
        assertEquals(Unit, (outcome as RemoteConfigSdkOutcome.Success<*>).value)
    }

    @Test fun activationFalseIsSuccessfulInsteadOfFailure() {
        var outcome: RemoteConfigSdkOutcome<Boolean>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forResult(false), direct, Contract.ACTIVATION_FAILED, { it.result }) { outcome = it }
        assertEquals(false, (outcome as RemoteConfigSdkOutcome.Success<*>).value)
    }

    @Test fun activationTrueIsSuccessful() {
        var outcome: RemoteConfigSdkOutcome<Boolean>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forResult(true), direct, Contract.ACTIVATION_FAILED, { it.result }) { outcome = it }
        assertEquals(true, (outcome as RemoteConfigSdkOutcome.Success<*>).value)
    }

    @Test fun ordinaryFailureDoesNotExposeItsExceptionText() {
        var outcome: RemoteConfigSdkOutcome<Unit>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forException<Void>(IllegalStateException("private SDK detail")), direct,
            Contract.FETCH_FAILED, { Unit }) { outcome = it }
        assertEquals(Contract.FETCH_FAILED, (outcome as RemoteConfigSdkOutcome.Failure).code)
        assertFalse(outcome.toString().contains("private SDK detail"))
    }

    @Test fun cancellationBecomesAControlledOperationFailure() {
        var outcome: RemoteConfigSdkOutcome<Unit>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forCanceled<Void>(), direct, Contract.FETCH_FAILED, { Unit }) { outcome = it }
        assertEquals(Contract.FETCH_FAILED, (outcome as RemoteConfigSdkOutcome.Failure).code)
    }

    @Test fun throttlingRetainsTheSdkRetryTimestamp() {
        var outcome: RemoteConfigSdkOutcome<Unit>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forException<Void>(FirebaseRemoteConfigFetchThrottledException(5000)), direct,
            Contract.FETCH_FAILED, { Unit }) { outcome = it }
        val failure = outcome as RemoteConfigSdkOutcome.Failure
        assertEquals(Contract.THROTTLED, failure.code)
        assertEquals(5000L, failure.throttleEndTimeMs)
    }

    @Test fun wrappedThrottleExceptionIsRecognized() {
        val failure = RemoteConfigTaskCompletion.failure(RuntimeException("wrapper", FirebaseRemoteConfigFetchThrottledException(5000)), Contract.FETCH_FAILED)
        assertEquals(Contract.THROTTLED, failure.code)
        assertEquals(5000L, failure.throttleEndTimeMs)
    }

    @Test fun invalidThrottleTimestampIsNotExposed() {
        val failure = RemoteConfigTaskCompletion.failure(FirebaseRemoteConfigFetchThrottledException(0), Contract.FETCH_FAILED)
        assertEquals(Contract.THROTTLED, failure.code)
        assertNull(failure.throttleEndTimeMs)
    }

    @Test fun nullSuccessfulActivationResultIsControlled() {
        var outcome: RemoteConfigSdkOutcome<Boolean>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forResult<Boolean?>(null), direct, Contract.ACTIVATION_FAILED,
            { requireNotNull(it.result) }) { outcome = it }
        assertEquals(Contract.ACTIVATION_FAILED, (outcome as RemoteConfigSdkOutcome.Failure).code)
    }

    @Test fun mapperLinkageFailureIsControlled() {
        var outcome: RemoteConfigSdkOutcome<Unit>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forResult(true), direct, Contract.ACTIVATION_FAILED,
            { throw LinkageError("private class detail") }) { outcome = it }
        assertEquals(Contract.NATIVE_UNAVAILABLE, (outcome as RemoteConfigSdkOutcome.Failure).code)
    }

    @Test fun mapperMemoryFailureIsControlled() {
        var outcome: RemoteConfigSdkOutcome<Unit>? = null
        RemoteConfigTaskCompletion.observe(Tasks.forResult(true), direct, Contract.ACTIVATION_FAILED,
            { throw OutOfMemoryError("simulated") }) { outcome = it }
        assertEquals(Contract.LIMIT_EXCEEDED, (outcome as RemoteConfigSdkOutcome.Failure).code)
    }

    @Test fun aTaskInvokesItsCompletionOnce() {
        val count = AtomicInteger()
        val source = TaskCompletionSource<Boolean>()
        RemoteConfigTaskCompletion.observe(source.task, direct, Contract.ACTIVATION_FAILED, { it.result }) { count.incrementAndGet() }
        source.setResult(false)
        assertEquals(1, count.get())
    }

    @Test fun callbackRunsOnTheExplicitWorkerExecutor() {
        val executor = Executors.newSingleThreadExecutor { Thread(it, "remote-config-test-owner") }
        val finished = CountDownLatch(1)
        val threadName = AtomicReference<String>()
        val isMain = AtomicReference<Boolean>()
        try {
            RemoteConfigTaskCompletion.observe(Tasks.forResult(true), executor, Contract.ACTIVATION_FAILED, { it.result }) {
                threadName.set(Thread.currentThread().name)
                isMain.set(Looper.myLooper() == Looper.getMainLooper())
                finished.countDown()
            }
            assertTrue(finished.await(5, TimeUnit.SECONDS))
            assertEquals("remote-config-test-owner", threadName.get())
            assertEquals(false, isMain.get())
        } finally { executor.shutdownNow() }
    }
}
