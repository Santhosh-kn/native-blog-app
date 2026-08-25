package com.bbs.plugins.firebase_push_notifications

import android.content.Context
import android.util.Log
import com.google.firebase.messaging.FirebaseMessaging

fun initializeFirebasePushNotifications(
    context: Context
) {
    Log.d(
        INITIALIZER_TAG,
        "Initializing Firebase push notifications for ${context.packageName}"
    )

    FirebaseMessaging.getInstance()
        .setNotificationDelegationEnabled(false)
        .addOnCompleteListener { task ->
            if (task.isSuccessful) {
                Log.d(
                    INITIALIZER_TAG,
                    "Firebase notification delegation disabled"
                )
            } else {
                Log.e(
                    INITIALIZER_TAG,
                    "Unable to disable Firebase notification delegation",
                    task.exception
                )
            }
        }
}

private const val INITIALIZER_TAG =
    "FirebasePushInitializer"