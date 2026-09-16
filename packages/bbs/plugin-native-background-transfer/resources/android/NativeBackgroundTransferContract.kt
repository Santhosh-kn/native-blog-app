package com.bbs.plugins.native_background_transfer

import org.json.JSONArray
import java.net.URI
import java.net.URISyntaxException
import java.util.Locale

internal sealed interface NativeBackgroundTransferUrlValidation {

    data class Valid(
        val url: String
    ) : NativeBackgroundTransferUrlValidation

    data class Invalid(
        val errorCode: String
    ) : NativeBackgroundTransferUrlValidation
}

internal object NativeBackgroundTransferContract {

    const val TYPE_DOWNLOAD = "download"
    const val TYPE_UPLOAD = "upload"

    const val STATUS_QUEUED = "queued"
    const val STATUS_RUNNING = "running"
    const val STATUS_SUCCEEDED = "succeeded"
    const val STATUS_FAILED = "failed"
    const val STATUS_CANCELLED = "cancelled"

    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val INVALID_SOURCE_DOCUMENT_ID = "INVALID_SOURCE_DOCUMENT_ID"
    const val SOURCE_DOCUMENT_UNAVAILABLE = "SOURCE_DOCUMENT_UNAVAILABLE"
    const val INVALID_HTTP_METHOD = "INVALID_HTTP_METHOD"
    const val DUPLICATE_TRANSFER_ID = "DUPLICATE_TRANSFER_ID"
    const val INVALID_URL = "INVALID_URL"
    const val HTTPS_REQUIRED = "HTTPS_REQUIRED"
    const val INVALID_MAX_SIZE = "INVALID_MAX_SIZE"
    const val INVALID_FILE_NAME = "INVALID_FILE_NAME"
    const val INVALID_MIME_TYPE = "INVALID_MIME_TYPE"
    const val TRANSFER_NOT_FOUND = "TRANSFER_NOT_FOUND"
    const val RESULT_ALREADY_CONSUMED = "RESULT_ALREADY_CONSUMED"
    const val SCHEDULER_UNAVAILABLE = "SCHEDULER_UNAVAILABLE"
    const val STORAGE_UNAVAILABLE = "STORAGE_UNAVAILABLE"
    const val FILE_TOO_LARGE = "FILE_TOO_LARGE"
    const val INVALID_CONTENT_TYPE = "INVALID_CONTENT_TYPE"
    const val NETWORK_ERROR = "NETWORK_ERROR"
    const val HTTP_ERROR = "HTTP_ERROR"
    const val RESULT_PERSISTENCE_FAILED = "RESULT_PERSISTENCE_FAILED"
    const val UNKNOWN_ERROR = "UNKNOWN_ERROR"

    const val MAX_CONFIGURABLE_SIZE = 1_073_741_824L
    const val MAX_URL_LENGTH = 4096
    const val MAX_MIME_TYPE_COUNT = 32
    const val MAX_FILE_NAME_CODE_POINTS = 255

    private const val MAX_MIME_TYPE_LENGTH = 127
    private const val MAX_INSPECTED_FILE_NAME_CODE_POINTS = 1024

    private val uuidPattern = Regex(
        "^[0-9a-fA-F]{8}-" +
            "[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{4}-" +
            "[0-9a-fA-F]{12}$"
    )

    private val mimeTypePattern = Regex(
        "^[a-z0-9][a-z0-9!#\\$&^_.+%\\-]*/" +
            "(?:\\*|[a-z0-9][a-z0-9!#\\$&^_.+%\\-]*)$"
    )

    private val unsafeUrlCharacters = Regex(
        "[\\u0000-\\u0020\\u007F]"
    )

    fun normalizeRequestId(value: Any?): String? {
        val candidate = (value as? String)
            ?.trim()
            ?: return null

        if (!uuidPattern.matches(candidate)) {
            return null
        }

        return candidate.lowercase(Locale.ROOT)
    }

    fun normalizeUploadMethod(
        value: Any?
    ): String? {
        val method = (value as? String)
            ?.trim()
            ?.uppercase(Locale.ROOT)
            ?: return null

        return method.takeIf {
            it == "POST" || it == "PUT"
        }
    }

