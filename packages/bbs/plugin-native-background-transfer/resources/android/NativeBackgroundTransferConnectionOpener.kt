package com.bbs.plugins.native_background_transfer

import java.net.URL
import javax.net.ssl.HttpsURLConnection

/*
 * Opens validated HTTPS transfer connections.
 *
 * WorkManager uses the default network behavior. Android 14+
 * UIDT can later supply the Network assigned by JobScheduler.
 */
internal fun interface NativeBackgroundTransferConnectionOpener {

    fun open(
        url: URL
    ): HttpsURLConnection?

    companion object {
        fun default() =
            NativeBackgroundTransferConnectionOpener { url ->
                url.openConnection() as?
                    HttpsURLConnection
            }
    }
}
