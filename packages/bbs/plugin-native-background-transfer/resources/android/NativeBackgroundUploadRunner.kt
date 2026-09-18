package com.bbs.plugins.native_background_transfer

import android.content.Context

/*
 * Platform-neutral orchestration home for upload transfers.
 *
 * Upload behavior is moved here incrementally so WorkManager and
 * Android 14+ UIDT can share the same durable transfer semantics.
 */
internal class NativeBackgroundUploadRunner(
    context: Context,
    private val foregroundUpdater:
        NativeBackgroundTransferForegroundUpdater,
    private val isStopRequested: () -> Boolean
) {

    private val applicationContext =
        context.applicationContext

    fun execute(
        rawTransferId: String
    ): NativeBackgroundTransferExecutionResult {
        val ready =
            when (
                val preparation =
                    prepare(
                        rawTransferId
                    )
            ) {
                is NativeBackgroundUploadPreparationResult.Ready ->
                    preparation

                NativeBackgroundUploadPreparationResult.Completed ->
                    return NativeBackgroundTransferExecutionResult
                        .Succeeded

                NativeBackgroundUploadPreparationResult.Failed ->
                    return NativeBackgroundTransferExecutionResult
                        .Failed
            }
        val transferId =
            ready.transferId

        val store =
            ready.store

        val request =
            ready.request

        val source =
            ready.source

        val initialProgress =
            ready.initialProgress

        when (
            start(
                ready = ready,
                foregroundUpdater =
                    foregroundUpdater
            )
        ) {
            is NativeBackgroundUploadStartResult.Running ->
                Unit

            NativeBackgroundUploadStartResult.Completed ->
                return NativeBackgroundTransferExecutionResult
                    .Succeeded

            NativeBackgroundUploadStartResult.Failed ->
                return NativeBackgroundTransferExecutionResult
                    .Failed
        }

        var lastPersistedBytes = 0L

        var lastPersistedProgress =
            initialProgress

        var persistenceFailed = false
        var foregroundFailed = false

        val engine =
            NativeBackgroundUploadEngine()

        var networkRetryCount = 0

        var outcome:
            NativeBackgroundUploadOutcome

        while (true) {
            /*
             * Every upload attempt, including an internal retry,
             * restarts the request body from byte zero.
             */
            lastPersistedBytes = 0L
            lastPersistedProgress =
                initialProgress

            val attemptOutcome =
                engine.upload(
                    request = request,
                    source = source,

                    isCancelled = {
                        isStopRequested() ||
                            persistenceFailed ||
                            foregroundFailed ||
                            isPersistedCancelled(
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
                                        transferId =
                                            transferId,
                                        lastPersistedBytes =
                                            lastPersistedBytes,
                                        lastPersistedProgress =
                                            lastPersistedProgress,
                                        transferredBytes =
                                            transferredBytes,
                                        totalBytes =
                                            totalBytes,
                                        progress =
                                            progress,
                                        foregroundUpdater =
                                            foregroundUpdater
                                    )
                            ) {
                                is NativeBackgroundUploadProgressResult.Persisted -> {
                                    lastPersistedBytes =
                                        progressResult
                                            .transferredBytes

                                    lastPersistedProgress =
                                        progressResult
                                            .progress
                                }

                                NativeBackgroundUploadProgressResult.Skipped ->
                                    Unit

                                NativeBackgroundUploadProgressResult.PersistenceFailed ->
                                    persistenceFailed =
                                        true

                                NativeBackgroundUploadProgressResult.ForegroundFailed ->
                                    foregroundFailed =
                                        true
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
                attemptOutcome is
                    NativeBackgroundUploadOutcome.Failed &&
                attemptOutcome.errorCode ==
                    NativeBackgroundTransferContract
                        .NETWORK_ERROR &&
                networkRetryCount <
                    UPLOAD_MAX_NETWORK_RETRY_COUNT
            ) {
                networkRetryCount += 1

                when (
                    val retry =
                        prepareNetworkRetry(
                            store = store,
                            transferId =
                                transferId,
                            totalBytes =
                                source.size,
                            retryNumber =
                                networkRetryCount,
                            retryProgress =
                                initialProgress,
                            lastPersistedBytes =
                                lastPersistedBytes,
                            lastPersistedProgress =
                                lastPersistedProgress,
                            foregroundUpdater =
                                foregroundUpdater
                        )
                ) {
                    is NativeBackgroundUploadRetryResult.Ready -> {
                        lastPersistedBytes =
                            retry.lastPersistedBytes

                        lastPersistedProgress =
                            retry.lastPersistedProgress

                        continue
                    }

                    NativeBackgroundUploadRetryResult.Completed ->
                        return NativeBackgroundTransferExecutionResult
                            .Succeeded

                    NativeBackgroundUploadRetryResult.Reschedule ->
                        return NativeBackgroundTransferExecutionResult
                            .Reschedule

                    is NativeBackgroundUploadRetryResult.PersistenceFailed -> {
                        lastPersistedBytes =
                            retry.lastPersistedBytes

                        lastPersistedProgress =
                            retry.lastPersistedProgress

                        persistenceFailed = true
                        outcome = attemptOutcome
                        break
                    }

                    is NativeBackgroundUploadRetryResult.ForegroundFailed -> {
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

        return handleOutcome(
            store = store,
            transferId = transferId,
            outcome = outcome
        )
    }
    fun start(
        ready: NativeBackgroundUploadPreparationResult.Ready,
        foregroundUpdater:
            NativeBackgroundTransferForegroundUpdater
    ): NativeBackgroundUploadStartResult {
        val transferId =
            ready.transferId

        val store =
            ready.store

        val source =
            ready.source

        val initialProgress =
            ready.initialProgress

        if (
            !foregroundUpdater.update(
                status =
                    NativeBackgroundTransferContract
                        .STATUS_RUNNING,
                progress = initialProgress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
            )
        ) {
            return failStart(
                store = store,
                transferId = transferId,
                errorCode =
                    NativeBackgroundTransferContract
                        .SCHEDULER_UNAVAILABLE,
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

        if (store.update(running)) {
            return NativeBackgroundUploadStartResult
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
            NativeBackgroundUploadStartResult
                .Completed
        } else {
            NativeBackgroundUploadStartResult
                .Failed
        }
    }

    private fun failStart(
        store: NativeBackgroundTransferStore,
        transferId: String,
        errorCode: String,
        totalBytes: Long?,
        progress: Int?
    ): NativeBackgroundUploadStartResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundUploadStartResult
                .Completed
        }

        val failed =
            NativeBackgroundTransferResult.failed(
                id = transferId,
                errorCode = errorCode,
                transferredBytes = 0L,
                totalBytes = totalBytes,
                progress = progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
            )

        return if (store.update(failed)) {
            NativeBackgroundUploadStartResult
                .Failed
        } else if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            NativeBackgroundUploadStartResult
                .Completed
        } else {
            NativeBackgroundUploadStartResult
                .Failed
        }
    }
    fun persistProgress(
        store: NativeBackgroundTransferStore,
        transferId: String,
        lastPersistedBytes: Long,
        lastPersistedProgress: Int?,
        transferredBytes: Long,
        totalBytes: Long?,
        progress: Int?,
        foregroundUpdater:
            NativeBackgroundTransferForegroundUpdater
    ): NativeBackgroundUploadProgressResult {
        if (
            isStopRequested() ||
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundUploadProgressResult
                .Skipped
        }

        val shouldPersist =
            when {
                progress != lastPersistedProgress ->
                    true

                transferredBytes -
                    lastPersistedBytes >=
                    UPLOAD_PROGRESS_BYTE_INTERVAL ->
                    true

                else ->
                    false
            }

        if (!shouldPersist) {
            return NativeBackgroundUploadProgressResult
                .Skipped
        }

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

        if (!store.update(progressResult)) {
            return if (
                isPersistedCancelled(
                    store = store,
                    transferId = transferId
                )
            ) {
                NativeBackgroundUploadProgressResult
                    .Skipped
            } else {
                NativeBackgroundUploadProgressResult
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
                        .TYPE_UPLOAD
            )
        ) {
            return NativeBackgroundUploadProgressResult
                .ForegroundFailed
        }

        return NativeBackgroundUploadProgressResult
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
        retryProgress: Int?,
        lastPersistedBytes: Long,
        lastPersistedProgress: Int?,
        foregroundUpdater:
            NativeBackgroundTransferForegroundUpdater
    ): NativeBackgroundUploadRetryResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundUploadRetryResult
                .Completed
        }

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
                NativeBackgroundUploadRetryResult
                    .Completed
            } else {
                NativeBackgroundUploadRetryResult
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
                        .TYPE_UPLOAD
            )
        ) {
            return NativeBackgroundUploadRetryResult
                .ForegroundFailed(
                    lastPersistedBytes =
                        lastPersistedBytes,
                    lastPersistedProgress =
                        lastPersistedProgress
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
                NativeBackgroundUploadRetryResult
                    .Completed
            } else {
                NativeBackgroundUploadRetryResult
                    .Reschedule
            }
        }

        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundUploadRetryResult
                .Completed
        }

        if (isStopRequested()) {
            return NativeBackgroundUploadRetryResult
                .Reschedule
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
                NativeBackgroundUploadRetryResult
                    .Completed
            } else {
                NativeBackgroundUploadRetryResult
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
                        .STATUS_RUNNING,
                progress = retryProgress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
            )
        ) {
            return NativeBackgroundUploadRetryResult
                .ForegroundFailed(
                    lastPersistedBytes =
                        lastPersistedBytes,
                    lastPersistedProgress =
                        lastPersistedProgress
                )
        }

        return NativeBackgroundUploadRetryResult
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
            UPLOAD_NETWORK_RETRY_BASE_DELAY_MILLIS *
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
                    UPLOAD_NETWORK_RETRY_POLL_INTERVAL_MILLIS,
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
                    progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
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
        outcome: NativeBackgroundUploadOutcome
    ): NativeBackgroundTransferExecutionResult {
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

        if (store.update(outcome.result)) {
            return NativeBackgroundTransferExecutionResult
                .Succeeded
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

    private fun handleFailure(
        store: NativeBackgroundTransferStore,
        transferId: String,
        outcome: NativeBackgroundUploadOutcome.Failed
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
                    outcome.progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
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
        outcome: NativeBackgroundUploadOutcome.Cancelled
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
         * Upload attempts restart from byte zero.
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
        rawTransferId: String
    ): NativeBackgroundUploadPreparationResult {
        val transferId =
            NativeBackgroundTransferContract
                .normalizeRequestId(
                    rawTransferId
                )
                ?: return NativeBackgroundUploadPreparationResult
                    .Failed

        val store =
            NativeBackgroundTransferStore(
                applicationContext
            )

        val request =
            store.storedRequest(transferId)
                as? NativeBackgroundUploadRequest
                ?: return NativeBackgroundUploadPreparationResult
                    .Failed

        if (
            request.id != transferId ||
            request.type !=
                NativeBackgroundTransferContract.TYPE_UPLOAD
        ) {
            return NativeBackgroundUploadPreparationResult
                .Failed
        }

        val current =
            store.result(transferId)
                ?: return NativeBackgroundUploadPreparationResult
                    .Failed

        if (
            current.id != transferId ||
            current.type !=
                NativeBackgroundTransferContract.TYPE_UPLOAD
        ) {
            return NativeBackgroundUploadPreparationResult
                .Failed
        }

        if (
            NativeBackgroundTransferContract
                .isTerminalStatus(
                    current.status
                )
        ) {
            return NativeBackgroundUploadPreparationResult
                .Completed
        }

        val source =
            when (
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

        return NativeBackgroundUploadPreparationResult
            .Ready(
                transferId = transferId,
                store = store,
                request = request,
                source = source,
                initialProgress = initialProgress
            )
    }

    private fun persistSourceFailure(
        store: NativeBackgroundTransferStore,
        transferId: String,
        current: NativeBackgroundTransferResult,
        errorCode: String
    ): NativeBackgroundUploadPreparationResult {
        if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            return NativeBackgroundUploadPreparationResult
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
                    current.progress,
                type =
                    NativeBackgroundTransferContract
                        .TYPE_UPLOAD
            )

        if (store.update(failed)) {
            return NativeBackgroundUploadPreparationResult
                .Failed
        }

        return if (
            isPersistedCancelled(
                store = store,
                transferId = transferId
            )
        ) {
            NativeBackgroundUploadPreparationResult
                .Completed
        } else {
            NativeBackgroundUploadPreparationResult
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
}

internal sealed interface NativeBackgroundUploadPreparationResult {

    data class Ready(
        val transferId: String,
        val store: NativeBackgroundTransferStore,
        val request: NativeBackgroundUploadRequest,
        val source: NativeBackgroundUploadSourceResolution.Resolved,
        val initialProgress: Int?
    ) : NativeBackgroundUploadPreparationResult

    data object Completed :
        NativeBackgroundUploadPreparationResult

    data object Failed :
        NativeBackgroundUploadPreparationResult
}
internal sealed interface NativeBackgroundUploadStartResult {

    data class Running(
        val running: NativeBackgroundTransferResult
    ) : NativeBackgroundUploadStartResult

    data object Completed :
        NativeBackgroundUploadStartResult

    data object Failed :
        NativeBackgroundUploadStartResult
}
internal sealed interface NativeBackgroundUploadProgressResult {

    data class Persisted(
        val transferredBytes: Long,
        val progress: Int?
    ) : NativeBackgroundUploadProgressResult

    data object Skipped :
        NativeBackgroundUploadProgressResult

    data object PersistenceFailed :
        NativeBackgroundUploadProgressResult

    data object ForegroundFailed :
        NativeBackgroundUploadProgressResult
}

private const val UPLOAD_PROGRESS_BYTE_INTERVAL =
    1024L * 1024L
internal sealed interface NativeBackgroundUploadRetryResult {

    data class Ready(
        val lastPersistedBytes: Long,
        val lastPersistedProgress: Int?
    ) : NativeBackgroundUploadRetryResult

    data object Completed :
        NativeBackgroundUploadRetryResult

    data object Reschedule :
        NativeBackgroundUploadRetryResult

    data class PersistenceFailed(
        val lastPersistedBytes: Long,
        val lastPersistedProgress: Int?
    ) : NativeBackgroundUploadRetryResult

    data class ForegroundFailed(
        val lastPersistedBytes: Long,
        val lastPersistedProgress: Int?
    ) : NativeBackgroundUploadRetryResult
}

private const val UPLOAD_NETWORK_RETRY_BASE_DELAY_MILLIS =
    30_000L

private const val UPLOAD_NETWORK_RETRY_POLL_INTERVAL_MILLIS =
    250L
private const val UPLOAD_MAX_NETWORK_RETRY_COUNT =
    3
