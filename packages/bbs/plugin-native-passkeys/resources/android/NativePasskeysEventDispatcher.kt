package com.bbs.plugins.native_passkeys

import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.utils.WebViewProvider
import org.json.JSONObject

internal object NativePasskeysEventDispatcher {

    private const val MAX_EVENT_CHARACTERS = 16_384

    fun dispatch(
        activity: FragmentActivity,
        result: NativePasskeysResult
    ): Boolean {
        if (
            !NativePasskeysContract
                .isTerminalStatus(result.status)
        ) {
            return false
        }

        val payloadJson = try {
            result.toEventJson().toString()
        } catch (_: RuntimeException) {
            return false
        }

        if (
            payloadJson.length >
            MAX_EVENT_CHARACTERS
        ) {
            return false
        }

        if (
            Looper.myLooper() ==
            Looper.getMainLooper()
        ) {
            return deliver(
                activity = activity,
                payloadJson = payloadJson
            )
        }

        return Handler(
            Looper.getMainLooper()
        ).post {
            deliver(
                activity = activity,
                payloadJson = payloadJson
            )
        }
    }

    private fun deliver(
        activity: FragmentActivity,
        payloadJson: String
    ): Boolean {
        var delivered = false

        try {
            NativeElementBridge.sendNativeEvent(
                NativePasskeysContract.EVENT_CLASS,
                payloadJson
            )
            delivered = true
        } catch (_: Exception) {
            // The result remains recoverable through GetStatus.
        }

        val webView =
            (activity as? WebViewProvider)
                ?.getWebViewOrNull()

        if (webView != null) {
            try {
                webView.evaluateJavascript(
                    javascript(payloadJson),
                    null
                )
                delivered = true
            } catch (_: RuntimeException) {
                // The result remains recoverable through GetStatus.
            }
        }

        return delivered
    }

    private fun javascript(
        payloadJson: String
    ): String {
        val eventNameJson =
            JSONObject.quote(
                NativePasskeysContract.EVENT_CLASS
            )

        return """
            (function () {
                const eventName = $eventNameJson;
                const payload = $payloadJson;

                try {
                    document.dispatchEvent(
                        new CustomEvent(
                            "native-event",
                            {
                                detail: {
                                    event: eventName,
                                    payload: payload
                                }
                            }
                        )
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
