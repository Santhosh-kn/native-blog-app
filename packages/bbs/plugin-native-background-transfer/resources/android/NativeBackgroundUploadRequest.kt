package com.bbs.plugins.native_background_transfer

import org.json.JSONObject
import java.util.UUID

internal sealed interface NativeBackgroundUploadRequestValidation {

    data class Valid(
        val request: NativeBackgroundUploadRequest
    ) : NativeBackgroundUploadRequestValidation

    data class Invalid(
        val id: String,
        val errorCode: String
    ) : NativeBackgroundUploadRequestValidation
}

internal data class NativeBackgroundUploadRequest(
    override val id: String,
    val sourceDocumentId: String,
    val url: String,
    val method: String,
    val maxSize: Long,
    override val createdAt: Long
) : NativeBackgroundTransferStoredRequest {

    override val type: String
        get() = NativeBackgroundTransferContract.TYPE_UPLOAD

    override fun toStoredJson(): JSONObject {
        return JSONObject().apply {
            put("id", id)
            put("type", type)
            put("sourceDocumentId", sourceDocumentId)
            put("url", url)
            put("method", method)
            put("maxSize", maxSize)
            put("createdAt", createdAt)
        }
    }

    companion object {

        fun fromParameters(
            parameters: Map<String, Any>
        ): NativeBackgroundUploadRequestValidation {
            val id =
                NativeBackgroundTransferContract
                    .normalizeRequestId(parameters["id"])

            if (id == null) {
                return NativeBackgroundUploadRequestValidation.Invalid(
                    id = UUID.randomUUID().toString(),
                    errorCode =
                        NativeBackgroundTransferContract.INVALID_REQUEST_ID
                )
            }

            val sourceDocumentId =
                NativeBackgroundTransferContract
                    .normalizeRequestId(
                        parameters["source_document_id"]
                    )

            if (sourceDocumentId == null) {
                return NativeBackgroundUploadRequestValidation.Invalid(
                    id = id,
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_SOURCE_DOCUMENT_ID
                )
            }

            val url = when (
                val validation =
                    NativeBackgroundTransferContract
                        .validateHttpsUrl(parameters["url"])
            ) {
                is NativeBackgroundTransferUrlValidation.Valid ->
                    validation.url

                is NativeBackgroundTransferUrlValidation.Invalid ->
                    return NativeBackgroundUploadRequestValidation.Invalid(
                        id = id,
                        errorCode = validation.errorCode
                    )
            }

            val method =
                NativeBackgroundTransferContract
                    .normalizeUploadMethod(parameters["method"])

            if (method == null) {
                return NativeBackgroundUploadRequestValidation.Invalid(
                    id = id,
                    errorCode =
                        NativeBackgroundTransferContract.INVALID_HTTP_METHOD
                )
            }

            val maxSize =
                NativeBackgroundTransferContract
                    .normalizeMaximumSize(parameters["max_size"])

            if (maxSize == null) {
                return NativeBackgroundUploadRequestValidation.Invalid(
                    id = id,
                    errorCode =
                        NativeBackgroundTransferContract.INVALID_MAX_SIZE
                )
            }

            return NativeBackgroundUploadRequestValidation.Valid(
                NativeBackgroundUploadRequest(
                    id = id,
                    sourceDocumentId = sourceDocumentId,
                    url = url,
                    method = method,
                    maxSize = maxSize,
                    createdAt = System.currentTimeMillis()
                )
            )
        }

        fun fromStoredJson(
            json: JSONObject
        ): NativeBackgroundUploadRequest? {
            if (
                json.optString("type") !=
                NativeBackgroundTransferContract.TYPE_UPLOAD
            ) {
                return null
            }

            val parameters = buildMap<String, Any> {
                copyValue(json, "id", "id", this)
                copyValue(
                    json,
                    "sourceDocumentId",
                    "source_document_id",
                    this
                )
                copyValue(json, "url", "url", this)
                copyValue(json, "method", "method", this)
                copyValue(json, "maxSize", "max_size", this)
            }

            val validation = fromParameters(parameters)

            if (
                validation !is
                NativeBackgroundUploadRequestValidation.Valid
            ) {
                return null
            }

            val createdAt =
                positiveLong(json.opt("createdAt"))
                    ?: return null

            return validation.request.copy(
                createdAt = createdAt
            )
        }

        private fun copyValue(
            json: JSONObject,
            sourceKey: String,
            destinationKey: String,
            destination: MutableMap<String, Any>
        ) {
            if (
                !json.has(sourceKey) ||
                json.isNull(sourceKey)
            ) {
                return
            }

            json.opt(sourceKey)?.let {
                destination[destinationKey] = it
            }
        }

        private fun positiveLong(
            value: Any?
        ): Long? {
            val number = value as? Number
                ?: return null

            val longValue = number.toLong()

            if (
                number is Float &&
                number.toDouble() != longValue.toDouble()
            ) {
                return null
            }

            if (
                number is Double &&
                number != longValue.toDouble()
            ) {
                return null
            }

            return longValue.takeIf {
                it > 0L
            }
        }
    }
}