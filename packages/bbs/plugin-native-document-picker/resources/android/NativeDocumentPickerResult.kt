package com.bbs.plugins.native_document_picker

import org.json.JSONObject

internal data class NativeDocumentPickerResult(
    val id: String,
    val status: String,
    val success: Boolean,
    val cancelled: Boolean,
    val path: String? = null,
    val originalName: String? = null,
    val mimeType: String? = null,
    val size: Long? = null,
    val errorCode: String? = null,
    val completedAt: Long? = null
) {
    val errorMessage: String?
        get() = errorCode?.let(NativeDocumentPickerContract::errorMessage)

    fun toBridgeMap(): Map<String, Any> {
        val documentSize = size

        return buildMap {
            put("id", id)
            put("status", status)
            put("success", success)
            put("cancelled", cancelled)

            path?.let { put("path", it) }
            originalName?.let { put("originalName", it) }
            mimeType?.let { put("mimeType", it) }
            documentSize?.let { put("size", it) }
            errorCode?.let { put("errorCode", it) }
            errorMessage?.let { put("errorMessage", it) }
        }
    }

    fun toEventJson(): JSONObject {
        return JSONObject().apply {
            put("id", id)
            put("success", success)
            put("cancelled", cancelled)
            put("path", path ?: JSONObject.NULL)
            put("originalName", originalName ?: JSONObject.NULL)
            put("mimeType", mimeType ?: JSONObject.NULL)
            put("size", size ?: JSONObject.NULL)
            put("errorCode", errorCode ?: JSONObject.NULL)
            put("errorMessage", errorMessage ?: JSONObject.NULL)
        }
    }

    fun toStoredJson(): JSONObject {
        return toEventJson().apply {
            put("status", status)
            put("completedAt", completedAt ?: JSONObject.NULL)
        }
    }

    companion object {
        fun pending(request: NativeDocumentPickerRequest): NativeDocumentPickerResult {
            return NativeDocumentPickerResult(
                id = request.id,
                status = NativeDocumentPickerContract.STATUS_PENDING,
                success = false,
                cancelled = false
            )
        }

        fun succeeded(
            id: String,
            path: String,
            originalName: String,
            mimeType: String,
            size: Long
        ): NativeDocumentPickerResult {
            return NativeDocumentPickerResult(
                id = id,
                status = NativeDocumentPickerContract.STATUS_SUCCEEDED,
                success = true,
                cancelled = false,
                path = path,
                originalName = originalName,
                mimeType = mimeType,
                size = size,
                completedAt = System.currentTimeMillis()
            )
        }

        fun cancelled(id: String): NativeDocumentPickerResult {
            return NativeDocumentPickerResult(
                id = id,
                status = NativeDocumentPickerContract.STATUS_CANCELLED,
                success = false,
                cancelled = true,
                completedAt = System.currentTimeMillis()
            )
        }

        fun failed(
            id: String,
            errorCode: String
        ): NativeDocumentPickerResult {
            return NativeDocumentPickerResult(
                id = id,
                status = NativeDocumentPickerContract.STATUS_FAILED,
                success = false,
                cancelled = false,
                errorCode = errorCode,
                completedAt = System.currentTimeMillis()
            )
        }

        fun notFound(id: String): NativeDocumentPickerResult {
            return NativeDocumentPickerResult(
                id = id,
                status = NativeDocumentPickerContract.STATUS_NOT_FOUND,
                success = false,
                cancelled = false,
                errorCode = NativeDocumentPickerContract.RESULT_NOT_FOUND,
                completedAt = System.currentTimeMillis()
            )
        }

        fun fromStoredJson(json: JSONObject): NativeDocumentPickerResult? {
            val id = NativeDocumentPickerContract.normalizeRequestId(
                json.opt("id")
            ) ?: return null

            return when (json.optString("status")) {
                NativeDocumentPickerContract.STATUS_PENDING ->
                    NativeDocumentPickerResult(
                        id = id,
                        status = NativeDocumentPickerContract.STATUS_PENDING,
                        success = false,
                        cancelled = false
                    )

                NativeDocumentPickerContract.STATUS_SUCCEEDED ->
                    restoreSuccess(json, id)

                NativeDocumentPickerContract.STATUS_CANCELLED ->
                    NativeDocumentPickerResult(
                        id = id,
                        status = NativeDocumentPickerContract.STATUS_CANCELLED,
                        success = false,
                        cancelled = true,
                        completedAt = longOrNull(json, "completedAt")
                            ?: return null
                    )

                NativeDocumentPickerContract.STATUS_FAILED ->
                    restoreFailure(json, id)

                else -> null
            }
        }

        private fun restoreSuccess(
            json: JSONObject,
            id: String
        ): NativeDocumentPickerResult? {
            val path = NativeDocumentPickerContract.normalizePath(
                json.opt("path")
            ) ?: return null

            val originalName = storedOriginalName(json)
                ?: return null

            val mimeType = NativeDocumentPickerContract.normalizeConcreteMimeType(
                json.opt("mimeType")
            ) ?: return null

            val size = longOrNull(
                json = json,
                key = "size",
                allowZero = true
            ) ?: return null

            return NativeDocumentPickerResult(
                id = id,
                status = NativeDocumentPickerContract.STATUS_SUCCEEDED,
                success = true,
                cancelled = false,
                path = path,
                originalName = originalName,
                mimeType = mimeType,
                size = size,
                completedAt = longOrNull(json, "completedAt")
                    ?: return null
            )
        }

        private fun restoreFailure(
            json: JSONObject,
            id: String
        ): NativeDocumentPickerResult? {
            val errorCode = nullableString(json, "errorCode")
                ?: return null

            if (!NativeDocumentPickerContract.isKnownErrorCode(errorCode)) {
                return null
            }

            return NativeDocumentPickerResult(
                id = id,
                status = NativeDocumentPickerContract.STATUS_FAILED,
                success = false,
                cancelled = false,
                errorCode = errorCode,
                completedAt = longOrNull(json, "completedAt")
                    ?: return null
            )
        }

        private fun nullableString(
            json: JSONObject,
            key: String
        ): String? {
            if (!json.has(key) || json.isNull(key)) {
                return null
            }

            return (json.opt(key) as? String)?.takeIf(String::isNotEmpty)
        }

        private fun storedOriginalName(json: JSONObject): String? {
            val value = nullableString(json, "originalName")
                ?: return null

            if (
                value == "." ||
                value == ".." ||
                value.trim(' ', '.') != value ||
                value.codePointCount(0, value.length) >
                    NativeDocumentPickerContract.MAX_FILE_NAME_CODE_POINTS ||
                !hasOnlySafeFileNameCharacters(value)
            ) {
                return null
            }

            return value
        }

        private fun hasOnlySafeFileNameCharacters(value: String): Boolean {
            var index = 0

            while (index < value.length) {
                val character = value[index]

                if (
                    character == '/' ||
                    character == '\\' ||
                    character.code < 0x20 ||
                    character.code == 0x7F
                ) {
                    return false
                }

                if (Character.isHighSurrogate(character)) {
                    if (
                        index + 1 >= value.length ||
                        !Character.isLowSurrogate(value[index + 1])
                    ) {
                        return false
                    }

                    index += 2
                    continue
                }

                if (Character.isLowSurrogate(character)) {
                    return false
                }

                index++
            }

            return true
        }

        private fun longOrNull(
            json: JSONObject,
            key: String,
            allowZero: Boolean = false
        ): Long? {
            if (!json.has(key) || json.isNull(key)) {
                return null
            }

            val number = json.opt(key) as? Number ?: return null
            val value = number.toLong()

            if (
                number is Float && number.toDouble() != value.toDouble() ||
                number is Double && number != value.toDouble()
            ) {
                return null
            }

            if (value < 0 || !allowZero && value == 0L) {
                return null
            }

            return value
        }
    }
}
