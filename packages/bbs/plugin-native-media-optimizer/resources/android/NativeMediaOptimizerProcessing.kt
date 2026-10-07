package com.bbs.plugins.native_media_optimizer

import android.os.Looper
import java.util.concurrent.atomic.AtomicBoolean
import com.bbs.plugins.native_media_optimizer.NativeMediaOptimizerContract as Contract

internal class NativeMediaOptimizerProcessingException(val code: String) : RuntimeException(Contract.message(code)) {
    init { require(Contract.knownError(code)) }
}

internal class NativeMediaOptimizerCancelled : RuntimeException("Media processing cancelled.")

internal class NativeMediaOptimizerCancellation {
    private val cancelled = AtomicBoolean(false)
    fun cancel() { cancelled.set(true) }
    fun check() { if (cancelled.get() || Thread.currentThread().isInterrupted) throw NativeMediaOptimizerCancelled() }
}

internal data class NativeMediaOptimizerProcessed(
    val input: NativeMediaOptimizerMetadata,
    val output: NativeMediaOptimizerOutput
) {
    override fun toString() = "NativeMediaOptimizerProcessed(redacted)"
}

internal fun requireMediaWorker() {
    check(Looper.myLooper() != Looper.getMainLooper()) { "Media work requires a background thread." }
}

/** Fit inside the requested box, preserving aspect ratio and never enlarging. */
internal object NativeMediaOptimizerGeometry {
    fun fit(width: Int, height: Int, maxWidth: Int, maxHeight: Int): Pair<Int, Int> {
        require(width > 0 && height > 0 && maxWidth > 0 && maxHeight > 0)
        if (width <= maxWidth && height <= maxHeight) return width to height
        return if (maxWidth.toLong() * height <= maxHeight.toLong() * width) {
            maxWidth to maxOf(1, (height.toLong() * maxWidth / width).toInt())
        } else {
            maxOf(1, (width.toLong() * maxHeight / height).toInt()) to maxHeight
        }
    }

    // Account conservatively for decoder intermediates, color conversion and
    // JPEG's optional white background bitmap. API 33 lacks a decoder allocation limit.
    fun checkImageMemory(width: Int, height: Int, outputWidth: Int, outputHeight: Int) {
        val runtime = Runtime.getRuntime()
        val headroom = runtime.maxMemory() - (runtime.totalMemory() - runtime.freeMemory())
        val estimate = width.toLong() * height * 8L + outputWidth.toLong() * outputHeight * 12L + 32L * 1024 * 1024
        if (estimate > minOf(headroom, 256L * 1024 * 1024)) throw NativeMediaOptimizerProcessingException(Contract.LIMIT_EXCEEDED)
    }
}
