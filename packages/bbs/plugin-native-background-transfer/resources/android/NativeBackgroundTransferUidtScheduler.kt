package com.bbs.plugins.native_background_transfer

import android.app.job.JobInfo
import android.app.job.JobScheduler
import android.content.ComponentName
import android.content.Context
import android.os.Build

/*
 * Android 14+ scheduler for user-initiated data transfer jobs.
 *
 * This class is intentionally separate from the existing WorkManager
 * scheduler. It will only be connected once the UIDT JobService can
 * execute transfers safely.
 */
internal class NativeBackgroundTransferUidtScheduler(
    context: Context
) {

    private val applicationContext =
        context.applicationContext

    fun schedule(
        transferId: String,
        type: String
    ): Boolean {
        if (
            Build.VERSION.SDK_INT <
            Build.VERSION_CODES.UPSIDE_DOWN_CAKE
        ) {
            return false
        }

        val extras =
            NativeBackgroundTransferUidtContract
                .extras(
                    transferId = transferId,
                    type = type
                )
                ?: return false

        return try {
            synchronized(scheduleLock) {
                val scheduler =
                    namespacedScheduler()

                if (
                    !scheduler
                        .canRunUserInitiatedJobs()
                ) {
                    return@synchronized false
                }

                val pendingJobs =
                    scheduler.allPendingJobs

                /*
                 * Do not replace an already scheduled/running job
                 * for the same transfer. Re-scheduling the same
                 * JobScheduler ID can stop a currently running job.
                 */
                val alreadyScheduled =
                    pendingJobs.any { job ->
                        NativeBackgroundTransferUidtContract
                            .transferId(job.extras) ==
                            transferId &&
                        NativeBackgroundTransferUidtContract
                            .transferType(job.extras) ==
                            type
                    }

                if (alreadyScheduled) {
                    return@synchronized true
                }

                val jobId =
                    availableJobId(
                        pendingJobs = pendingJobs,
                        transferId = transferId,
                        type = type
                    )

                val jobInfo =
                    JobInfo.Builder(
                        jobId,
                        ComponentName(
                            applicationContext,
                            NativeBackgroundTransferJobService::
                                class.java
                        )
                    )
                        .setExtras(extras)
                        .setRequiredNetworkType(
                            JobInfo.NETWORK_TYPE_ANY
                        )
                        .setUserInitiated(true)
                        .setBackoffCriteria(
                            BACKOFF_MILLIS,
                            JobInfo
                                .BACKOFF_POLICY_EXPONENTIAL
                        )
                        .build()

                scheduler.schedule(jobInfo) ==
                    JobScheduler.RESULT_SUCCESS
            }
        } catch (_: Exception) {
            false
        }
    }

    fun cancel(
        transferId: String
    ): Boolean {
        if (
            Build.VERSION.SDK_INT <
            Build.VERSION_CODES.UPSIDE_DOWN_CAKE
        ) {
            return false
        }

        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(
                    transferId
                )
                ?: return false

        if (normalizedId != transferId) {
            return false
        }

        return try {
            synchronized(scheduleLock) {
                val scheduler =
                    namespacedScheduler()

                scheduler
                    .allPendingJobs
                    .filter { job ->
                        NativeBackgroundTransferUidtContract
                            .transferId(job.extras) ==
                            transferId
                    }
                    .forEach { job ->
                        scheduler.cancel(job.id)
                    }

                true
            }
        } catch (_: Exception) {
            false
        }
    }

    private fun namespacedScheduler():
        JobScheduler {
        return applicationContext
            .getSystemService(
                JobScheduler::class.java
            )
            .forNamespace(JOB_NAMESPACE)
    }

    private fun availableJobId(
        pendingJobs: List<JobInfo>,
        transferId: String,
        type: String
    ): Int {
        val usedIds =
            pendingJobs
                .mapTo(
                    mutableSetOf()
                ) {
                    it.id
                }

        /*
         * Start from a stable candidate, then probe if another
         * active transfer already occupies that ID.
         *
         * The dedicated namespace prevents collisions with
         * unrelated JobScheduler users in the host app.
         */
        var candidate =
            "$type:$transferId"
                .hashCode() and
                Int.MAX_VALUE

        if (candidate == 0) {
            candidate = 1
        }

        while (candidate in usedIds) {
            candidate =
                if (
                    candidate ==
                    Int.MAX_VALUE
                ) {
                    1
                } else {
                    candidate + 1
                }
        }

        return candidate
    }

    companion object {
        private const val JOB_NAMESPACE =
            "native-background-transfer"

        private const val BACKOFF_MILLIS =
            30_000L

        private val scheduleLock =
            Any()
    }
}