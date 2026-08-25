package com.bbs.plugins.firebase_push_notifications

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.google.firebase.messaging.FirebaseMessaging
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

object FirebasePushNotificationsFunctions {

    private const val TAG = "FirebasePushNotifications"

    private const val COMPLETED_EVENT =
        "Bbs\\FirebasePushNotifications\\Events\\FirebasePushNotificationsCompleted"

    private const val PREFERENCES_NAME =
        "firebase_push_notifications"

    private const val PERMISSION_REQUESTED_KEY =
        "notification_permission_requested"

    private const val PERMISSION_REQUEST_CODE = 9041

    private fun notificationsAreEnabled(context: Context): Boolean {
        val appNotificationsEnabled =
            NotificationManagerCompat.from(context)
                .areNotificationsEnabled()

        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            return appNotificationsEnabled
        }

        val runtimePermissionGranted =
            ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED

        return runtimePermissionGranted && appNotificationsEnabled
    }

    private fun wasPermissionRequested(context: Context): Boolean {
        return context.getSharedPreferences(
            PREFERENCES_NAME,
            Context.MODE_PRIVATE
        ).getBoolean(PERMISSION_REQUESTED_KEY, false)
    }

    private fun markPermissionRequested(context: Context) {
        context.getSharedPreferences(
            PREFERENCES_NAME,
            Context.MODE_PRIVATE
        ).edit()
            .putBoolean(PERMISSION_REQUESTED_KEY, true)
            .apply()
    }

    private fun permissionStatus(context: Context): String {
        if (notificationsAreEnabled(context)) {
            return "granted"
        }

        if (
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            !wasPermissionRequested(context)
        ) {
            return "not_determined"
        }

        return "denied"
    }

    class CheckPermission(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val status = permissionStatus(context)

            return BridgeResponse.success(
                mapOf(
                    "status" to status,
                    "granted" to (status == "granted"),
                    "sdkInt" to Build.VERSION.SDK_INT,
                    "requiresRuntimePermission" to (
                        Build.VERSION.SDK_INT >=
                            Build.VERSION_CODES.TIRAMISU
                    )
                )
            )
        }
    }

    class RequestPermission(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val currentStatus = permissionStatus(activity)

            if (currentStatus == "granted") {
                return BridgeResponse.success(
                    mapOf(
                        "requested" to false,
                        "status" to "granted"
                    )
                )
            }

            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
                return BridgeResponse.success(
                    mapOf(
                        "requested" to false,
                        "status" to currentStatus
                    )
                )
            }

            markPermissionRequested(activity)

            Handler(Looper.getMainLooper()).post {
                ActivityCompat.requestPermissions(
                    activity,
                    arrayOf(Manifest.permission.POST_NOTIFICATIONS),
                    PERMISSION_REQUEST_CODE
                )
            }

            return BridgeResponse.success(
                mapOf(
                    "requested" to true,
                    "status" to "pending"
                )
            )
        }
    }

    class GetToken(
        private val activity: FragmentActivity
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val id = parameters["id"] as? String

            Handler(Looper.getMainLooper()).post {
                requestToken(id)
            }

            val response = mutableMapOf<String, Any>(
                "started" to true
            )

            id?.let {
                response["id"] = it
            }

            return BridgeResponse.success(response)
        }

        private fun requestToken(id: String?) {
            try {
                FirebaseMessaging.getInstance().token
                    .addOnCompleteListener(activity) { task ->
                        if (!task.isSuccessful) {
                            dispatchFailure(
                                message = task.exception?.localizedMessage
                                    ?: "Unable to obtain the FCM token",
                                id = id
                            )
                            return@addOnCompleteListener
                        }

                        val token = task.result

                        if (token.isNullOrBlank()) {
                            dispatchFailure(
                                message = "Firebase returned an empty FCM token",
                                id = id
                            )
                            return@addOnCompleteListener
                        }

                        FirebasePushTokenStore.save(
                            context = activity,
                            token = token
                        )

                        val payload = JSONObject().apply {
                            put("success", true)
                            id?.let { put("id", it) }
                        }

                        dispatchResult(payload)
                    }
            } catch (exception: Exception) {
                Log.e(TAG, "FCM token request failed", exception)

                dispatchFailure(
                    message = exception.localizedMessage
                        ?: "FCM token request failed",
                    id = id
                )
            }
        }

        private fun dispatchFailure(
            message: String,
            id: String?
        ) {
            val payload = JSONObject().apply {
                put("success", false)
                put("error", message)
                id?.let { put("id", it) }
            }

            dispatchResult(payload)
        }

        private fun dispatchResult(payload: JSONObject) {
            Handler(Looper.getMainLooper()).post {
                NativeActionCoordinator.dispatchEvent(
                    activity,
                    COMPLETED_EVENT,
                    payload.toString()
                )
            }
        }
    }

    class GetStoredToken(
        private val context: Context
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>
        ): Map<String, Any> {
            val tokenFile =
                FirebasePushTokenStore.readableFile(context)

            val response = mutableMapOf<String, Any>(
                "available" to (tokenFile !== null)
            )

            tokenFile?.let {
                response["path"] = it.absolutePath
            }

            return BridgeResponse.success(response)
        }
    }
}