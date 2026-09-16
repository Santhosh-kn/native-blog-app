package com.bbs.plugins.native_background_transfer

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.pm.ServiceInfo
import androidx.work.ForegroundInfo

internal class NativeBackgroundTransferNotifications(
    context: Context
) {

    private val applicationContext =
        context.applicationContext

    private val notificationManager =
        applicationContext.getSystemService(
            NotificationManager::class.java
        )

    init {
        createChannel()
    }

    fun foregroundInfo(
        transferId: String,
        status: String,
        progress: Int?,
        type: String =
            NativeBackgroundTransferContract.TYPE_DOWNLOAD
    ): ForegroundInfo {
        val safeProgress =
            progress?.coerceIn(
                0,
                100
            )

        val isUpload =
            type ==
                NativeBackgroundTransferContract
                    .TYPE_UPLOAD

        val action =
            if (isUpload) {
                "Uploading"
            } else {
                "Downloading"
            }

        val title =
            if (isUpload) {
                "Background upload"
            } else {
                "Background download"
            }

        val icon =
            if (isUpload) {
                android.R.drawable.stat_sys_upload
            } else {
                android.R.drawable.stat_sys_download
            }

        val text = when {
            status ==
                NativeBackgroundTransferContract.STATUS_QUEUED ->
                "Waiting for network"

            safeProgress != null ->
                "$action $safeProgress%"

            else ->
                action
        }

        val notification =
            Notification.Builder(
                applicationContext,
                CHANNEL_ID
            )
                .setSmallIcon(icon)
                .setContentTitle(title)
                .setContentText(text)
                .setCategory(
                    Notification.CATEGORY_PROGRESS
                )
                .setVisibility(
                    Notification.VISIBILITY_PRIVATE
                )
                .setOnlyAlertOnce(true)
                .setOngoing(true)
                .setProgress(
                    100,
                    safeProgress ?: 0,
                    safeProgress == null
                )
                .build()

        return ForegroundInfo(
            notificationId(transferId),
            notification,
            ServiceInfo
                .FOREGROUND_SERVICE_TYPE_DATA_SYNC
        )
    }
    private fun createChannel() {
        val channel =
            NotificationChannel(
                CHANNEL_ID,
                CHANNEL_NAME,
                NotificationManager
                    .IMPORTANCE_LOW
            ).apply {
                description =
                    "Background file transfer progress"

                setShowBadge(false)
            }

        notificationManager
            ?.createNotificationChannel(
                channel
            )
    }

    private fun notificationId(
        transferId: String
    ): Int {
        val suffix =
            transferId.hashCode() and
                NOTIFICATION_ID_MASK

        return NOTIFICATION_ID_BASE +
            suffix
    }

    private companion object {
        const val CHANNEL_ID =
            "native_background_transfers"

        const val CHANNEL_NAME =
            "Background transfers"

        const val NOTIFICATION_ID_BASE =
            10_000

        const val NOTIFICATION_ID_MASK =
            0x00FFFFFF
    }
}