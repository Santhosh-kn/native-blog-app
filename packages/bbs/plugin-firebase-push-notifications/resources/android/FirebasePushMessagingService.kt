package com.bbs.plugins.firebase_push_notifications

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

class FirebasePushMessagingService : FirebaseMessagingService() {

    override fun onMessageReceived(
        remoteMessage: RemoteMessage
    ) {
        super.onMessageReceived(remoteMessage)

        Log.d(TAG, "FCM message received")

        val title =
            remoteMessage.notification?.title
                ?: remoteMessage.data["title"]
                ?: applicationInfo
                    .loadLabel(packageManager)
                    .toString()

        val body =
            remoteMessage.notification?.body
                ?: remoteMessage.data["body"]
                ?: remoteMessage.data["message"]
                ?: ""

        showNotification(
            title = title,
            body = body,
            data = remoteMessage.data,
            messageId = remoteMessage.messageId
        )
    }

    override fun onNewToken(token: String) {
        super.onNewToken(token)

        FirebasePushTokenStore.save(
            context = this,
            token = token
        )

        Log.d(TAG, "FCM registration token refreshed")
    }

    private fun showNotification(
        title: String,
        body: String,
        data: Map<String, String>,
        messageId: String?
    ) {
        if (
            Build.VERSION.SDK_INT >=
                Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(
                this,
                Manifest.permission.POST_NOTIFICATIONS
            ) != PackageManager.PERMISSION_GRANTED
        ) {
            Log.w(
                TAG,
                "Notification permission is not granted"
            )

            return
        }

        createNotificationChannel()

        val notificationId =
            messageId?.hashCode()
                ?: System.currentTimeMillis().toInt()

        val tapId = try {
            FirebasePushNotificationTapStore.create(
                context = this,
                data = data
            )
        } catch (exception: Exception) {
            Log.e(
                TAG,
                "Unable to prepare notification tap",
                exception
            )

            null
        }

        val launchIntent =
            packageManager
                .getLaunchIntentForPackage(packageName)
                ?.apply {
                    addFlags(
                        Intent.FLAG_ACTIVITY_CLEAR_TOP or
                            Intent.FLAG_ACTIVITY_SINGLE_TOP
                    )

                    tapId?.let { id ->
                        this.data = Uri.Builder()
                            .scheme(INTERNAL_SCHEME)
                            .authority(INTERNAL_HOST)
                            .appendPath(INTERNAL_PATH)
                            .appendQueryParameter(
                                TAP_PARAMETER,
                                id
                            )
                            .build()
                    }
                }

        val contentIntent = launchIntent?.let {
            PendingIntent.getActivity(
                this,
                notificationId,
                it,
                PendingIntent.FLAG_UPDATE_CURRENT or
                    PendingIntent.FLAG_IMMUTABLE
            )
        }

        val notification =
            NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(
                    android.R.drawable.ic_dialog_info
                )
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(
                    NotificationCompat.BigTextStyle()
                        .bigText(body)
                )
                .setPriority(
                    NotificationCompat.PRIORITY_HIGH
                )
                .setAutoCancel(true)

        contentIntent?.let {
            notification.setContentIntent(it)
        }

        NotificationManagerCompat
            .from(this)
            .notify(
                notificationId,
                notification.build()
            )

        Log.d(TAG, "Notification displayed")
    }

    private fun createNotificationChannel() {
        if (
            Build.VERSION.SDK_INT <
                Build.VERSION_CODES.O
        ) {
            return
        }

        val channel = NotificationChannel(
            CHANNEL_ID,
            CHANNEL_NAME,
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = CHANNEL_DESCRIPTION
        }

        getSystemService(
            NotificationManager::class.java
        ).createNotificationChannel(channel)
    }

    companion object {
        private const val TAG =
            "FirebasePushMessaging"

        private const val CHANNEL_ID =
            "nativephp_firebase_push"

        private const val CHANNEL_NAME =
            "Push notifications"

        private const val CHANNEL_DESCRIPTION =
            "Notifications received through Firebase Cloud Messaging"

        private const val INTERNAL_SCHEME =
            "nativeblog"

        private const val INTERNAL_HOST =
            "push"

        private const val INTERNAL_PATH =
            "open"

        private const val TAP_PARAMETER =
            "tap"
    }
}