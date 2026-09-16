package com.bbs.plugins.native_background_transfer

import android.content.Context
import androidx.work.ForegroundInfo
import androidx.work.Worker
import androidx.work.WorkerParameters

internal class NativeBackgroundUploadWorker(
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
            store.storedRequest(transferId)
                as? NativeBackgroundUploadRequest
                ?: return Result.failure()

        if (
            request.id != transferId ||
            request.type !=
                NativeBackgroundTransferContract.TYPE_UPLOAD
        ) {
            return Result.failure()
        }

        val current =
            store.result(transferId)
                ?: return Result.failure()

        if (
            current.id != transferId ||
            current.type !=
                NativeBackgroundTransferContract.TYPE_UPLOAD
        ) {
            return Result.failure()
        }

        if (
            NativeBackgroundTransferContract
                .isTerminalStatus(current.status)
        ) {
            return Result.success()
        }

        val source = when (
            val resolution =
                NativeBackgroundUploadSource.resolve(
                    context = applicationContext,
                    request = request
                )
        ) {
            is NativeBackgroundUploadSourceResolution.Resolved ->
                resolution

            is NativeBackgroundUploadSourceResolution.Rejected ->
                return persistSourceFailure(
                    store = store,
                    transferId = transferId,
                    current = current,
                    errorCode = resolution.errorCode
                )
        }

        val initialProgress =
            if (source.size > 0L) {
                0
            } else {
                null
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
                    progress = initialProgress,
                    type =
                        NativeBackgroundTransferContract
                            .TYPE_UPLOAD
                )
            )
        ) {
            return failTransfer(
                store = store,
                transferId = transferId,
                errorCode =
                    NativeBackgroundTransferContract
                        .SCHEDULER_UNAVAILABLE,
                transferredBytes = 0L,
                totalBytes = source.size,
                progress = initialProgress
            )
        }

        /*
         * Upload attempts always restart the HTTP body from byte zero.
         * We do not advertise resumable upload semantics.
         */
        val running =
            NativeBackgroundTransferResult(
                id = transferId,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD,
                status =
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                transferredBytes = 0L,
                totalBytes = source.size,
                progress = initialProgress
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

        var lastPersistedBytes = 0L
        var lastPersistedProgress =
            initialProgress

        var persistenceFailed = false
        var foregroundFailed = false

        val engine =
            NativeBackgroundUploadEngine()

        var networkRetryCount = 0
        var outcome: NativeBackgroundUploadOutcome

        while (true) {
            lastPersistedBytes = 0L
            lastPersistedProgress = initialProgress

            val attemptOutcome =
                engine.upload(
                request = request,
                source = source,

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
                                progress !=
                                    lastPersistedProgress ->
                                    true

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
                                    type =
                                        NativeBackgroundTransferContract
                                            .TYPE_UPLOAD,
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
                                                    progress,
                                                type =
                                                    NativeBackgroundTransferContract
                                                        .TYPE_UPLOAD
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

            if (
                persistenceFailed ||
                foregroundFailed
            ) {
                outcome = attemptOutcome
                break
            }

            if (
                attemptOutcome is NativeBackgroundUploadOutcome.Failed &&
                attemptOutcome.errorCode ==
                    NativeBackgroundTransferContract.NETWORK_ERROR &&
                networkRetryCount <
                    MAX_NETWORK_RETRY_COUNT
            ) {
                if (
                    isPersistedCancelled(
                        store,
                        transferId
                    )
                ) {
                    return Result.success()
                }

                networkRetryCount += 1

                val retryState =
                    NativeBackgroundTransferResult(
                        id = transferId,
                        type =
                            NativeBackgroundTransferContract
                                .TYPE_UPLOAD,
                        status =
                            NativeBackgroundTransferContract
                                .STATUS_QUEUED,
                        transferredBytes = 0L,
                        totalBytes = source.size,
                        progress = initialProgress
                    )

                if (!store.update(retryState)) {
                    if (
                        isPersistedCancelled(
                            store,
                            transferId
                        )
                    ) {
                        return Result.success()
                    }

                    persistenceFailed = true
                    outcome = attemptOutcome
                    break
                }

                if (
                    !setForegroundSafely(
                        notifications.foregroundInfo(
                            transferId = transferId,
                            status =
                                NativeBackgroundTransferContract
                                    .STATUS_QUEUED,
                            progress = initialProgress,
                            type =
                                NativeBackgroundTransferContract
                                    .TYPE_UPLOAD
                        )
                    )
                ) {
                    foregroundFailed = true
                    outcome = attemptOutcome
                    break
                }

                if (
                    !waitForNetworkRetry(
                        store = store,
                        transferId = transferId,
                        retryNumber = networkRetryCount
                    )
                ) {
                    if (
                        isPersistedCancelled(
                            store,
                            transferId
                        )
                    ) {
                        return Result.success()
                    }

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

                if (
                    stopRequested ||
                    isStopped
                ) {
                    return Result.retry()
                }

                val retryRunning =
                    NativeBackgroundTransferResult(
                        id = transferId,
                        type =
                            NativeBackgroundTransferContract
                                .TYPE_UPLOAD,
                        status =
                            NativeBackgroundTransferContract
                                .STATUS_RUNNING,
                        transferredBytes = 0L,
                        totalBytes = source.size,
                        progress = initialProgress
                    )

                if (!store.update(retryRunning)) {
                    if (
                        isPersistedCancelled(
                            store,
                            transferId
                        )
                    ) {
                        return Result.success()
                    }

                    persistenceFailed = true
                    outcome = attemptOutcome
                    break
                }

                if (
                    !setForegroundSafely(
                        notifications.foregroundInfo(
                            transferId = transferId,
                            status =
                                NativeBackgroundTransferContract
                                    .STATUS_RUNNING,
                            progress = initialProgress,
                            type =
                                NativeBackgroundTransferContract
                                    .TYPE_UPLOAD
                        )
                    )
                ) {
                    foregroundFailed = true
                    outcome = attemptOutcome
                    break
                }

                continue
            }

            outcome = attemptOutcome
            break
        }

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
                    source.size,
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
                    source.size,
                progress =
                    lastPersistedProgress
            )
        }

        return when (outcome) {
            is NativeBackgroundUploadOutcome.Succeeded ->
                handleSuccess(
                    store = store,
                    transferId = transferId,
                    outcome = outcome
                )

            is NativeBackgroundUploadOutcome.Failed ->
                handleFailure(
                    store = store,
                    transferId = transferId,
                    outcome = outcome
                )

            is NativeBackgroundUploadOutcome.Cancelled ->
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
        outcome: NativeBackgroundUploadOutcome.Succeeded
    ): Result {
        if (
            isPersistedCancelled(
                store,
                transferId
            )
        ) {
            return Result.success()
        }

        if (store.update(outcome.result)) {
            return Result.success()
        }

        return if (
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

    private fun handleFailure(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundUploadOutcome.Failed
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
                errorCode =
                    outcome.errorCode,
                transferredBytes =
                    outcome.transferredBytes,
                totalBytes =
                    outcome.totalBytes,
                progress =
                    outcome.progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
            )

        if (store.update(failed)) {
            return Result.failure()
        }

        return if (
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

    private fun handleStoppedOrCancelled(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundUploadOutcome.Cancelled
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
         * A WorkManager/system stop is not a user cancellation.
         * Uploads are restarted from byte zero.
         */
        val queued =
            NativeBackgroundTransferResult(
                id = transferId,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD,
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

        return if (
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

    private fun persistSourceFailure(
        store: NativeBackgroundTransferStore,
        transferId: String,
        current: NativeBackgroundTransferResult,
        errorCode: String
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
                    current.transferredBytes,
                totalBytes =
                    current.totalBytes,
                progress =
                    current.progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
            )

        if (store.update(failed)) {
            return Result.failure()
        }

        return if (
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
                    progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
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

    private fun waitForNetworkRetry(
        store: NativeBackgroundTransferStore,
        transferId: String,
        retryNumber: Int
    ): Boolean {
        val delayMillis =
            NETWORK_RETRY_BASE_DELAY_MILLIS *
                (1L shl (retryNumber - 1))

        var remainingMillis =
            delayMillis

        while (remainingMillis > 0L) {
            if (
                stopRequested ||
                isStopped ||
                isPersistedCancelled(
                    store,
                    transferId
                )
            ) {
                return false
            }

            val sleepMillis =
                minOf(
                    NETWORK_RETRY_POLL_INTERVAL_MILLIS,
                    remainingMillis
                )

            try {
                Thread.sleep(
                    sleepMillis
                )
            } catch (_: InterruptedException) {
                Thread.currentThread()
                    .interrupt()

                return false
            }

            remainingMillis -=
                sleepMillis
        }

        return true
    }

    private companion object {
        const val PROGRESS_BYTE_INTERVAL =
            1024L * 1024L

        const val NETWORK_RETRY_BASE_DELAY_MILLIS =
            30_000L

        const val NETWORK_RETRY_POLL_INTERVAL_MILLIS =
            250L

        /*
         * Three bounded network retries are performed inside
         * the same foreground Worker after the initial attempt.
         *
         * This avoids starting a new foreground service while
         * the application is already backgrounded.
         */
        const val MAX_NETWORK_RETRY_COUNT =
            3
    }
}
