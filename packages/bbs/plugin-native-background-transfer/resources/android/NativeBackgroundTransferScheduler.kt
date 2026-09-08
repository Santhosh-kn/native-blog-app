package com.bbs.plugins.native_background_transfer

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.Data
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
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

        val inputData = Data.Builder()
            .putString(
                INPUT_TRANSFER_ID,
                transferId
            )
            .build()

        val constraints = Constraints.Builder()
            .setRequiredNetworkType(
                NetworkType.CONNECTED
            )
            .build()

        val workRequest =
            OneTimeWorkRequest.Builder(
                NativeBackgroundDownloadWorker::class.java
            )
                .setInputData(inputData)
                .setConstraints(constraints)
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
                    workName(transferId),
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
            WorkManager
                .getInstance(applicationContext)
                .cancelUniqueWork(
                    workName(transferId)
                )

            true
        } catch (_: Exception) {
            false
        }
    }

    private fun workName(
        transferId: String
    ): String {
        return "$WORK_NAME_PREFIX$transferId"
    }

    private fun transferTag(
        transferId: String
    ): String {
        return "$TRANSFER_TAG_PREFIX$transferId"
    }

    companion object {
        const val INPUT_TRANSFER_ID =
            "native_background_transfer_id"

        private const val WORK_NAME_PREFIX =
            "native-background-download-"

        private const val TRANSFER_TAG_PREFIX =
            "native-background-transfer-"

        private const val WORK_TAG =
            "native-background-transfer"

        private const val BACKOFF_SECONDS =
            30L
    }
}