package com.bbs.plugins.native_background_transfer

import org.json.JSONObject

internal data class NativeBackgroundTransferResult(
    val id: String,
    val status: String,
    val transferredBytes: Long = 0L,
    val totalBytes: Long? = null,
    val progress: Int? = null,
    val fileId: String? = null,
    val displayName: String? = null,
    val mimeType: String? = null,
    val size: Long? = null,
    val errorCode: String? = null,
    val consumed: Boolean = false,
    val updatedAt: Long = System.currentTimeMillis()
) {

    fun toBridgeMap(): Map<String, Any> {
        return buildMap {
            put("id", id)
            put(
                "type",
                NativeBackgroundTransferContract.TYPE_DOWNLOAD
            )
            put("status", status)
            put("transferredBytes", transferredBytes)
            put("consumed", consumed)

            totalBytes?.let {
                put("totalBytes", it)
            }

            progress?.let {
                put("progress", it)
            }

            fileId?.let {
                put("fileId", it)
            }

            displayName?.let {
                put("displayName", it)
            }

            mimeType?.let {
                put("mimeType", it)
            }

            this@NativeBackgroundTransferResult.size?.let {
                put("size", it)
            }

            errorCode?.let {
                put("errorCode", it)
                put(
                    "errorMessage",
                    NativeBackgroundTransferContract
                        .errorMessage(it)
                )
            }
        }
    }

    fun toStoredJson(): JSONObject {
        return JSONObject().apply {
            put("id", id)
            put(
                "type",
                NativeBackgroundTransferContract.TYPE_DOWNLOAD
            )
            put("status", status)
            put("transferredBytes", transferredBytes)
            put(
                "totalBytes",
                totalBytes ?: JSONObject.NULL
            )
            put(
                "progress",
                progress ?: JSONObject.NULL
            )
            put(
                "fileId",
                fileId ?: JSONObject.NULL
            )
            put(
                "displayName",
                displayName ?: JSONObject.NULL
            )
            put(
                "mimeType",
                mimeType ?: JSONObject.NULL
            )
            put(
                "size",
                size ?: JSONObject.NULL
            )
            put(
                "errorCode",
                errorCode ?: JSONObject.NULL
            )
            put("consumed", consumed)
            put("updatedAt", updatedAt)
        }
    }

    companion object {

        fun queued(
            request: NativeBackgroundTransferRequest
        ): NativeBackgroundTransferResult {
            return NativeBackgroundTransferResult(
                id = request.id,
                status =
                    NativeBackgroundTransferContract
                        .STATUS_QUEUED
            )
        }

        fun failed(
            id: String,
            errorCode: String,
            transferredBytes: Long = 0L,
            totalBytes: Long? = null,
            progress: Int? = null,
            consumed: Boolean = false
        ): NativeBackgroundTransferResult {
            return NativeBackgroundTransferResult(
                id = id,
                status =
                    NativeBackgroundTransferContract
                        .STATUS_FAILED,
                transferredBytes = transferredBytes,
                totalBytes = totalBytes,
                progress = progress,
                errorCode = errorCode,
                consumed = consumed
            )
        }

        fun fromStoredJson(
            json: JSONObject
        ): NativeBackgroundTransferResult? {
            val id =
                NativeBackgroundTransferContract
                    .normalizeRequestId(
                        json.opt("id")
                    )
                    ?: return null

            if (
                json.optString("type") !=
                NativeBackgroundTransferContract.TYPE_DOWNLOAD
            ) {
                return null
            }

            val status =
                (json.opt("status") as? String)
                    ?: return null

            if (
                !NativeBackgroundTransferContract
                    .isKnownStatus(status)
            ) {
                return null
            }

            val transferredBytes =
                NativeBackgroundTransferContract
                    .normalizeByteCount(
                        json.opt("transferredBytes")
                    )
                    ?: return null

            val totalBytes = nullableByteCount(
                json = json,
                key = "totalBytes"
            ) ?: if (
                json.has("totalBytes") &&
                !json.isNull("totalBytes")
            ) {
                return null
            } else {
                null
            }

            if (
                totalBytes != null &&
                transferredBytes > totalBytes
            ) {
                return null
            }

            val progress = nullableProgress(
                json = json,
                key = "progress"
            ) ?: if (
                json.has("progress") &&
                !json.isNull("progress")
            ) {
                return null
            } else {
                null
            }

            if (
                totalBytes != null &&
                totalBytes > 0L
            ) {
                val expectedProgress =
                    ((transferredBytes * 100L) /
                        totalBytes)
                        .toInt()

                if (progress != expectedProgress) {
                    return null
                }
            }

            val fileId = nullableString(
                json = json,
                key = "fileId"
            )?.let {
                NativeBackgroundTransferContract
                    .normalizeRequestId(it)
            }

            if (
                json.has("fileId") &&
                !json.isNull("fileId") &&
                fileId == null
            ) {
                return null
            }

            val displayName = nullableString(
                json = json,
                key = "displayName"
            )

            if (
                displayName != null &&
                NativeBackgroundTransferContract
                    .safeDisplayName(displayName) !=
                displayName
            ) {
                return null
            }

            val mimeType = nullableString(
                json = json,
                key = "mimeType"
            )?.let {
                NativeBackgroundTransferContract
                    .normalizeConcreteMimeType(it)
            }

            if (
                json.has("mimeType") &&
                !json.isNull("mimeType") &&
                mimeType == null
            ) {
                return null
            }

            val size = nullableByteCount(
                json = json,
                key = "size"
            ) ?: if (
                json.has("size") &&
                !json.isNull("size")
            ) {
                return null
            } else {
                null
            }

            val errorCode = nullableString(
                json = json,
                key = "errorCode"
            )

            val consumed =
                json.opt("consumed") as? Boolean
                    ?: return null

            val updatedAt = positiveLong(
                json = json,
                key = "updatedAt"
            ) ?: return null

            if (
                !NativeBackgroundTransferContract
                    .isTerminalStatus(status) &&
                consumed
            ) {
                return null
            }

            when (status) {
                NativeBackgroundTransferContract
                    .STATUS_SUCCEEDED -> {
                    if (
                        fileId == null ||
                        displayName == null ||
                        mimeType == null ||
                        size == null ||
                        size != transferredBytes ||
                        progress != 100 ||
                        errorCode != null
                    ) {
                        return null
                    }
                }

                NativeBackgroundTransferContract
                    .STATUS_FAILED -> {
                    if (
                        errorCode == null ||
                        !NativeBackgroundTransferContract
                            .isKnownErrorCode(errorCode)
                    ) {
                        return null
                    }

                    if (
                        fileId != null ||
                        displayName != null ||
                        mimeType != null ||
                        size != null
                    ) {
                        return null
                    }
                }

                NativeBackgroundTransferContract
                    .STATUS_CANCELLED,
                NativeBackgroundTransferContract
                    .STATUS_QUEUED,
                NativeBackgroundTransferContract
                    .STATUS_RUNNING -> {
                    if (
                        errorCode != null ||
                        fileId != null ||
                        displayName != null ||
                        mimeType != null ||
                        size != null
                    ) {
                        return null
                    }
                }
            }

            return NativeBackgroundTransferResult(
                id = id,
                status = status,
                transferredBytes = transferredBytes,
                totalBytes = totalBytes,
                progress = progress,
                fileId = fileId,
                displayName = displayName,
                mimeType = mimeType,
                size = size,
                errorCode = errorCode,
                consumed = consumed,
                updatedAt = updatedAt
            )
        }

        private fun nullableString(
            json: JSONObject,
            key: String
        ): String? {
            if (
                !json.has(key) ||
                json.isNull(key)
            ) {
                return null
            }

            return (json.opt(key) as? String)
                ?.takeIf {
                    it.isNotEmpty()
                }
        }

        private fun nullableByteCount(
            json: JSONObject,
            key: String
        ): Long? {
            if (
                !json.has(key) ||
                json.isNull(key)
            ) {
                return null
            }

            return NativeBackgroundTransferContract
                .normalizeByteCount(
                    json.opt(key)
                )
        }

        private fun nullableProgress(
            json: JSONObject,
            key: String
        ): Int? {
            if (
                !json.has(key) ||
                json.isNull(key)
            ) {
                return null
            }

            return NativeBackgroundTransferContract
                .normalizeProgress(
                    json.opt(key)
                )
        }

        private fun positiveLong(
            json: JSONObject,
            key: String
        ): Long? {
            val number = json.opt(key) as? Number
                ?: return null

            val value = number.toLong()

            if (
                number is Float &&
                number.toDouble() != value.toDouble()
            ) {
                return null
            }

            if (
                number is Double &&
                number != value.toDouble()
            ) {
                return null
            }

            return value.takeIf {
                it > 0L
            }
        }
    }
}