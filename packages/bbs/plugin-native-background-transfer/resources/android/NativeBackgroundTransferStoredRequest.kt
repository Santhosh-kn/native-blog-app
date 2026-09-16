package com.bbs.plugins.native_background_transfer

import org.json.JSONObject

internal sealed interface NativeBackgroundTransferStoredRequest {

    val id: String

    val type: String

    val createdAt: Long

    fun toStoredJson(): JSONObject

    companion object {

        fun fromStoredJson(
            json: JSONObject
        ): NativeBackgroundTransferStoredRequest? {
            return when (json.optString("type")) {
                NativeBackgroundTransferContract.TYPE_DOWNLOAD ->
                    NativeBackgroundTransferRequest.fromStoredJson(json)

                NativeBackgroundTransferContract.TYPE_UPLOAD ->
                    NativeBackgroundUploadRequest.fromStoredJson(json)

                else -> null
            }
        }
    }
}