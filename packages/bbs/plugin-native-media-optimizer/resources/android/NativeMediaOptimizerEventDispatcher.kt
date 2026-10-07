package com.bbs.plugins.native_media_optimizer

import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.utils.WebViewProvider
import java.lang.ref.WeakReference
import java.util.concurrent.atomic.AtomicReference
import org.json.JSONObject

/** Best effort, metadata-only notifications. Persisted GetStatus is authoritative. */
internal object NativeMediaOptimizerEventDispatcher {
    private const val EVENT = "Bbs\\NativeMediaOptimizer\\Events\\NativeMediaOptimizerCompleted"
    private val host = AtomicReference<WeakReference<FragmentActivity>?>(null)

    fun attach(activity: FragmentActivity) { host.set(WeakReference(activity)) }

    fun dispatch(result: NativeMediaOptimizerResult) {
        if (!result.accepted || !result.isTerminal) return
        val payload = result.toEventJson().toString()
        if (payload.toByteArray(Charsets.UTF_8).size > 16384) return
        val reference = host.get() ?: return
        try {
            Handler(Looper.getMainLooper()).post {
                val activity = reference.get() ?: return@post
                if (activity.isFinishing || activity.isDestroyed) return@post
                try { NativeElementBridge.sendNativeEvent(EVENT, payload) }
                catch (_: RuntimeException) { } catch (_: LinkageError) { }
                try {
                    (activity as? WebViewProvider)?.getWebViewOrNull()?.evaluateJavascript(javascript(payload), null)
                } catch (_: RuntimeException) { } catch (_: LinkageError) { }
            }
        } catch (_: RuntimeException) { }
    }

    private fun javascript(payload: String): String = """
        (function () {
            const eventName = ${JSONObject.quote(EVENT)};
            const payload = $payload;
            try {
                document.dispatchEvent(new CustomEvent("native-event", {
                    detail: {event: eventName, payload: payload}
                }));
            } catch (_) {}
            try {
                if (window.Livewire && typeof window.Livewire.dispatch === "function") {
                    window.Livewire.dispatch("native:" + eventName, payload);
                }
            } catch (_) {}
            try {
                fetch("/_native/api/events", {
                    method: "POST",
                    headers: {"Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest"},
                    body: JSON.stringify({event: eventName, payload: payload})
                }).catch(function () {});
            } catch (_) {}
        })();
    """.trimIndent()
}
