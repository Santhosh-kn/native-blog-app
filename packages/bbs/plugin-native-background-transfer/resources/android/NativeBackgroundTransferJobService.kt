package com.bbs.plugins.native_background_transfer

import android.app.job.JobParameters
import android.app.job.JobService

/*
 * Android 14+ execution shell for user-initiated data transfers.
 *
 * Scheduling and transfer execution will be connected in a later
 * reliability step. Until then, existing transfers continue to use
 * WorkManager.
 */
internal class NativeBackgroundTransferJobService :
    JobService() {

    override fun onStartJob(
        params: JobParameters
    ): Boolean {
        /*
         * No UIDT work is scheduled yet.
         *
         * Returning false means there is currently no asynchronous
         * work associated with this JobService invocation.
         */
        return false
    }

    override fun onStopJob(
        params: JobParameters
    ): Boolean {
        /*
         * Recovery/rescheduling behavior will be implemented when
         * the UIDT execution path is connected.
         */
        return false
    }
}
