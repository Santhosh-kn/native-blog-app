package com.bbs.plugins.native_nfc

import android.content.Context
import android.nfc.NfcAdapter

internal data class NativeNfcAvailability(
    val adapter: NfcAdapter?,
    val status: String
) {
    val supported: Boolean
        get() = adapter != null

    val enabled: Boolean
        get() = status == NativeNfcContract.AVAILABILITY_AVAILABLE

    fun toMap(): Map<String, Any> = mapOf(
        "supported" to supported,
        "enabled" to enabled,
        "availability" to status
    )
}

internal object NativeNfcAvailabilityChecker {

    fun check(context: Context): NativeNfcAvailability {
        val adapter = NfcAdapter.getDefaultAdapter(context)

        if (adapter == null) {
            return NativeNfcAvailability(
                adapter = null,
                status = NativeNfcContract.AVAILABILITY_UNAVAILABLE
            )
        }

        return NativeNfcAvailability(
            adapter = adapter,
            status = if (adapter.isEnabled) {
                NativeNfcContract.AVAILABILITY_AVAILABLE
            } else {
                NativeNfcContract.AVAILABILITY_DISABLED
            }
        )
    }
}
