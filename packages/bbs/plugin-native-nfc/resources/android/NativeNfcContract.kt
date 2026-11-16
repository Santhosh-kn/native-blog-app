package com.bbs.plugins.native_nfc

internal object NativeNfcContract {

    const val OP_IS_AVAILABLE = "is_available"
    const val OP_START_SCAN = "start_scan"
    const val OP_START_WRITE = "start_write"
    const val OP_GET_STATUS = "get_status"
    const val OP_STOP_SESSION = "stop_session"
    const val OP_CONSUME_RESULT = "consume_result"

    const val STATUS_PENDING = "pending"
    const val STATUS_READ = "read"
    const val STATUS_WRITTEN = "written"
    const val STATUS_CANCELLED = "cancelled"
    const val STATUS_FAILED = "failed"
    const val STATUS_UNKNOWN = "unknown"
    const val STATUS_NOT_FOUND = "not_found"

    const val AVAILABILITY_AVAILABLE = "available"
    const val AVAILABILITY_UNAVAILABLE = "unavailable"
    const val AVAILABILITY_DISABLED = "disabled"

    const val ERROR_INVALID_PARAMETERS = "invalid_parameters"
    const val ERROR_INVALID_REQUEST_ID = "invalid_request_id"
    const val ERROR_REQUEST_TOO_LARGE = "request_too_large"
    const val ERROR_TEXT_TOO_LARGE = "text_too_large"
    const val ERROR_EMPTY_TEXT = "empty_text"
    const val ERROR_NFC_UNAVAILABLE = "nfc_unavailable"
    const val ERROR_NFC_DISABLED = "nfc_disabled"
    const val ERROR_ACTIVITY_UNAVAILABLE = "activity_unavailable"
    const val ERROR_OPERATION_BUSY = "operation_busy"
    const val ERROR_UNSUPPORTED_TAG = "unsupported_tag"
    const val ERROR_TAG_NOT_NDEF = "tag_not_ndef"
    const val ERROR_TAG_READ_ONLY = "tag_read_only"
    const val ERROR_TAG_TOO_SMALL = "tag_too_small"
    const val ERROR_TAG_LOST = "tag_lost"
    const val ERROR_IO = "io_error"
    const val ERROR_RESULT_NOT_READY = "result_not_ready"
    const val ERROR_RESULT_ALREADY_CONSUMED = "result_already_consumed"
    const val ERROR_RESULT_EXPIRED = "result_expired"
    const val ERROR_OPERATION_INTERRUPTED = "operation_interrupted"
    const val ERROR_UNKNOWN = "unknown_error"

    const val EVENT_CLASS = "Bbs\\NativeNfc\\Events\\NativeNfcCompleted"
    const val MAX_EVENT_BYTES = 8 * 1024

    const val MAX_REQUEST_BYTES = 8 * 1024
    const val MAX_TEXT_BYTES = 4 * 1024
    const val MAX_REQUEST_ID_LENGTH = 36

    const val RESULT_TTL_MILLIS = 5 * 60 * 1000L
    const val MAX_STORED_RESULTS = 20

    val UUID_V4_REGEX = Regex(
        "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
    )

    val TERMINAL_STATUSES = setOf(
        STATUS_READ,
        STATUS_WRITTEN,
        STATUS_CANCELLED,
        STATUS_FAILED,
        STATUS_UNKNOWN
    )
    val FAILURE_ERROR_CODES = setOf(
        ERROR_NFC_UNAVAILABLE,
        ERROR_NFC_DISABLED,
        ERROR_ACTIVITY_UNAVAILABLE,
        ERROR_UNSUPPORTED_TAG,
        ERROR_TAG_NOT_NDEF,
        ERROR_TAG_READ_ONLY,
        ERROR_TAG_TOO_SMALL,
        ERROR_TEXT_TOO_LARGE,
        ERROR_TAG_LOST,
        ERROR_IO,
        ERROR_UNKNOWN
    )
}
