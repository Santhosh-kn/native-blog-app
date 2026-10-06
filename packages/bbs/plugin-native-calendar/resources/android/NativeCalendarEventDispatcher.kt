package com.bbs.plugins.native_calendar

import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.utils.WebViewProvider
import java.lang.ref.WeakReference
import org.json.JSONObject
import com.bbs.plugins.native_calendar.NativeCalendarContract as Contract

/**
 * Best-effort completion notifications containing request metadata only.
 *
 * Persist terminal metadata before calling dispatch().
 * GetStatus remains the durable source if notification delivery fails.
 */
internal object NativeCalendarEventDispatcher {

    private const val EVENT_CLASS =
        "Bbs\\NativeCalendar\\Events\\NativeCalendarCompleted"

    /**
     * True means submission was attempted or scheduled.
     * It does not mean a listener acknowledged the notification.
     */
    fun dispatch(
        activity: FragmentActivity,
        result: NativeCalendarResult
    ): Boolean {
        if (
            !result.accepted ||
            !Contract.isBinding(result.operation, result.target) ||
            result.status !in setOf(
                Contract.STATUS_LAUNCHED,
                Contract.STATUS_FAILED,
                Contract.STATUS_UNKNOWN
            )
        ) {
            return false
        }

        val payloadJson = try {
            // This serializer exposes only the ten metadata fields.
            result.toEventJson().toString()
        } catch (_: RuntimeException) {
            return false
        }

        if (
            payloadJson.toByteArray(Charsets.UTF_8).size >
                Contract.MAX_RESULT_BYTES
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
                EVENT_CLASS,
                payloadJson
            )
            submitted = true
        } catch (_: RuntimeException) {
            // Persisted status remains available.
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
            // Persisted status remains available.
        }

        return submitted
    }

    private fun javascript(payloadJson: String): String {
        val eventNameJson = JSONObject.quote(EVENT_CLASS)

        // Transports may notify consumers more than once.
        // Consumers must correlate completion notifications by request ID.
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