    fun validateHttpsUrl(
        value: Any?
    ): NativeBackgroundTransferUrlValidation {
        val candidate = (value as? String)
            ?.trim()
            ?: return NativeBackgroundTransferUrlValidation.Invalid(
                INVALID_URL
            )

        if (
            candidate.isEmpty() ||
            candidate.length > MAX_URL_LENGTH ||
            unsafeUrlCharacters.containsMatchIn(candidate)
        ) {
            return NativeBackgroundTransferUrlValidation.Invalid(
                INVALID_URL
            )
        }

        val uri = try {
            URI(candidate)
        } catch (_: URISyntaxException) {
            return NativeBackgroundTransferUrlValidation.Invalid(
                INVALID_URL
            )
        } catch (_: IllegalArgumentException) {
            return NativeBackgroundTransferUrlValidation.Invalid(
                INVALID_URL
            )
        }

        val scheme = uri.scheme
            ?.lowercase(Locale.ROOT)
            .orEmpty()

        if (scheme != "https") {
            return NativeBackgroundTransferUrlValidation.Invalid(
                HTTPS_REQUIRED
            )
        }

        if (
            !uri.isAbsolute ||
            uri.host.isNullOrBlank() ||
            uri.rawUserInfo != null ||
            uri.rawFragment != null
        ) {
            return NativeBackgroundTransferUrlValidation.Invalid(
                INVALID_URL
            )
        }

        if (
            uri.port != -1 &&
            uri.port !in 1..65535
        ) {
            return NativeBackgroundTransferUrlValidation.Invalid(
                INVALID_URL
            )
        }

        return NativeBackgroundTransferUrlValidation.Valid(
            candidate
        )
    }

    fun normalizeMimeTypes(
        value: Any?
    ): List<String>? {
        val values = when (value) {
            is JSONArray -> buildList {
                for (index in 0 until value.length()) {
                    add(value.opt(index))
                }
            }

            is List<*> -> value

            else -> return null
        }

        if (
            values.isEmpty() ||
            values.size > MAX_MIME_TYPE_COUNT
        ) {
            return null
        }

        val normalized = linkedSetOf<String>()

        for (item in values) {
            val mimeType = normalizeMimeType(
                value = item,
                allowWildcard = true
            ) ?: return null

            normalized.add(mimeType)
        }

        return normalized.toList()
    }

    fun normalizeConcreteMimeType(
        value: Any?
    ): String? {
        return normalizeMimeType(
            value = value,
            allowWildcard = false
        )
    }

    fun normalizeMaximumSize(
        value: Any?
    ): Long? {
        val number = value as? Number
            ?: return null

        val maxSize = number.toLong()

        if (
            number is Float &&
            number.toDouble() != maxSize.toDouble()
        ) {
            return null
        }

        if (
            number is Double &&
            number != maxSize.toDouble()
        ) {
            return null
        }

        if (
            maxSize < 1L ||
            maxSize > MAX_CONFIGURABLE_SIZE
        ) {
            return null
        }

        return maxSize
    }

    fun normalizeByteCount(
        value: Any?
    ): Long? {
        val number = value as? Number
            ?: return null

        val bytes = number.toLong()

        if (
            number is Float &&
            number.toDouble() != bytes.toDouble()
        ) {
            return null
        }

        if (
            number is Double &&
            number != bytes.toDouble()
        ) {
            return null
        }

        if (
            bytes < 0L ||
            bytes > MAX_CONFIGURABLE_SIZE
        ) {
            return null
        }

        return bytes
    }

    fun normalizeProgress(
        value: Any?
    ): Int? {
        val number = value as? Number
            ?: return null

        val progress = number.toInt()

        if (
            number is Float &&
            number.toDouble() != progress.toDouble()
        ) {
            return null
        }

        if (
            number is Double &&
            number != progress.toDouble()
        ) {
            return null
        }

        if (progress !in 0..100) {
            return null
        }

        return progress
    }

    fun safeDisplayName(
        value: Any?
    ): String? {
        val source = value as? String
            ?: return null

        if (source.isEmpty()) {
            return null
        }

        val sanitized = StringBuilder()

        var index = 0
        var inspected = 0
        var accepted = 0

        while (
            index < source.length &&
            inspected < MAX_INSPECTED_FILE_NAME_CODE_POINTS &&
            accepted < MAX_FILE_NAME_CODE_POINTS
        ) {
            val codePoint = source.codePointAt(index)
            val charCount = Character.charCount(codePoint)

            if (isUnsafeFileNameCodePoint(codePoint)) {
                sanitized.append('_')
            } else {
                sanitized.appendCodePoint(codePoint)
            }

            index += charCount
            inspected++
            accepted++
        }

        val candidate = sanitized
            .toString()
            .trim(' ', '.')

        if (
            candidate.isEmpty() ||
            candidate == "." ||
            candidate == ".."
        ) {
            return null
        }

        return candidate
    }

    fun isMimeTypeAllowed(
        mimeType: String,
        allowedMimeTypes: List<String>
    ): Boolean {
        val type = mimeType.substringBefore('/')

        return allowedMimeTypes.any { allowed ->
            allowed == "*/*" ||
                allowed == mimeType ||
                allowed == "$type/*"
        }
    }

    fun isKnownStatus(status: String): Boolean {
        return status in knownStatuses
    }

    fun isKnownTransferType(type: String): Boolean {
        return type in knownTransferTypes
    }

