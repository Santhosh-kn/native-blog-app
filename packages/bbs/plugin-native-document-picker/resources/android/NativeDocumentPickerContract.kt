package com.bbs.plugins.native_document_picker

import org.json.JSONArray
import java.util.Locale

internal object NativeDocumentPickerContract {

    const val EVENT_CLASS = "Bbs\\NativeDocumentPicker\\Events\\NativeDocumentPickerCompleted"

    const val STATUS_PENDING = "pending"
    const val STATUS_SUCCEEDED = "succeeded"
    const val STATUS_CANCELLED = "cancelled"
    const val STATUS_FAILED = "failed"
    const val STATUS_NOT_FOUND = "not_found"

    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val INVALID_MIME_TYPES = "INVALID_MIME_TYPES"
    const val INVALID_MAX_SIZE = "INVALID_MAX_SIZE"
    const val ACTIVITY_UNAVAILABLE = "ACTIVITY_UNAVAILABLE"
    const val PICKER_UNAVAILABLE = "PICKER_UNAVAILABLE"
    const val PICKER_BUSY = "PICKER_BUSY"
    const val PICKER_LAUNCH_FAILED = "PICKER_LAUNCH_FAILED"
    const val UNSUPPORTED_MIME_TYPE = "UNSUPPORTED_MIME_TYPE"
    const val FILE_TOO_LARGE = "FILE_TOO_LARGE"
    const val SOURCE_UNREADABLE = "SOURCE_UNREADABLE"
    const val INVALID_DESTINATION = "INVALID_DESTINATION"
    const val PRIVATE_STORAGE_FAILED = "PRIVATE_STORAGE_FAILED"
    const val COPY_FAILED = "COPY_FAILED"
    const val RESULT_NOT_FOUND = "RESULT_NOT_FOUND"
    const val RESULT_PERSISTENCE_FAILED = "RESULT_PERSISTENCE_FAILED"
    const val UNKNOWN_ERROR = "UNKNOWN_ERROR"

    const val MAX_MIME_TYPE_COUNT = 32
    const val MAX_CONFIGURABLE_SIZE = 1_073_741_824L
    const val MAX_PATH_LENGTH = 4096
    const val MAX_FILE_NAME_CODE_POINTS = 255

    private const val MAX_MIME_TYPE_LENGTH = 127

    private val uuidPattern = Regex("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-" + "[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-" + "[0-9a-fA-F]{12}$")

    private val mimeTypePattern = Regex("^(?:\\*/\\*|" + "[a-z0-9][a-z0-9!#\$&^_.+%\\-]*/" + "(?:\\*|[a-z0-9][a-z0-9!#\$&^_.+%\\-]*))$")

    private val controlCharacters = Regex("[\\u0000-\\u001F\\u007F]")

    fun normalizeRequestId(value: Any?): String? {
        val candidate = (value as? String)?.trim() ?: return null

        if (!uuidPattern.matches(candidate)) {
            return null
        }

        return candidate.lowercase(Locale.ROOT)
    }

    fun normalizeMimeTypes(value: Any?): List<String>? {
        val values = when (value) {
            is JSONArray -> {
                buildList {
                    for (index in 0 until value.length()) {
                        add(value.opt(index))
                    }
                }
            }

            is List<*> -> value
            else -> return null
        }

        if (values.isEmpty() || values.size > MAX_MIME_TYPE_COUNT) {
            return null
        }

        val normalized = linkedSetOf<String>()

        for (item in values) {
            val mimeType = normalizeMimeType(value = item, allowWildcard = true) ?: return null

            normalized.add(mimeType)
        }

        return normalized.toList()
    }

    fun normalizeConcreteMimeType(value: Any?): String? {
        return normalizeMimeType(value = value, allowWildcard = false)
    }

    fun normalizeMaximumSize(value: Any?): Long? {
        val number = value as? Number ?: return null
        val maxSize = number.toLong()

        if (number is Float && number.toDouble() != maxSize.toDouble() || number is Double && number != maxSize.toDouble()){
            return null
        }

        if (maxSize < 1 || maxSize > MAX_CONFIGURABLE_SIZE) {
            return null
        }

        return maxSize
    }

    fun normalizePath(value: Any?): String? {
        val path = value as? String ?: return null

        if (path.isEmpty() || path.length > MAX_PATH_LENGTH || controlCharacters.containsMatchIn(path)) {
            return null
        }

        return path
    }

    fun isMimeTypeAllowed(mimeType: String, allowedMimeTypes: List<String> ): Boolean {
        val type = mimeType.substringBefore('/')

        return allowedMimeTypes.any { allowed ->
            allowed == "*/*" ||
                allowed == mimeType ||
                allowed == "$type/*"
        }
    }

    fun isKnownErrorCode(errorCode: String): Boolean {
        return knownErrorCodes.contains(errorCode)
    }

    fun errorMessage(errorCode: String): String {
        return when (errorCode) {
            INVALID_REQUEST_ID ->
                "The request ID must be a valid UUID."
            INVALID_MIME_TYPES ->
                "At least one valid MIME type is required."
            INVALID_MAX_SIZE ->
                "The maximum file size is invalid."
            ACTIVITY_UNAVAILABLE ->
                "The native document picker cannot access an activity."
            PICKER_UNAVAILABLE ->
                "The system document picker is unavailable."
            PICKER_BUSY ->
                "Another document picker request is already active."
            PICKER_LAUNCH_FAILED ->
                "The system document picker could not be opened."
            UNSUPPORTED_MIME_TYPE ->
                "The selected document type is not allowed."
            FILE_TOO_LARGE ->
                "The selected document exceeds the maximum file size."
            SOURCE_UNREADABLE ->
                "The selected document could not be read."
            INVALID_DESTINATION ->
                "The private storage destination is invalid."
            PRIVATE_STORAGE_FAILED ->
                "Application-private document storage is unavailable."
            COPY_FAILED ->
                "The selected document could not be copied safely."
            RESULT_NOT_FOUND ->
                "No saved result was found for this request."
            RESULT_PERSISTENCE_FAILED ->
                "The picker result could not be saved for recovery."
            else ->
                "The document picker could not complete the request."
        }
    }

    private fun normalizeMimeType(value: Any?, allowWildcard: Boolean): String? {
        val mimeType = (value as? String) ?.trim() ?.lowercase(Locale.ROOT) ?: return null

        if (mimeType.isEmpty() || mimeType.length > MAX_MIME_TYPE_LENGTH || !mimeTypePattern.matches(mimeType) || !allowWildcard && mimeType.contains('*')) {
            return null
        }

        return mimeType
    }

    private val knownErrorCodes = setOf(
        INVALID_REQUEST_ID,
        INVALID_MIME_TYPES,
        INVALID_MAX_SIZE,
        ACTIVITY_UNAVAILABLE,
        PICKER_UNAVAILABLE,
        PICKER_BUSY,
        PICKER_LAUNCH_FAILED,
        UNSUPPORTED_MIME_TYPE,
        FILE_TOO_LARGE,
        SOURCE_UNREADABLE,
        INVALID_DESTINATION,
        PRIVATE_STORAGE_FAILED,
        COPY_FAILED,
        RESULT_NOT_FOUND,
        RESULT_PERSISTENCE_FAILED,
        UNKNOWN_ERROR
    )
}
