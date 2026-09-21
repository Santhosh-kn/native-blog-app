package com.bbs.plugins.native_background_transfer

import android.app.job.JobParameters
import android.app.job.JobService
import android.net.Network
import android.os.Build
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.Executors
import java.util.concurrent.Future
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicReference
import javax.net.ssl.HttpsURLConnection

/*
 * Android 14+ execution adapter for user-initiated data transfers.
 *
 * Transfer semantics remain inside the shared download/upload runners.
 * This service only adapts JobScheduler lifecycle, notification,
 * cancellation and assigned-network behavior.
 */
internal class NativeBackgroundTransferJobService :
    JobService() {

    private val executor =
        Executors.newCachedThreadPool()

    private val runningJobs =
        ConcurrentHashMap<Int, RunningJob>()

    override fun onStartJob(
        params: JobParameters
    ): Boolean {
        if (
            Build.VERSION.SDK_INT <
            Build.VERSION_CODES.UPSIDE_DOWN_CAKE
        ) {
            return false
        }

        val transferId =
            NativeBackgroundTransferUidtContract
                .transferId(params.extras)
                ?: return false

        val type =
            NativeBackgroundTransferUidtContract
                .transferType(params.extras)
                ?: return false

        val network =
            params.network
                ?: return false

        val store =
            NativeBackgroundTransferStore(
                applicationContext
            )

        val current =
            store.result(transferId)
                ?: return false

        if (
            current.id != transferId ||
            current.type != type
        ) {
            return false
        }

        if (
            NativeBackgroundTransferContract
                .isTerminalStatus(
                    current.status
                )
        ) {
            return false
        }

        val runningJob =
            RunningJob(
                transferId = transferId,
                type = type,
                initialNetwork = network
            )

        if (
            runningJobs.putIfAbsent(
                params.jobId,
                runningJob
            ) != null
        ) {
            return false
        }

        val notifications =
            NativeBackgroundTransferNotifications(
                applicationContext
            )

        /*
         * UIDT requires a JobService notification promptly after
         * onStartJob(). The runner will update it with live state.
         */
        try {
            setNotification(
                params,
                notifications.notificationId(
                    transferId
                ),
                notifications.notification(
                    status =
                        NativeBackgroundTransferContract
                            .STATUS_RUNNING,
                    progress = current.progress,
                    type = type
                ),
                JOB_END_NOTIFICATION_POLICY_REMOVE
            )
        } catch (_: Exception) {
            runningJobs.remove(
                params.jobId,
                runningJob
            )

            return false
        }

        val future =
            executor.submit {
                executeTransfer(
                    params = params,
                    runningJob = runningJob,
                    notifications = notifications
                )
            }

        runningJob.future.set(future)

        return true
    }

    override fun onStopJob(
        params: JobParameters
    ): Boolean {
        val runningJob =
            runningJobs[params.jobId]
                ?: return false

        /*
         * Normal completion and JobScheduler stop can race on
         * different threads. Exactly one side may own termination.
         */
        if (
            !runningJob.lifecycleClaimed
                .compareAndSet(
                    false,
                    true
                )
        ) {
            runningJobs.remove(
                params.jobId,
                runningJob
            )

            return false
        }

        synchronized(runningJob) {
            runningJob.stoppedBySystem.set(true)
            runningJob.stopRequested.set(true)

            runningJob.future
                .get()
                ?.cancel(true)

            runningJobs.remove(
                params.jobId,
                runningJob
            )
        }

        /*
         * Request JobScheduler retry for system stops.
         *
         * A Task Manager user-stop terminates the process and does
         * not invoke onStopJob(), so that path cannot be rescheduled.
         */
        return true
    }

    override fun onNetworkChanged(
        params: JobParameters
    ) {
        val runningJob =
            runningJobs[params.jobId]
                ?: return

        runningJob.network.set(
            params.network
        )
    }

    override fun onDestroy() {
        runningJobs.values
            .forEach { runningJob ->
                synchronized(runningJob) {
                    runningJob.stoppedBySystem.set(true)
                    runningJob.stopRequested.set(true)

                    runningJob.future
                        .get()
                        ?.cancel(true)
                }
            }

        runningJobs.clear()

        executor.shutdownNow()

        super.onDestroy()
    }

    private fun executeTransfer(
        params: JobParameters,
        runningJob: RunningJob,
        notifications:
            NativeBackgroundTransferNotifications
    ) {
        val foregroundUpdater =
            NativeBackgroundTransferForegroundUpdater {
                    status,
                    progress,
                    type ->

                when {
                    type != runningJob.type ->
                        false

                    /*
                     * Once JobScheduler has stopped this job, avoid
                     * treating a late notification update as a
                     * scheduler failure. The runner's stop callback
                     * owns the reschedule decision.
                     */
                    runningJob.stopRequested.get() ->
                        true

                    else ->
                        updateNotification(
                            params = params,
                            runningJob = runningJob,
                            notifications = notifications,
                            status = status,
                            progress = progress,
                            type = type
                        )
                }
            }

        val connectionOpener =
            NativeBackgroundTransferConnectionOpener {
                    url ->

                val network =
                    runningJob.network.get()

                if (network == null) {
                    null
                } else {
                    network.openConnection(url) as?
                        HttpsURLConnection
                }
            }

        val executionResult =
            try {
                when (runningJob.type) {
                    NativeBackgroundTransferContract
                        .TYPE_DOWNLOAD ->
                        NativeBackgroundDownloadRunner(
                            context =
                                applicationContext,
                            foregroundUpdater =
                                foregroundUpdater,
                            isStopRequested = {
                                runningJob
                                    .stopRequested
                                    .get()
                            },
                            connectionOpener =
                                connectionOpener
                        )
                            .execute(
                                runningJob.transferId
                            )

                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD ->
                        NativeBackgroundUploadRunner(
                            context =
                                applicationContext,
                            foregroundUpdater =
                                foregroundUpdater,
                            isStopRequested = {
                                runningJob
                                    .stopRequested
                                    .get()
                            },
                            connectionOpener =
                                connectionOpener
                        )
                            .execute(
                                runningJob.transferId
                            )

                    else ->
                        NativeBackgroundTransferExecutionResult
                            .Failed
                }
            } catch (_: Exception) {
                NativeBackgroundTransferExecutionResult
                    .Reschedule
            }

        val wantsReschedule =
            executionResult ==
                NativeBackgroundTransferExecutionResult
                    .Reschedule

        finishJob(
            params = params,
            runningJob = runningJob,
            wantsReschedule = wantsReschedule
        )
    }

    private fun updateNotification(
        params: JobParameters,
        runningJob: RunningJob,
        notifications:
            NativeBackgroundTransferNotifications,
        status: String,
        progress: Int?,
        type: String
    ): Boolean {
        return try {
            setNotification(
                params,
                notifications.notificationId(
                    runningJob.transferId
                ),
                notifications.notification(
                    status = status,
                    progress = progress,
                    type = type
                ),
                JOB_END_NOTIFICATION_POLICY_REMOVE
            )

            true
        } catch (_: Exception) {
            false
        }
    }

    private fun finishJob(
        params: JobParameters,
        runningJob: RunningJob,
        wantsReschedule: Boolean
    ) {
        /*
         * Claim completion before removing the map entry so a
         * concurrent onStopJob() cannot independently win too.
         */
        if (
            !runningJob.lifecycleClaimed
                .compareAndSet(
                    false,
                    true
                )
        ) {
            runningJobs.remove(
                params.jobId,
                runningJob
            )

            return
        }

        synchronized(runningJob) {
            runningJobs.remove(
                params.jobId,
                runningJob
            )

            /*
             * onDestroy() can still mark the execution stopped
             * independently of the normal finish/stop race.
             */
            if (
                !runningJob
                    .stoppedBySystem
                    .get()
            ) {
                jobFinished(
                    params,
                    wantsReschedule
                )
            }
        }
    }

    private class RunningJob(
        val transferId: String,
        val type: String,
        initialNetwork: Network
    ) {
        val lifecycleClaimed =
            AtomicBoolean(false)

        val stopRequested =
            AtomicBoolean(false)

        val stoppedBySystem =
            AtomicBoolean(false)

        val network =
            AtomicReference<Network?>(
                initialNetwork
            )

        val future =
            AtomicReference<Future<*>?>(
                null
            )
    }
}
