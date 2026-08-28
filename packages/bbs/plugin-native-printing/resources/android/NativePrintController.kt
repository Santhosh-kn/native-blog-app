package com.bbs.plugins.native_printing

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.print.PrintJob
import android.print.PrintJobInfo
import android.print.PrintManager
import androidx.fragment.app.FragmentActivity
import java.io.File
import java.lang.ref.WeakReference
import java.util.UUID
import java.util.concurrent.atomic.AtomicBoolean

internal object NativePrintController {

    private const val POLL_INTERVAL_MILLISECONDS = 750L

    private val mainHandler = Handler(
        Looper.getMainLooper()
    )

    private val activeJobs =
        mutableMapOf<String, PrintLifecycle>()

    fun enqueue(
        activity: FragmentActivity,
        file: File,
        documentName: String,
        pageCount: Int,
        requestId: String
    ): Boolean {
        return mainHandler.post {
            startOnMainThread(
                activity = activity,
                file = file,
                documentName = documentName,
                pageCount = pageCount,
                requestId = requestId
            )
        }
    }

    private fun startOnMainThread(
        activity: FragmentActivity,
        file: File,
        documentName: String,
        pageCount: Int,
        requestId: String
    ) {
        if (
            activity.isFinishing ||
            activity.isDestroyed
        ) {
            NativePrintingEvents.dispatchState(
                sourceActivity = activity,
                requestId = requestId,
                action = NativePrintingContract.ACTION_PRINT,
                status = NativePrintingContract.STATUS_FAILED,
                errorCode =
                    NativePrintingContract.ACTIVITY_UNAVAILABLE,
                errorMessage =
                    "The native activity is unavailable."
            )

            return
        }

        val printManager = activity.getSystemService(
            Context.PRINT_SERVICE
        ) as? PrintManager

        if (printManager == null) {
            NativePrintingEvents.dispatchState(
                sourceActivity = activity,
                requestId = requestId,
                action = NativePrintingContract.ACTION_PRINT,
                status = NativePrintingContract.STATUS_FAILED,
                errorCode =
                    NativePrintingContract.PRINTING_UNAVAILABLE,
                errorMessage =
                    "Android printing is unavailable."
            )

            return
        }

        val lifecycle = PrintLifecycle(
            activity = activity,
            requestId = requestId
        )

        val adapter = NativePdfPrintDocumentAdapter(
            file = file,
            documentName = documentName,
            pageCount = pageCount,
            onFailure = lifecycle::adapterFailed
        )

        try {
            val printJob = printManager.print(
                documentName,
                adapter,
                null
            )

            lifecycle.attach(printJob)
            lifecycle.start()
        } catch (_: Exception) {
            lifecycle.failImmediately(
                errorCode =
                    NativePrintingContract.PRINT_DIALOG_FAILED,
                errorMessage =
                    "The Android print interface could not be opened."
            )
        }
    }

    private data class ObservedState(
        val status: String,
        val terminal: Boolean,
        val errorCode: String? = null,
        val errorMessage: String? = null
    )

