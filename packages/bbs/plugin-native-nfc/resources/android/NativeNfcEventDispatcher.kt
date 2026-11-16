package com.bbs.plugins.native_nfc

import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import java.lang.ref.WeakReference
import org.json.JSONObject

internal object NativeNfcEventDispatcher {

    /**
     * The caller must persist the terminal result before dispatching.
     * A true return means submission was attempted or scheduled,
     * not that a JavaScript listener acknowledged receipt.
     */
    fun dispatch(
        activity: FragmentActivity,
        result: NativeNfcResult
    ): Boolean {
        if (!isCompletionMetadata(result)) return false

        val payloadJson = try {
            JSONObject(result.eventMetadata()).toString()
        } catch (_: RuntimeException) {
            return false
        }

        if (payloadJson.toByteArray(Charsets.UTF_8).size >
            NativeNfcContract.MAX_EVENT_BYTES
        ) {
            return false
        }

        if (Looper.myLooper() == Looper.getMainLooper()) {
            return deliver(activity, payloadJson)
        }

        val activityReference = WeakReference(activity)

        return try {
            Handler(Looper.getMainLooper()).post {
                activityReference.get()?.let {
                    deliver(it, payloadJson)
                }
            }
        } catch (_: RuntimeException) {
            false
        }
    }

    private fun isCompletionMetadata(result: NativeNfcResult): Boolean {
        if (
            !result.isValidStored() ||
            result.status !in NativeNfcContract.TERMINAL_STATUSES ||
            result.completedAtMs == null ||
            result.consumed
        ) {
            return false
        }

        return true
    }

    private fun deliver(
        activity: FragmentActivity,
        payloadJson: String
    ): Boolean {
        if (activity.isFinishing || activity.isDestroyed) return false

        return try {
            NativeElementBridge.sendNativeEvent(
                NativeNfcContract.EVENT_CLASS,
                payloadJson
            )
            true
        } catch (_: RuntimeException) {
            false
        } catch (_: LinkageError) {
            false
        }
    }
}