    fun isTerminalStatus(status: String): Boolean {
        return status in terminalStatuses
    }

    fun isKnownErrorCode(errorCode: String): Boolean {
        return errorCode in knownErrorCodes
    }

    fun errorMessage(errorCode: String): String {
        return when (errorCode) {
            INVALID_REQUEST_ID ->
                "The transfer ID must be a valid UUID."

            INVALID_SOURCE_DOCUMENT_ID ->
                "The source document ID must be a valid UUID."

            SOURCE_DOCUMENT_UNAVAILABLE ->
                "The selected source document is not available for upload."

            INVALID_HTTP_METHOD ->
                "The upload method must be POST or PUT."

            DUPLICATE_TRANSFER_ID ->
                "A transfer already exists with this ID."

            INVALID_URL ->
                "The transfer address is invalid."

            HTTPS_REQUIRED ->
                "Only secure HTTPS transfers are allowed."

            INVALID_MAX_SIZE ->
                "The maximum transfer size is invalid."

            INVALID_FILE_NAME ->
                "The requested file name is invalid."

            INVALID_MIME_TYPE ->
                "The requested file type is invalid."

            TRANSFER_NOT_FOUND ->
                "No saved transfer was found."

            RESULT_ALREADY_CONSUMED ->
                "The transfer result has already been consumed."

            SCHEDULER_UNAVAILABLE ->
                "Background transfer scheduling is unavailable."

            STORAGE_UNAVAILABLE ->
                "Application-private transfer storage is unavailable."

            FILE_TOO_LARGE ->
                "The transferred file exceeds the allowed size."

            INVALID_CONTENT_TYPE ->
                "The downloaded file type is not allowed."

            NETWORK_ERROR ->
                "The transfer could not continue because of a network error."

            HTTP_ERROR ->
                "The remote server rejected the transfer."

            RESULT_PERSISTENCE_FAILED ->
                "The transfer state could not be safely persisted."

            else ->
                "The background transfer could not be completed."
        }
    }

    private fun normalizeMimeType(
        value: Any?,
        allowWildcard: Boolean
    ): String? {
        val mimeType = (value as? String)
            ?.trim()
            ?.lowercase(Locale.ROOT)
            ?: return null

        if (
            mimeType.isEmpty() ||
            mimeType.length > MAX_MIME_TYPE_LENGTH ||
            !mimeTypePattern.matches(mimeType) ||
            !allowWildcard &&
            mimeType.contains('*')
        ) {
            return null
        }

        return mimeType
    }

    private fun isUnsafeFileNameCodePoint(
        codePoint: Int
    ): Boolean {
        if (
            codePoint == '/'.code ||
            codePoint == '\\'.code ||
            codePoint == '<'.code ||
            codePoint == '>'.code ||
            codePoint == ':'.code ||
            codePoint == '"'.code ||
            codePoint == '|'.code ||
            codePoint == '?'.code ||
            codePoint == '*'.code ||
            Character.isISOControl(codePoint)
        ) {
            return true
        }

        return when (Character.getType(codePoint)) {
            Character.FORMAT.toInt(),
            Character.LINE_SEPARATOR.toInt(),
            Character.PARAGRAPH_SEPARATOR.toInt(),
            Character.PRIVATE_USE.toInt(),
            Character.SURROGATE.toInt(),
            Character.UNASSIGNED.toInt() -> true

            else -> false
        }
    }

    private val knownTransferTypes = setOf(
        TYPE_DOWNLOAD,
        TYPE_UPLOAD
    )

    private val knownStatuses = setOf(
        STATUS_QUEUED,
        STATUS_RUNNING,
        STATUS_SUCCEEDED,
        STATUS_FAILED,
        STATUS_CANCELLED
    )

    private val terminalStatuses = setOf(
        STATUS_SUCCEEDED,
        STATUS_FAILED,
        STATUS_CANCELLED
    )

    private val knownErrorCodes = setOf(
        INVALID_REQUEST_ID,
        INVALID_SOURCE_DOCUMENT_ID,
        SOURCE_DOCUMENT_UNAVAILABLE,
        INVALID_HTTP_METHOD,
        DUPLICATE_TRANSFER_ID,
        INVALID_URL,
        HTTPS_REQUIRED,
        INVALID_MAX_SIZE,
        INVALID_FILE_NAME,
        INVALID_MIME_TYPE,
        TRANSFER_NOT_FOUND,
        RESULT_ALREADY_CONSUMED,
        SCHEDULER_UNAVAILABLE,
        STORAGE_UNAVAILABLE,
        FILE_TOO_LARGE,
        INVALID_CONTENT_TYPE,
        NETWORK_ERROR,
        HTTP_ERROR,
        RESULT_PERSISTENCE_FAILED,
        UNKNOWN_ERROR
    )
}