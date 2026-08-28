package com.bbs.plugins.native_printing

import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.ui.MainActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import java.lang.ref.WeakReference

internal object NativePrintingEvents {

    private val mainHandler = Handler(
        Looper.getMainLooper()
    )

    private var hostReference:
        WeakReference<FragmentActivity>? = null

    @Synchronized
    fun rememberHost(activity: FragmentActivity) {
        if (isUsable(activity)) {
            hostReference = WeakReference(activity)
        }
    }

    fun dispatchState(
        sourceActivity: FragmentActivity?,
        requestId: String,
        action: String,
        status: String,
        jobId: String? = null,
        errorCode: String? = null,
        errorMessage: String? = null
    ) {
        val payloadJson = JSONObject().apply {
            put("request_id", requestId)
            put("action", action)
            put("status", status)

            put(
                "job_id",
                jobId ?: JSONObject.NULL
            )

            put(
                "error_code",
                errorCode ?: JSONObject.NULL
            )

            put(
                "error_message",
                errorMessage ?: JSONObject.NULL
            )
        }.toString()

        mainHandler.post {
            val target = findEventTarget(sourceActivity)
                ?: return@post

            runCatching {
                NativeActionCoordinator.dispatchEvent(
                    target,
                    NativePrintingContract.EVENT_CLASS,
                    payloadJson
                )
            }
        }
    }

    private fun findEventTarget(
        sourceActivity: FragmentActivity?
    ): FragmentActivity? {
        val mainActivity = MainActivity.instance

        if (
            mainActivity != null &&
            isUsable(mainActivity)
        ) {
            return mainActivity
        }

        val remembered = synchronized(this) {
            hostReference?.get()
        }

        if (
            remembered != null &&
            isUsable(remembered)
        ) {
            return remembered
        }

        if (
            sourceActivity != null &&
            isUsable(sourceActivity)
        ) {
            return sourceActivity
        }

        return null
    }

    private fun isUsable(
        activity: FragmentActivity
    ): Boolean {
        return !activity.isFinishing &&
            !activity.isDestroyed
    }
}
