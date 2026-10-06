package com.bbs.plugins.native_contacts

import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.utils.WebViewProvider
import java.lang.ref.WeakReference
import org.json.JSONObject

internal object NativeContactsEventDispatcher {

    /**
     * The caller must persist the terminal result before dispatching.
     * A true return means submission was attempted or scheduled,
     * not that a listener acknowledged receipt.
     */
    fun dispatch(
        activity: FragmentActivity,
        result: NativeContactsResult
    ): Boolean {
        if (!isCompletionMetadata(result)) {
            return false
        }

        val payloadJson = try {
            // Default serialization never includes private selection data.
            result.toEventJson().toString()
        } catch (_: RuntimeException) {
            return false
        }

        if (
            payloadJson.toByteArray(Charsets.UTF_8).size >
                NativeContactsContract.MAX_RESULT_BYTES
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

    private fun isCompletionMetadata(
        result: NativeContactsResult
    ): Boolean {
        val created = result.createdAtMs ?: return false
        val completed = result.completedAtMs ?: return false

        if (
            NativeContactsContract.requestId(result.id) != result.id ||
            result.operation !in NativeContactsContract.operations ||
            result.status !in NativeContactsContract.terminalStatuses ||
            created <= 0L ||
            completed < created ||
            (
                result.operation == NativeContactsContract.PICK &&
                    result.mode !in NativeContactsContract.modes
            ) ||
            (
                result.operation != NativeContactsContract.PICK &&
                    result.mode != null
            ) ||
            (
                result.status in setOf(
                    NativeContactsContract.STATUS_SELECTED,
                    NativeContactsContract.STATUS_CANCELLED
                ) &&
                    result.operation != NativeContactsContract.PICK
            ) ||
            (
                result.status == NativeContactsContract.STATUS_LAUNCHED &&
                    result.operation == NativeContactsContract.PICK
            ) ||
            (
                result.consumed &&
                    result.status != NativeContactsContract.STATUS_SELECTED
            )
        ) {
            return false
        }

        return when (result.status) {
            NativeContactsContract.STATUS_SELECTED,
            NativeContactsContract.STATUS_LAUNCHED,
            NativeContactsContract.STATUS_CANCELLED ->
                result.errorCode == null

            NativeContactsContract.STATUS_UNKNOWN ->
                result.errorCode ==
                    NativeContactsContract.OPERATION_INTERRUPTED

            NativeContactsContract.STATUS_FAILED ->
                result.errorCode?.let {
                    NativeContactsContract.isKnownError(it)
                } == true

            else -> false
        }
    }

    private fun deliver(
        activity: FragmentActivity,
        payloadJson: String
    ): Boolean {
        if (activity.isFinishing || activity.isDestroyed) {
            return false
        }

        var submitted = false

        try {
            NativeElementBridge.sendNativeEvent(
                NativeContactsContract.EVENT_CLASS,
                payloadJson
            )
            submitted = true
        } catch (_: RuntimeException) {
            // Persisted metadata remains available through GetStatus.
        } catch (_: LinkageError) {
            // The native event transport may be unavailable.
        }

        try {
            val webView =
                (activity as? WebViewProvider)?.getWebViewOrNull()

            if (webView != null) {
                webView.evaluateJavascript(
                    javascript(payloadJson),
                    null
                )
                submitted = true
            }
        } catch (_: RuntimeException) {
            // Persisted metadata remains available through GetStatus.
        }

        return submitted
    }

    private fun javascript(payloadJson: String): String {
        val eventNameJson =
            JSONObject.quote(NativeContactsContract.EVENT_CLASS)

        // Native and WebView transports may both notify consumers.
        // Consumers must correlate notifications by request ID.
        return """
            (function () {
                const eventName = $eventNameJson;
                const payload = $payloadJson;

                try {
                    document.dispatchEvent(
                        new CustomEvent("native-event", {
                            detail: {
                                event: eventName,
                                payload: payload
                            }
                        })
                    );
                } catch (_) {}

                try {
                    if (
                        window.Livewire &&
                        typeof window.Livewire.dispatch === "function"
                    ) {
                        window.Livewire.dispatch(
                            "native:" + eventName,
                            payload
                        );
                    }
                } catch (_) {}

                try {
                    fetch("/_native/api/events", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: JSON.stringify({
                            event: eventName,
                            payload: payload
                        })
                    }).catch(function () {});
                } catch (_) {}
            })();
        """.trimIndent()
    }
}