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
        val transferId =
            NativeBackgroundTransferContract
                .normalizeRequestId(
                    inputData.getString(
                        NativeBackgroundTransferScheduler
                            .INPUT_TRANSFER_ID
                    )
                )
                ?: return Result.failure()

        val store =
            NativeBackgroundTransferStore(
                applicationContext
            )

        val request =
            store.request(transferId)
                ?: return Result.failure()

        val current =
            store.result(transferId)
                ?: return Result.failure()

        if (
            NativeBackgroundTransferContract
                .isTerminalStatus(
                    current.status
                )
        ) {
            return Result.success()
        }

        val notifications =
            NativeBackgroundTransferNotifications(
                applicationContext
            )

        if (
            !setForegroundSafely(
                notifications.foregroundInfo(
                    transferId = transferId,
                    status =
                        NativeBackgroundTransferContract
                            .STATUS_RUNNING,
                    progress =
                        current.progress
                )
            )
        ) {
            return failTransfer(
                store = store,
                transferId = transferId,
                errorCode =
                    NativeBackgroundTransferContract
                        .SCHEDULER_UNAVAILABLE,
                transferredBytes =
                    current.transferredBytes,
                totalBytes =
                    current.totalBytes,
                progress =
                    current.progress
            )
        }

        val running =
            NativeBackgroundTransferResult(
                id = transferId,
                status =
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                transferredBytes =
                    current.transferredBytes,
                totalBytes =
                    current.totalBytes,
                progress =
                    current.progress
            )

        if (!store.update(running)) {
            if (
                isPersistedCancelled(
                    store,
                    transferId
                )
            ) {
                return Result.success()
            }

            return Result.failure()
        }

        var lastPersistedBytes =
            running.transferredBytes

        var lastPersistedProgress =
            running.progress

        var persistenceFailed = false
        var foregroundFailed = false

        val engine =
            NativeBackgroundDownloadEngine(
                applicationContext
            )

        val outcome =
            engine.download(
                request = request,

                isCancelled = {
                    stopRequested ||
                        isStopped ||
                        persistenceFailed ||
                        foregroundFailed ||
                        isPersistedCancelled(
                            store,
                            transferId
                        )
                },

                onProgress = {
                        transferredBytes,
                        totalBytes,
                        progress ->

                    if (
                        !persistenceFailed &&
                        !foregroundFailed &&
                        !stopRequested &&
                        !isStopped &&
                        !isPersistedCancelled(
                            store,
                            transferId
                        )
                    ) {
                        val shouldPersist =
                            when {
                                progress != null ->
                                    progress !=
                                        lastPersistedProgress

                                transferredBytes -
                                    lastPersistedBytes >=
                                    PROGRESS_BYTE_INTERVAL ->
                                    true

                                else ->
                                    false
                            }

                        if (shouldPersist) {
                            val progressResult =
                                NativeBackgroundTransferResult(
                                    id = transferId,
                                    status =
                                        NativeBackgroundTransferContract
                                            .STATUS_RUNNING,
                                    transferredBytes =
                                        transferredBytes,
                                    totalBytes =
                                        totalBytes,
                                    progress =
                                        progress
                                )

                            if (
                                store.update(
                                    progressResult
                                )
                            ) {
                                lastPersistedBytes =
                                    transferredBytes

                                lastPersistedProgress =
                                    progress

                                if (
                                    !setForegroundSafely(
                                        notifications
                                            .foregroundInfo(
                                                transferId =
                                                    transferId,
                                                status =
                                                    NativeBackgroundTransferContract
                                                        .STATUS_RUNNING,
                                                progress =
                                                    progress
                                            )
                                    )
                                ) {
                                    foregroundFailed =
                                        true
                                }
                            } else if (
                                !isPersistedCancelled(
                                    store,
                                    transferId
                                )
                            ) {
                                persistenceFailed =
                                    true
                            }
                        }
                    }
                }
            )

        if (persistenceFailed) {
            return failTransfer(
                store = store,
                transferId = transferId,
                errorCode =
                    NativeBackgroundTransferContract
                        .RESULT_PERSISTENCE_FAILED,
                transferredBytes =
                    lastPersistedBytes,
                totalBytes =
                    store.result(transferId)
                        ?.totalBytes,
                progress =
                    lastPersistedProgress
            )
        }

        if (foregroundFailed) {
            return failTransfer(
                store = store,
                transferId = transferId,
                errorCode =
                    NativeBackgroundTransferContract
                        .SCHEDULER_UNAVAILABLE,
                transferredBytes =
                    lastPersistedBytes,
                totalBytes =
                    store.result(transferId)
                        ?.totalBytes,
                progress =
                    lastPersistedProgress
            )
        }

        return when (outcome) {
            is NativeBackgroundDownloadOutcome.Succeeded ->
                handleSuccess(
                    store = store,
                    transferId = transferId,
                    outcome = outcome
                )

            is NativeBackgroundDownloadOutcome.Failed ->
                handleFailure(
                    store = store,
                    transferId = transferId,
                    outcome = outcome
                )

            is NativeBackgroundDownloadOutcome.Cancelled ->
                handleStoppedOrCancelled(
                    store = store,
                    transferId = transferId,
                    outcome = outcome
                )
        }
    }

    private fun handleSuccess(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundDownloadOutcome.Succeeded
    ): Result {
        val success =
            outcome.result

        val mimeType =
            success.mimeType
                ?: return Result.failure()

        val filePolicy =
            NativeBackgroundTransferFilePolicy(
                applicationContext
            )

        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            filePolicy.deleteFinalFor(
                transferId = transferId,
                mimeType = mimeType
            )

            return Result.success()
        }

        if (store.update(success)) {
            return Result.success()
        }

        /*
         * Cancellation may have won the race between the final
         * engine check and terminal result persistence.
         */
        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            filePolicy.deleteFinalFor(
                transferId = transferId,
                mimeType = mimeType
            )

            return Result.success()
        }

        /*
         * Never leave an untracked completed file if its durable
         * terminal record could not be persisted.
         */
        filePolicy.deleteFinalFor(
            transferId = transferId,
            mimeType = mimeType
        )

        return Result.failure()
    }

    private fun handleFailure(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundDownloadOutcome.Failed
    ): Result {
        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            return Result.success()
        }

        if (
            outcome.errorCode ==
                NativeBackgroundTransferContract
                    .NETWORK_ERROR &&
            runAttemptCount <
                MAX_NETWORK_RETRY_COUNT
        ) {
            val retryState =
                NativeBackgroundTransferResult(
                    id = transferId,
                    status =
                        NativeBackgroundTransferContract
                            .STATUS_QUEUED,
                    transferredBytes = 0L,
                    totalBytes =
                        outcome.totalBytes,
                    progress =
                        if (
                            outcome.totalBytes != null &&
                            outcome.totalBytes > 0L
                        ) {
                            0
                        } else {
                            null
                        }
                )

            if (store.update(retryState)) {
                return Result.retry()
            }

            if (
                isPersistedCancelled(
                    store,
                    transferId
                )
            ) {
                return Result.success()
            }

            return Result.failure()
        }

        val failed =
            NativeBackgroundTransferResult.failed(
                id = transferId,
                errorCode =
                    outcome.errorCode,
                transferredBytes =
                    outcome.transferredBytes,
                totalBytes =
                    outcome.totalBytes,
                progress =
                    outcome.progress
            )

        if (store.update(failed)) {
            return Result.failure()
        }

        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            return Result.success()
        }

        return Result.failure()
    }

    private fun handleStoppedOrCancelled(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundDownloadOutcome.Cancelled
    ): Result {
        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            return Result.success()
        }

        /*
         * A system stop is not a user cancellation.
         * The partial file has already been removed by the engine,
         * so reset streamed bytes before retrying.
         */
        val queued =
            NativeBackgroundTransferResult(
                id = transferId,
                status =
                    NativeBackgroundTransferContract
                        .STATUS_QUEUED,
                transferredBytes = 0L,
                totalBytes =
                    outcome.totalBytes,
                progress =
                    if (
                        outcome.totalBytes != null &&
                        outcome.totalBytes > 0L
                    ) {
                        0
                    } else {
                        null
                    }
            )

        if (store.update(queued)) {
            return Result.retry()
        }

        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            return Result.success()
        }

        return Result.failure()
    }

    private fun failTransfer(
        store: NativeBackgroundTransferStore,
        transferId: String,
        errorCode: String,
        transferredBytes: Long,
        totalBytes: Long?,
        progress: Int?
    ): Result {
        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            return Result.success()
        }

        val failed =
            NativeBackgroundTransferResult.failed(
                id = transferId,
                errorCode = errorCode,
                transferredBytes =
                    transferredBytes,
                totalBytes =
                    totalBytes,
                progress =
                    progress
            )

        return if (store.update(failed)) {
            Result.failure()
        } else if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            Result.success()
        } else {
            Result.failure()
        }
    }

    private fun isPersistedCancelled(
        store: NativeBackgroundTransferStore,
        transferId: String
    ): Boolean {
        return store.result(transferId)
            ?.status ==
            NativeBackgroundTransferContract
                .STATUS_CANCELLED
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

    private companion object {
        const val PROGRESS_BYTE_INTERVAL =
            1024L * 1024L

        /*
         * runAttemptCount starts at zero.
         * This permits three WorkManager retries after the
         * initial network attempt.
         */
        const val MAX_NETWORK_RETRY_COUNT =
            3
    }
}