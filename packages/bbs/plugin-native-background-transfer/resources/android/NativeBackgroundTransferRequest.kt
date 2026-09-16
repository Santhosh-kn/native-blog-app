package com.bbs.plugins.native_background_transfer

import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

internal sealed interface NativeBackgroundTransferRequestValidation {

    data class Valid(
        val request: NativeBackgroundTransferRequest
    ) : NativeBackgroundTransferRequestValidation

    data class Invalid(
        val id: String,
        val errorCode: String
    ) : NativeBackgroundTransferRequestValidation
}

internal data class NativeBackgroundTransferRequest(
    override val id: String,
    val url: String,
    val mimeTypes: List<String>,
    val maxSize: Long,
    override val createdAt: Long
) : NativeBackgroundTransferStoredRequest {

    override val type: String
        get() = NativeBackgroundTransferContract.TYPE_DOWNLOAD

    override fun toStoredJson(): JSONObject {
        return JSONObject().apply {
            put("id", id)
            put("type", type)
            put("url", url)
            put("mimeTypes", JSONArray(mimeTypes))
            put("maxSize", maxSize)
            put("createdAt", createdAt)
        }
    }

    companion object {

        fun fromParameters(
            parameters: Map<String, Any>
        ): NativeBackgroundTransferRequestValidation {
            val id =
                NativeBackgroundTransferContract
                    .normalizeRequestId(
                        parameters["id"]
                    )

            if (id == null) {
                return NativeBackgroundTransferRequestValidation.Invalid(
                    id = UUID.randomUUID().toString(),
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_REQUEST_ID
                )
            }

            val url = when (
                val validation =
                    NativeBackgroundTransferContract
                        .validateHttpsUrl(
                            parameters["url"]
                        )
            ) {
                is NativeBackgroundTransferUrlValidation.Valid ->
                    validation.url

                is NativeBackgroundTransferUrlValidation.Invalid ->
                    return NativeBackgroundTransferRequestValidation.Invalid(
                        id = id,
                        errorCode = validation.errorCode
                    )
            }

            val mimeTypes =
                NativeBackgroundTransferContract
                    .normalizeMimeTypes(
                        parameters["mime_types"]
                    )

            if (mimeTypes == null) {
                return NativeBackgroundTransferRequestValidation.Invalid(
                    id = id,
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_MIME_TYPE
                )
            }

            val maxSize =
                NativeBackgroundTransferContract
                    .normalizeMaximumSize(
                        parameters["max_size"]
                    )

            if (maxSize == null) {
                return NativeBackgroundTransferRequestValidation.Invalid(
                    id = id,
                    errorCode =
                        NativeBackgroundTransferContract
                            .INVALID_MAX_SIZE
                )
            }

            return NativeBackgroundTransferRequestValidation.Valid(
                NativeBackgroundTransferRequest(
                    id = id,
                    url = url,
                    mimeTypes = mimeTypes,
                    maxSize = maxSize,
                    createdAt = System.currentTimeMillis()
                )
            )
        }

        fun fromStoredJson(
            json: JSONObject
        ): NativeBackgroundTransferRequest? {
            if (
                json.optString("type") !=
                NativeBackgroundTransferContract.TYPE_DOWNLOAD
            ) {
                return null
            }

            val parameters = buildMap<String, Any> {
                copyValue(
                    json = json,
                    sourceKey = "id",
                    destinationKey = "id",
                    destination = this
                )

                copyValue(
                    json = json,
                    sourceKey = "url",
                    destinationKey = "url",
                    destination = this
                )

                copyValue(
                    json = json,
                    sourceKey = "mimeTypes",
                    destinationKey = "mime_types",
                    destination = this
                )

                copyValue(
                    json = json,
                    sourceKey = "maxSize",
                    destinationKey = "max_size",
                    destination = this
                )
            }

            val validation = fromParameters(
                parameters
            )

            if (
                validation !is
                NativeBackgroundTransferRequestValidation.Valid
            ) {
                return null
            }

            val createdAt = positiveLong(
                json = json,
                key = "createdAt"
            ) ?: return null

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

            json.opt(sourceKey)?.let { value ->
                destination[destinationKey] = value
            }
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

            if (value <= 0L) {
                return null
            }

            return value
        }
    }
}