package com.bbs.plugins.native_background_transfer

/*
 * Platform-neutral result returned by a transfer runner.
 *
 * WorkManager and Android 14+ UIDT translate these outcomes into
 * their own completion/rescheduling APIs.
 */
internal sealed interface NativeBackgroundTransferExecutionResult {

    data object Succeeded :
        NativeBackgroundTransferExecutionResult

    data object Failed :
        NativeBackgroundTransferExecutionResult

    data object Reschedule :
        NativeBackgroundTransferExecutionResult
}

/*
 * Allows transfer runners to update foreground/user-visible
 * execution state without depending directly on WorkManager or
 * JobService APIs.
 */
internal fun interface NativeBackgroundTransferForegroundUpdater {

    fun update(
        status: String,
        progress: Int?,
        type: String
    ): Boolean
}