package com.bbs.plugins.native_printing

import java.util.Locale
import java.util.UUID

internal object NativePrintingContract {

    const val EVENT_CLASS =
        "Bbs\\NativePrinting\\Events\\NativePrintingStateChanged"

    const val ACTION_PREVIEW = "preview"
    const val ACTION_PRINT = "print"

    const val STATUS_ACCEPTED = "accepted"
    const val STATUS_PRESENTED = "presented"
    const val STATUS_CLOSED = "closed"
    const val STATUS_SUBMITTED = "submitted"
    const val STATUS_BLOCKED = "blocked"
    const val STATUS_CANCELLED = "cancelled"
    const val STATUS_COMPLETED = "completed"
    const val STATUS_FAILED = "failed"

    const val PRINTING_UNAVAILABLE = "PRINTING_UNAVAILABLE"
    const val FILE_NOT_FOUND = "FILE_NOT_FOUND"
    const val FILE_NOT_READABLE = "FILE_NOT_READABLE"

    const val FILE_OUTSIDE_APP_STORAGE =
        "FILE_OUTSIDE_APP_STORAGE"

    const val INVALID_FILE_TYPE = "INVALID_FILE_TYPE"
    const val INVALID_PDF = "INVALID_PDF"
    const val INVALID_REQUEST_ID = "INVALID_REQUEST_ID"
    const val PREVIEW_UNAVAILABLE = "PREVIEW_UNAVAILABLE"
    const val PREVIEW_FAILED = "PREVIEW_FAILED"
    const val PRINT_DIALOG_FAILED = "PRINT_DIALOG_FAILED"
    const val PRINT_CANCELLED = "PRINT_CANCELLED"
    const val PRINT_JOB_FAILED = "PRINT_JOB_FAILED"
    const val ACTIVITY_UNAVAILABLE = "ACTIVITY_UNAVAILABLE"
    const val UNKNOWN_ERROR = "UNKNOWN_ERROR"

    const val EXTRA_PATH =
        "com.bbs.plugins.native_printing.extra.PATH"

    const val EXTRA_TITLE =
        "com.bbs.plugins.native_printing.extra.TITLE"

    const val EXTRA_REQUEST_ID =
        "com.bbs.plugins.native_printing.extra.REQUEST_ID"

    private const val MAX_LABEL_LENGTH = 120

    private const val MAX_REJECTED_REQUEST_ID_LENGTH = 128

    private val uuidPattern = Regex(
        """^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-""" +
            """[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-""" +
            """[0-9a-fA-F]{12}$"""
    )

    private val controlCharacters = Regex(
        "[\u0000-\u001F\u007F]+"
    )

    private val repeatedWhitespace = Regex("\\s+")

    fun resolveRequestId(value: Any?): String? {
        if (value == null) {
            return UUID.randomUUID().toString()
        }

        if (value !is String) {
            return null
        }

        val candidate = value.trim()

        if (candidate.isEmpty()) {
            return UUID.randomUUID().toString()
        }

        if (!uuidPattern.matches(candidate)) {
            return null
        }

        return candidate.lowercase(Locale.ROOT)
    }

    fun safeRejectedRequestId(value: Any?): String {
        val candidate = (value as? String)
            ?.trim()
            .orEmpty()

        if (candidate.isEmpty()) {
            return UUID.randomUUID().toString()
        }

        return candidate.take(
            MAX_REJECTED_REQUEST_ID_LENGTH
        )
    }

    fun normalizeLabel(
        value: Any?,
        fallback: String
    ): String {
        val raw = value as? String ?: ""

        val normalized = repeatedWhitespace.replace(
            controlCharacters.replace(raw, " "),
            " "
        ).trim()

        if (normalized.isEmpty()) {
            return fallback
        }

        return normalized.take(MAX_LABEL_LENGTH)
    }
}
