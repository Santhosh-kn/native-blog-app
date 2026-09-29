package com.bbs.plugins.native_passkeys

import android.os.CancellationSignal

internal object NativePasskeysOperationRegistry {

    private val lock = Any()

    private val activeSignals =
        mutableMapOf<String, CancellationSignal>()

    fun register(
        id: String,
        signal: CancellationSignal
    ): Boolean = synchronized(lock) {
        if (activeSignals.containsKey(id)) {
            return@synchronized false
        }

        activeSignals[id] = signal
        true
    }

    fun isActive(
        id: String
    ): Boolean = synchronized(lock) {
        activeSignals.containsKey(id)
    }

    fun finish(
        id: String,
        expectedSignal: CancellationSignal
    ): Boolean = synchronized(lock) {
        if (activeSignals[id] !== expectedSignal) {
            return@synchronized false
        }

        activeSignals.remove(id)
        true
    }

    fun cancel(
        id: String
    ): Boolean {
        val signal = synchronized(lock) {
            activeSignals.remove(id)
        } ?: return false

        return try {
            signal.cancel()
            true
        } catch (_: RuntimeException) {
            false
        }
    }
}
