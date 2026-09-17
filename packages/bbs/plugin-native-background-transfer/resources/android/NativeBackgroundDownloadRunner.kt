package com.bbs.plugins.native_background_transfer

import android.content.Context

/*
 * Platform-neutral orchestration home for download transfers.
 *
 * Download behavior is being moved here incrementally so both
 * WorkManager and Android 14+ UIDT can share the same durable
 * transfer semantics.
 */
internal class NativeBackgroundDownloadRunner(
    context: Context,
    private val foregroundUpdater:
        NativeBackgroundTransferForegroundUpdater,
    private val isStopRequested: () -> Boolean
) {

    private val applicationContext =
        context.applicationContext

    fun start(
        ready: NativeBackgroundDownloadPreparationResult.Ready
    ): NativeBackgroundDownloadStartResult {
        val transferId =
            ready.transferId

        val store =
            ready.store

        val current =
            ready.current

        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundDownloadStartResult
                .Completed
        }

        if (isStopRequested()) {
            return NativeBackgroundDownloadStartResult
                .Reschedule
        }

        if (
            !foregroundUpdater.update(
                status =
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                progress =
                    current.progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_DOWNLOAD
            )
        ) {
            return failStart(
                store = store,
                transferId = transferId,
                current = current,
                errorCode =
                    NativeBackgroundTransferContract
                        .SCHEDULER_UNAVAILABLE
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

        if (store.update(running)) {
            return NativeBackgroundDownloadStartResult
                .Running(
                    running = running
                )
        }

        return if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            NativeBackgroundDownloadStartResult
                .Completed
        } else {
            NativeBackgroundDownloadStartResult
                .Failed
        }
    }

    private fun failStart(
        store: NativeBackgroundTransferStore,
        transferId: String,
        current: NativeBackgroundTransferResult,
        errorCode: String
    ): NativeBackgroundDownloadStartResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundDownloadStartResult
                .Completed
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
                    current.progress
            )

        return if (store.update(failed)) {
            NativeBackgroundDownloadStartResult
                .Failed
        } else if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            NativeBackgroundDownloadStartResult
                .Completed
        } else {
            NativeBackgroundDownloadStartResult
                .Failed
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

    fun execute(
        transferId: String
    ): NativeBackgroundTransferExecutionResult {
        val ready =
            when (
                val preparation =
                    prepare(
                        transferId
                    )
            ) {
                is NativeBackgroundDownloadPreparationResult.Ready ->
                    preparation

                NativeBackgroundDownloadPreparationResult.Completed ->
                    return NativeBackgroundTransferExecutionResult
                        .Succeeded

                NativeBackgroundDownloadPreparationResult.Failed ->
                    return NativeBackgroundTransferExecutionResult
                        .Failed
            }

        val store =
            ready.store

        val request =
            ready.request

        val running =
            when (
                val start =
                    start(
                        ready
                    )
            ) {
                is NativeBackgroundDownloadStartResult.Running ->
                    start.running

                NativeBackgroundDownloadStartResult.Completed ->
                    return NativeBackgroundTransferExecutionResult
                        .Succeeded

                NativeBackgroundDownloadStartResult.Reschedule ->
                    return NativeBackgroundTransferExecutionResult
                        .Reschedule

                NativeBackgroundDownloadStartResult.Failed ->
                    return NativeBackgroundTransferExecutionResult
                        .Failed
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

        var networkRetryCount = 0
        var outcome: NativeBackgroundDownloadOutcome

        while (true) {
            val attemptOutcome =
                engine.download(
                    request = request,

                    isCancelled = {
                        persistenceFailed ||
                            foregroundFailed ||
                            shouldStop(
                                store = store,
                                transferId = transferId
                            )
                    },

                    onProgress = {
                            transferredBytes,
                            totalBytes,
                            progress ->

                        if (
                            !persistenceFailed &&
                            !foregroundFailed
                        ) {
                            when (
                                val progressResult =
                                    persistProgress(
                                        store = store,
                                        transferId = transferId,
                                        lastPersistedBytes =
                                            lastPersistedBytes,
                                        lastPersistedProgress =
                                            lastPersistedProgress,
                                        transferredBytes =
                                            transferredBytes,
                                        totalBytes =
                                            totalBytes,
                                        progress =
                                            progress
                                    )
                            ) {
                                is NativeBackgroundDownloadProgressResult.Persisted -> {
                                    lastPersistedBytes =
                                        progressResult
                                            .transferredBytes

                                    lastPersistedProgress =
                                        progressResult.progress
                                }

                                NativeBackgroundDownloadProgressResult.Skipped ->
                                    Unit

                                NativeBackgroundDownloadProgressResult.PersistenceFailed ->
                                    persistenceFailed = true

                                NativeBackgroundDownloadProgressResult.ForegroundFailed ->
                                    foregroundFailed = true
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
                attemptOutcome is NativeBackgroundDownloadOutcome.Failed &&
                attemptOutcome.errorCode ==
                    NativeBackgroundTransferContract.NETWORK_ERROR &&
                networkRetryCount <
                    MAX_NETWORK_RETRY_COUNT
            ) {
                networkRetryCount += 1

                when (
                    val retry =
                        prepareNetworkRetry(
                            store = store,
                            transferId = transferId,
                            totalBytes =
                                attemptOutcome.totalBytes,
                            retryNumber =
                                networkRetryCount,
                            lastPersistedBytes =
                                lastPersistedBytes,
                            lastPersistedProgress =
                                lastPersistedProgress
                        )
                ) {
                    is NativeBackgroundDownloadRetryResult.Ready -> {
                        lastPersistedBytes =
                            retry.lastPersistedBytes

                        lastPersistedProgress =
                            retry.lastPersistedProgress

                        continue
                    }

                    NativeBackgroundDownloadRetryResult.Completed ->
                        return NativeBackgroundTransferExecutionResult
                            .Succeeded

                    NativeBackgroundDownloadRetryResult.Reschedule ->
                        return NativeBackgroundTransferExecutionResult
                            .Reschedule

                    is NativeBackgroundDownloadRetryResult.PersistenceFailed -> {
                        lastPersistedBytes =
                            retry.lastPersistedBytes

                        lastPersistedProgress =
                            retry.lastPersistedProgress

                        persistenceFailed = true
                        outcome = attemptOutcome
                        break
                    }

                    is NativeBackgroundDownloadRetryResult.ForegroundFailed -> {
                        lastPersistedBytes =
                            retry.lastPersistedBytes

                        lastPersistedProgress =
                            retry.lastPersistedProgress

                        foregroundFailed = true
                        outcome = attemptOutcome
                        break
                    }
                }
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

        return handleOutcome(
            store = store,
            transferId = transferId,
            outcome = outcome
        )
    }

    fun shouldStop(
        store: NativeBackgroundTransferStore,
        transferId: String
    ): Boolean {
        return isStopRequested() ||
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
    }

    fun persistProgress(
        store: NativeBackgroundTransferStore,
        transferId: String,
        lastPersistedBytes: Long,
        lastPersistedProgress: Int?,
        transferredBytes: Long,
        totalBytes: Long?,
        progress: Int?
    ): NativeBackgroundDownloadProgressResult {
        if (
            isStopRequested() ||
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundDownloadProgressResult
                .Skipped
        }

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

        if (!shouldPersist) {
            return NativeBackgroundDownloadProgressResult
                .Skipped
        }

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

        if (!store.update(progressResult)) {
            return if (
                isPersistedCancelled(
                    store = store,
                    transferId = transferId
                )
            ) {
                NativeBackgroundDownloadProgressResult
                    .Skipped
            } else {
                NativeBackgroundDownloadProgressResult
                    .PersistenceFailed
            }
        }

        if (
            !foregroundUpdater.update(
                status =
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                progress = progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_DOWNLOAD
            )
        ) {
            return NativeBackgroundDownloadProgressResult
                .ForegroundFailed
        }

        return NativeBackgroundDownloadProgressResult
            .Persisted(
                transferredBytes =
                    transferredBytes,
                progress =
                    progress
            )
    }

    fun prepareNetworkRetry(
        store: NativeBackgroundTransferStore,
        transferId: String,
        totalBytes: Long?,
        retryNumber: Int,
        lastPersistedBytes: Long,
        lastPersistedProgress: Int?
    ): NativeBackgroundDownloadRetryResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundDownloadRetryResult
                .Completed
        }

        val retryProgress =
            if (
                totalBytes != null &&
                totalBytes > 0L
            ) {
                0
            } else {
                null
            }

        val retryState =
            NativeBackgroundTransferResult(
                id = transferId,
                status =
                    NativeBackgroundTransferContract
                        .STATUS_QUEUED,
                transferredBytes = 0L,
                totalBytes = totalBytes,
                progress = retryProgress
            )

        if (!store.update(retryState)) {
            return if (
                isPersistedCancelled(
                    store = store,
                    transferId = transferId
                )
            ) {
                NativeBackgroundDownloadRetryResult
                    .Completed
            } else {
                NativeBackgroundDownloadRetryResult
                    .PersistenceFailed(
                        lastPersistedBytes =
                            lastPersistedBytes,
                        lastPersistedProgress =
                            lastPersistedProgress
                    )
            }
        }

        if (
            !foregroundUpdater.update(
                status =
                    NativeBackgroundTransferContract
                        .STATUS_QUEUED,
                progress = retryProgress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_DOWNLOAD
            )
        ) {
            return NativeBackgroundDownloadRetryResult
                .ForegroundFailed(
                    lastPersistedBytes = 0L,
                    lastPersistedProgress =
                        retryProgress
                )
        }

        if (
            !waitForNetworkRetry(
                store = store,
                transferId = transferId,
                retryNumber = retryNumber
            )
        ) {
            return if (
                isPersistedCancelled(
                    store = store,
                    transferId = transferId
                )
            ) {
                NativeBackgroundDownloadRetryResult
                    .Completed
            } else {
                NativeBackgroundDownloadRetryResult
                    .Reschedule
            }
        }

        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundDownloadRetryResult
                .Completed
        }

        if (isStopRequested()) {
            return NativeBackgroundDownloadRetryResult
                .Reschedule
        }

        val retryRunning =
            NativeBackgroundTransferResult(
                id = transferId,
                status =
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                transferredBytes = 0L,
                totalBytes = totalBytes,
                progress = retryProgress
            )

        if (!store.update(retryRunning)) {
            return if (
                isPersistedCancelled(
                    store = store,
                    transferId = transferId
                )
            ) {
                NativeBackgroundDownloadRetryResult
                    .Completed
            } else {
                NativeBackgroundDownloadRetryResult
                    .PersistenceFailed(
                        lastPersistedBytes = 0L,
                        lastPersistedProgress =
                            retryProgress
                    )
            }
        }

        if (
            !foregroundUpdater.update(
                status =
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                progress = retryProgress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_DOWNLOAD
            )
        ) {
            return NativeBackgroundDownloadRetryResult
                .ForegroundFailed(
                    lastPersistedBytes = 0L,
                    lastPersistedProgress =
                        retryProgress
                )
        }

        return NativeBackgroundDownloadRetryResult
            .Ready(
                lastPersistedBytes = 0L,
                lastPersistedProgress =
                    retryProgress
            )
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
                isStopRequested() ||
                isPersistedCancelled(
                    store = store,
                    transferId = transferId
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

    fun failTransfer(
        store: NativeBackgroundTransferStore,
        transferId: String,
        errorCode: String,
        transferredBytes: Long,
        totalBytes: Long?,
        progress: Int?
    ): NativeBackgroundTransferExecutionResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundTransferExecutionResult
                .Succeeded
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
            NativeBackgroundTransferExecutionResult
                .Failed
        } else if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            NativeBackgroundTransferExecutionResult
                .Succeeded
        } else {
            NativeBackgroundTransferExecutionResult
                .Failed
        }
    }

    fun handleOutcome(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundDownloadOutcome
    ): NativeBackgroundTransferExecutionResult {
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
    ): NativeBackgroundTransferExecutionResult {
        val success =
            outcome.result

        val mimeType =
            success.mimeType
                ?: return NativeBackgroundTransferExecutionResult
                    .Failed

        val filePolicy =
            NativeBackgroundTransferFilePolicy(
                applicationContext
            )

        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            filePolicy.deleteFinalFor(
                transferId = transferId,
                mimeType = mimeType
            )

            return NativeBackgroundTransferExecutionResult
                .Succeeded
        }

        if (store.update(success)) {
            return NativeBackgroundTransferExecutionResult
                .Succeeded
        }

        /*
         * Cancellation may win between the engine's final check
         * and persistence of the terminal result.
         */
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            filePolicy.deleteFinalFor(
                transferId = transferId,
                mimeType = mimeType
            )

            return NativeBackgroundTransferExecutionResult
                .Succeeded
        }

        /*
         * Never retain a completed private file when its terminal
         * result could not be persisted.
         */
        filePolicy.deleteFinalFor(
            transferId = transferId,
            mimeType = mimeType
        )

        return NativeBackgroundTransferExecutionResult
            .Failed
    }

    private fun handleFailure(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundDownloadOutcome.Failed
    ): NativeBackgroundTransferExecutionResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundTransferExecutionResult
                .Succeeded
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
            return NativeBackgroundTransferExecutionResult
                .Failed
        }

        return if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            NativeBackgroundTransferExecutionResult
                .Succeeded
        } else {
            NativeBackgroundTransferExecutionResult
                .Failed
        }
    }

    private fun handleStoppedOrCancelled(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundDownloadOutcome.Cancelled
    ): NativeBackgroundTransferExecutionResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundTransferExecutionResult
                .Succeeded
        }

        /*
         * A platform/system stop is not a user cancellation.
         * The engine already removed its partial file, so reset
         * streamed progress before requesting rescheduling.
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
            return NativeBackgroundTransferExecutionResult
                .Reschedule
        }

        return if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            NativeBackgroundTransferExecutionResult
                .Succeeded
        } else {
            NativeBackgroundTransferExecutionResult
                .Failed
        }
    }

    fun prepare(
        transferId: String
    ): NativeBackgroundDownloadPreparationResult {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(
                    transferId
                )
                ?: return NativeBackgroundDownloadPreparationResult
                    .Failed

        if (normalizedId != transferId) {
            return NativeBackgroundDownloadPreparationResult
                .Failed
        }

        val store =
            NativeBackgroundTransferStore(
                applicationContext
            )

        val request =
            store.request(transferId)
                ?: return NativeBackgroundDownloadPreparationResult
                    .Failed

        val current =
            store.result(transferId)
                ?: return NativeBackgroundDownloadPreparationResult
                    .Failed

        if (
            NativeBackgroundTransferContract
                .isTerminalStatus(
                    current.status
                )
        ) {
            return NativeBackgroundDownloadPreparationResult
                .Completed
        }

        return NativeBackgroundDownloadPreparationResult
            .Ready(
                transferId = transferId,
                store = store,
                request = request,
                current = current
            )
    }
}

internal sealed interface NativeBackgroundDownloadPreparationResult {

    data class Ready(
        val transferId: String,
        val store: NativeBackgroundTransferStore,
        val request: NativeBackgroundTransferRequest,
        val current: NativeBackgroundTransferResult
    ) : NativeBackgroundDownloadPreparationResult

    data object Completed :
        NativeBackgroundDownloadPreparationResult

    data object Failed :
        NativeBackgroundDownloadPreparationResult
}
internal sealed interface NativeBackgroundDownloadStartResult {

    data class Running(
        val running: NativeBackgroundTransferResult
    ) : NativeBackgroundDownloadStartResult

    data object Completed :
        NativeBackgroundDownloadStartResult

    data object Reschedule :
        NativeBackgroundDownloadStartResult

    data object Failed :
        NativeBackgroundDownloadStartResult
}

private const val PROGRESS_BYTE_INTERVAL =
    1024L * 1024L
internal sealed interface NativeBackgroundDownloadProgressResult {

    data class Persisted(
        val transferredBytes: Long,
        val progress: Int?
    ) : NativeBackgroundDownloadProgressResult

    data object Skipped :
        NativeBackgroundDownloadProgressResult

    data object PersistenceFailed :
        NativeBackgroundDownloadProgressResult

    data object ForegroundFailed :
        NativeBackgroundDownloadProgressResult
}
internal sealed interface NativeBackgroundDownloadRetryResult {

    data class Ready(
        val lastPersistedBytes: Long,
        val lastPersistedProgress: Int?
    ) : NativeBackgroundDownloadRetryResult

    data object Completed :
        NativeBackgroundDownloadRetryResult

    data object Reschedule :
        NativeBackgroundDownloadRetryResult

    data class PersistenceFailed(
        val lastPersistedBytes: Long,
        val lastPersistedProgress: Int?
    ) : NativeBackgroundDownloadRetryResult

    data class ForegroundFailed(
        val lastPersistedBytes: Long,
        val lastPersistedProgress: Int?
    ) : NativeBackgroundDownloadRetryResult
}

private const val NETWORK_RETRY_BASE_DELAY_MILLIS =
    30_000L

private const val NETWORK_RETRY_POLL_INTERVAL_MILLIS =
    250L

/*
 * Three bounded network retries are allowed after
 * the initial attempt.
 */
private const val MAX_NETWORK_RETRY_COUNT =
    3