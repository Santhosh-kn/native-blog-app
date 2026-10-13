package com.bbs.plugins.firebase_remote_config

import com.google.android.gms.tasks.Task
import com.google.firebase.remoteconfig.FirebaseRemoteConfigFetchThrottledException
import java.util.concurrent.Executor
import com.bbs.plugins.firebase_remote_config.RemoteConfigContract as Contract

/** One completion, on the supplied owner executor; never expose SDK exception text. */
internal object RemoteConfigTaskCompletion {
    fun <T, R> observe(
        task: Task<T>, executor: Executor, failureCode: String,
        mapper: (Task<T>) -> R, callback: (RemoteConfigSdkOutcome<R>) -> Unit
    ) {
        require(failureCode in Contract.ERRORS)
        task.addOnCompleteListener(executor) { completed ->
            val outcome: RemoteConfigSdkOutcome<R> = if (completed.isSuccessful) {
                try { RemoteConfigSdkOutcome.Success(mapper(completed)) }
                catch (_: LinkageError) { RemoteConfigSdkOutcome.Failure(Contract.NATIVE_UNAVAILABLE) }
                catch (_: OutOfMemoryError) { RemoteConfigSdkOutcome.Failure(Contract.LIMIT_EXCEEDED) }
                catch (error: Exception) { failure(error, failureCode) }
            } else failure(completed.exception, failureCode)
            callback(outcome)
        }
    }

    fun failure(error: Exception?, failureCode: String): RemoteConfigSdkOutcome.Failure {
        require(failureCode in Contract.ERRORS)
        // Some Task implementations wrap the SDK exception. Limit traversal.
        var candidate: Throwable? = error
        repeat(4) {
            val current = candidate
            if (current is FirebaseRemoteConfigFetchThrottledException) {
                val until = current.throttleEndTimeMillis.takeIf { it in 1..Contract.MAX_SAFE_INTEGER }
                return RemoteConfigSdkOutcome.Failure(Contract.THROTTLED, until)
            }
            candidate = current?.cause?.takeUnless { it === current }
        }
        return RemoteConfigSdkOutcome.Failure(failureCode)
    }
}
