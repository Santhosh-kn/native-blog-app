package com.bbs.plugins.native_background_transfer

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Data
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequest
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

internal class NativeBackgroundTransferScheduler(
    context: Context
) {

    private val applicationContext =
        context.applicationContext

    fun enqueueDownload(
        transferId: String
    ): Boolean {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(transferId)
                ?: return false

        if (normalizedId != transferId) {
            return false
        }

        val inputData =
            transferInputData(transferId)

        val workRequest =
            OneTimeWorkRequest.Builder(
                NativeBackgroundDownloadWorker::class.java
            )
                .setInputData(inputData)
                .setBackoffCriteria(
                    BackoffPolicy.EXPONENTIAL,
                    BACKOFF_SECONDS,
                    TimeUnit.SECONDS
                )
                .addTag(WORK_TAG)
                .addTag(
                    transferTag(transferId)
                )
                .build()

        return try {
            WorkManager
                .getInstance(applicationContext)
                .enqueueUniqueWork(
                    downloadWorkName(transferId),
                    ExistingWorkPolicy.KEEP,
                    workRequest
                )

            true
        } catch (_: Exception) {
            false
        }
    }

    fun enqueueUpload(
        transferId: String
    ): Boolean {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(transferId)
                ?: return false

        if (normalizedId != transferId) {
            return false
        }

        val inputData =
            transferInputData(transferId)

        val workRequest =
            OneTimeWorkRequest.Builder(
                NativeBackgroundUploadWorker::class.java
            )
                .setInputData(inputData)
                .setBackoffCriteria(
                    BackoffPolicy.EXPONENTIAL,
                    BACKOFF_SECONDS,
                    TimeUnit.SECONDS
                )
                .addTag(WORK_TAG)
                .addTag(
                    transferTag(transferId)
                )
                .build()

        return try {
            WorkManager
                .getInstance(applicationContext)
                .enqueueUniqueWork(
                    uploadWorkName(transferId),
                    ExistingWorkPolicy.KEEP,
                    workRequest
                )

            true
        } catch (_: Exception) {
            false
        }
    }

    fun cancel(
        transferId: String
    ): Boolean {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(transferId)
                ?: return false

        if (normalizedId != transferId) {
            return false
        }

        return try {
            val workManager =
                WorkManager.getInstance(
                    applicationContext
                )

            /*
             * Keep cancellation compatible with both transfer
             * directions. The existing download work-name prefix
             * remains unchanged.
             */
            workManager.cancelUniqueWork(
                downloadWorkName(transferId)
            )

            workManager.cancelUniqueWork(
                uploadWorkName(transferId)
            )

            true
        } catch (_: Exception) {
            false
        }
    }

    private fun transferInputData(
        transferId: String
    ): Data {
        return Data.Builder()
            .putString(
                INPUT_TRANSFER_ID,
                transferId
            )
            .build()
    }

    private fun downloadWorkName(
        transferId: String
    ): String {
        return "$DOWNLOAD_WORK_NAME_PREFIX$transferId"
    }

    private fun uploadWorkName(
        transferId: String
    ): String {
        return "$UPLOAD_WORK_NAME_PREFIX$transferId"
    }

    private fun transferTag(
        transferId: String
    ): String {
        return "$TRANSFER_TAG_PREFIX$transferId"
    }

    companion object {
        const val INPUT_TRANSFER_ID =
            "native_background_transfer_id"

        /*
         * Do not rename this. Existing download work may already
         * have been scheduled with this exact unique-work prefix.
         */
        private const val DOWNLOAD_WORK_NAME_PREFIX =
            "native-background-download-"

        private const val UPLOAD_WORK_NAME_PREFIX =
            "native-background-upload-"

        private const val TRANSFER_TAG_PREFIX =
            "native-background-transfer-"

        private const val WORK_TAG =
            "native-background-transfer"

        private const val BACKOFF_SECONDS =
            30L
    }
}