    private class PrintLifecycle(
        activity: FragmentActivity,
        private val requestId: String
    ) {
        private val activityReference =
            WeakReference(activity)

        private val terminal = AtomicBoolean(false)

        private var printJob: PrintJob? = null

        private var jobId: String? = null

        private var lastStatus: String? = null

        private val pollRunnable = Runnable {
            poll()
        }

        fun attach(job: PrintJob) {
            printJob = job
            jobId = UUID.randomUUID().toString()
        }

        fun start() {
            if (terminal.get()) {
                return
            }

            val resolvedJobId = jobId

            if (resolvedJobId == null) {
                failImmediately(
                    errorCode =
                        NativePrintingContract.PRINT_DIALOG_FAILED,
                    errorMessage =
                        "The Android print interface could not be opened."
                )

                return
            }

            activeJobs[resolvedJobId] = this

            emit(
                status =
                    NativePrintingContract.STATUS_PRESENTED
            )

            lastStatus =
                NativePrintingContract.STATUS_PRESENTED

            mainHandler.postDelayed(
                pollRunnable,
                POLL_INTERVAL_MILLISECONDS
            )
        }

        fun adapterFailed(
            errorCode: String,
            errorMessage: String
        ) {
            mainHandler.post {
                finish(
                    status =
                        NativePrintingContract.STATUS_FAILED,
                    errorCode = errorCode,
                    errorMessage = errorMessage
                )
            }
        }

        fun failImmediately(
            errorCode: String,
            errorMessage: String
        ) {
            finish(
                status =
                    NativePrintingContract.STATUS_FAILED,
                errorCode = errorCode,
                errorMessage = errorMessage
            )
        }

        private fun poll() {
            if (terminal.get()) {
                return
            }

            val job = printJob

            if (job == null) {
                finish(
                    status =
                        NativePrintingContract.STATUS_FAILED,
                    errorCode =
                        NativePrintingContract.PRINT_JOB_FAILED,
                    errorMessage =
                        "The Android print job could not be monitored."
                )

                return
            }

            val state = try {
                job.info.state
            } catch (_: Exception) {
                finish(
                    status =
                        NativePrintingContract.STATUS_FAILED,
                    errorCode =
                        NativePrintingContract.PRINT_JOB_FAILED,
                    errorMessage =
                        "The Android print job could not be monitored."
                )

                return
            }

            val observed = mapState(state)

            if (observed != null) {
                if (observed.terminal) {
                    finish(
                        status = observed.status,
                        errorCode = observed.errorCode,
                        errorMessage = observed.errorMessage
                    )

                    return
                }

                if (observed.status != lastStatus) {
                    emit(status = observed.status)
                    lastStatus = observed.status
                }
            }

            mainHandler.postDelayed(
                pollRunnable,
                POLL_INTERVAL_MILLISECONDS
            )
        }

        private fun mapState(
            state: Int
        ): ObservedState? {
            return when (state) {
                PrintJobInfo.STATE_CREATED -> {
                    ObservedState(
                        status =
                            NativePrintingContract.STATUS_PRESENTED,
                        terminal = false
                    )
                }

                PrintJobInfo.STATE_QUEUED,
                PrintJobInfo.STATE_STARTED -> {
                    ObservedState(
                        status =
                            NativePrintingContract.STATUS_SUBMITTED,
                        terminal = false
                    )
                }

                PrintJobInfo.STATE_BLOCKED -> {
                    ObservedState(
                        status =
                            NativePrintingContract.STATUS_BLOCKED,
                        terminal = false
                    )
                }

                PrintJobInfo.STATE_COMPLETED -> {
                    ObservedState(
                        status =
                            NativePrintingContract.STATUS_COMPLETED,
                        terminal = true
                    )
                }

                PrintJobInfo.STATE_FAILED -> {
                    ObservedState(
                        status =
                            NativePrintingContract.STATUS_FAILED,
                        terminal = true,
                        errorCode =
                            NativePrintingContract.PRINT_JOB_FAILED,
                        errorMessage =
                            "The Android print job failed."
                    )
                }

                PrintJobInfo.STATE_CANCELED -> {
                    ObservedState(
                        status =
                            NativePrintingContract.STATUS_CANCELLED,
                        terminal = true,
                        errorCode =
                            NativePrintingContract.PRINT_CANCELLED,
                        errorMessage =
                            "The Android print job was cancelled."
                    )
                }

                else -> null
            }
        }

        private fun finish(
            status: String,
            errorCode: String?,
            errorMessage: String?
        ) {
            if (!terminal.compareAndSet(false, true)) {
                return
            }

            mainHandler.removeCallbacks(pollRunnable)

            jobId?.let { resolvedJobId ->
                activeJobs.remove(resolvedJobId)
            }

            emit(
                status = status,
                errorCode = errorCode,
                errorMessage = errorMessage
            )

            lastStatus = status
        }

        private fun emit(
            status: String,
            errorCode: String? = null,
            errorMessage: String? = null
        ) {
            NativePrintingEvents.dispatchState(
                sourceActivity = activityReference.get(),
                requestId = requestId,
                action = NativePrintingContract.ACTION_PRINT,
                status = status,
                jobId = jobId,
                errorCode = errorCode,
                errorMessage = errorMessage
            )
        }
    }
}
