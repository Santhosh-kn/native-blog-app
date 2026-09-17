package com.bbs.plugins.native_background_transfer

import android.os.PersistableBundle

/*
 * Stable metadata contract shared by the Android 14+ UIDT
 * scheduler and JobService.
 *
 * No scheduling or transfer execution belongs here.
 */
internal object NativeBackgroundTransferUidtContract {

    const val EXTRA_TRANSFER_ID =
        "native_background_transfer_id"

    const val EXTRA_TRANSFER_TYPE =
        "native_background_transfer_type"

    fun extras(
        transferId: String,
        type: String
    ): PersistableBundle? {
        val normalizedId =
            NativeBackgroundTransferContract
                .normalizeRequestId(
                    transferId
                )
                ?: return null

        if (normalizedId != transferId) {
            return null
        }

        if (!isKnownTransferType(type)) {
            return null
        }

        return PersistableBundle().apply {
            putString(
                EXTRA_TRANSFER_ID,
                transferId
            )

            putString(
                EXTRA_TRANSFER_TYPE,
                type
            )
        }
    }

    fun transferId(
        extras: PersistableBundle
    ): String? {
        val raw =
            extras.getString(
                EXTRA_TRANSFER_ID
            )
                ?: return null

        val normalized =
            NativeBackgroundTransferContract
                .normalizeRequestId(raw)
                ?: return null

        return normalized.takeIf {
            it == raw
        }
    }

    fun transferType(
        extras: PersistableBundle
    ): String? {
        val type =
            extras.getString(
                EXTRA_TRANSFER_TYPE
            )
                ?: return null

        return type.takeIf {
            isKnownTransferType(it)
        }
    }

    private fun isKnownTransferType(
        type: String
    ): Boolean {
        return type ==
            NativeBackgroundTransferContract
                .TYPE_DOWNLOAD ||
            type ==
                NativeBackgroundTransferContract
                    .TYPE_UPLOAD
    }
}