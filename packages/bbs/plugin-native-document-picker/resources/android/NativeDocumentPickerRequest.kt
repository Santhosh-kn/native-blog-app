package com.bbs.plugins.native_document_picker

import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

internal sealed interface NativeDocumentPickerRequestValidation {

    data class Valid(
        val request: NativeDocumentPickerRequest
    ) : NativeDocumentPickerRequestValidation

    data class Invalid(
        val id: String,
        val errorCode: String
    ) : NativeDocumentPickerRequestValidation
}

internal data class NativeDocumentPickerRequest(
    val id: String,
    val mimeTypes: List<String>,
    val maxSize: Long,
    val destinationPath: String,
    val startedAt: Long
) {
    fun toJson(): JSONObject {
        return JSONObject().apply {
            put("id", id)
            put("mimeTypes", JSONArray(mimeTypes))
            put("maxSize", maxSize)
            put("destinationPath", destinationPath)
            put("startedAt", startedAt)
        }
    }

    companion object {
        fun fromParameters(
            parameters: Map<String, Any>
        ): NativeDocumentPickerRequestValidation {
            val id = NativeDocumentPickerContract.normalizeRequestId(
                parameters["id"]
            )

            if (id == null) {
                return NativeDocumentPickerRequestValidation.Invalid(
                    id = UUID.randomUUID().toString(),
                    errorCode = NativeDocumentPickerContract.INVALID_REQUEST_ID
                )
            }

            val mimeTypes = NativeDocumentPickerContract.normalizeMimeTypes(
                parameters["mime_types"]
            )

            if (mimeTypes == null) {
                return NativeDocumentPickerRequestValidation.Invalid(
                    id = id,
                    errorCode = NativeDocumentPickerContract.INVALID_MIME_TYPES
                )
            }

            val maxSize = NativeDocumentPickerContract.normalizeMaximumSize(
                parameters["max_size"]
            )

            if (maxSize == null) {
                return NativeDocumentPickerRequestValidation.Invalid(
                    id = id,
                    errorCode = NativeDocumentPickerContract.INVALID_MAX_SIZE
                )
            }

            val destinationPath = NativeDocumentPickerContract.normalizePath(
                parameters["destination_path"]
            )

            if (destinationPath == null) {
                return NativeDocumentPickerRequestValidation.Invalid(
                    id = id,
                    errorCode = NativeDocumentPickerContract.INVALID_DESTINATION
                )
            }

            return NativeDocumentPickerRequestValidation.Valid(
                NativeDocumentPickerRequest(
                    id = id,
                    mimeTypes = mimeTypes,
                    maxSize = maxSize,
                    destinationPath = destinationPath,
                    startedAt = System.currentTimeMillis()
                )
            )
        }

        fun fromJson(json: JSONObject): NativeDocumentPickerRequest? {
            val persistedParameters = buildMap<String, Any> {
                copyJsonValue(json, "id", "id", this)
                copyJsonValue(json, "mimeTypes", "mime_types", this)
                copyJsonValue(json, "maxSize", "max_size", this)
                copyJsonValue(
                    json,
                    "destinationPath",
                    "destination_path",
                    this
                )
            }

            val validation = fromParameters(
                persistedParameters
            )

            if (validation !is NativeDocumentPickerRequestValidation.Valid) {
                return null
            }

            val startedAt = storedPositiveLong(json, "startedAt")
                ?: return null

            return validation.request.copy(startedAt = startedAt)
        }

        private fun copyJsonValue(
            json: JSONObject,
            sourceKey: String,
            destinationKey: String,
            destination: MutableMap<String, Any>
        ) {
            if (!json.has(sourceKey) || json.isNull(sourceKey)) {
                return
            }

            json.opt(sourceKey)?.let { value ->
                destination[destinationKey] = value
            }
        }

        private fun storedPositiveLong(
            json: JSONObject,
            key: String
        ): Long? {
            val number = json.opt(key) as? Number ?: return null
            val value = number.toLong()

            if (
                number is Float && number.toDouble() != value.toDouble() ||
                number is Double && number != value.toDouble() ||
                value <= 0
            ) {
                return null
            }

            return value
        }
    }
}
