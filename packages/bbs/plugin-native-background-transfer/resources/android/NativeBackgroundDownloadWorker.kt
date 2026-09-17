package com.bbs.plugins.native_background_transfer

import android.content.Context
import androidx.work.ForegroundInfo
import androidx.work.Worker
import androidx.work.WorkerParameters

internal class NativeBackgroundDownloadWorker(
    appContext: Context,
    workerParameters: WorkerParameters
) : Worker(
    appContext,
    workerParameters
) {

    @Volatile
    private var stopRequested = false

    override fun onStopped() {
        stopRequested = true
        super.onStopped()
    }

    override fun doWork(): Result {
        val rawTransferId =
            inputData.getString(
                NativeBackgroundTransferScheduler
                    .INPUT_TRANSFER_ID
            )
                ?: return Result.failure()

        val notifications =
            NativeBackgroundTransferNotifications(
                applicationContext
            )

        val foregroundUpdater =
            NativeBackgroundTransferForegroundUpdater {
                    status,
                    progress,
                    type ->

                setForegroundSafely(
                    notifications.foregroundInfo(
                        transferId = rawTransferId,
                        status = status,
                        progress = progress,
                        type = type
                    )
                )
            }

        val runner =
            NativeBackgroundDownloadRunner(
                context = applicationContext,
                foregroundUpdater =
                    foregroundUpdater,
                isStopRequested = {
                    stopRequested ||
                        isStopped
                }
            )

        return when (
            runner.execute(
                rawTransferId
            )
        ) {
            NativeBackgroundTransferExecutionResult.Succeeded ->
                Result.success()

            NativeBackgroundTransferExecutionResult.Failed ->
                Result.failure()

            NativeBackgroundTransferExecutionResult.Reschedule ->
                Result.retry()
        }
    }
    private fun setForegroundSafely(
        foregroundInfo: ForegroundInfo
    ): Boolean {
        return try {
            setForegroundAsync(
                foregroundInfo
            ).get()

            true
        } catch (_: InterruptedException) {
            Thread.currentThread()
                .interrupt()

            false
        } catch (_: Exception) {
            false
        }
    }


}