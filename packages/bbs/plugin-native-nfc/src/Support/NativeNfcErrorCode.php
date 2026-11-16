<?php

declare(strict_types=1);

namespace Bbs\NativeNfc\Support;

final class NativeNfcErrorCode
{
    public const INVALID_PARAMETERS = 'invalid_parameters';
    public const REQUEST_TOO_LARGE = 'request_too_large';
    public const INVALID_REQUEST_ID = 'invalid_request_id';
    public const EMPTY_TEXT = 'empty_text';
    public const TEXT_TOO_LARGE = 'text_too_large';
    public const BRIDGE_UNAVAILABLE = 'bridge_unavailable';
    public const NFC_UNAVAILABLE = 'nfc_unavailable';
    public const NFC_DISABLED = 'nfc_disabled';
    public const ACTIVITY_UNAVAILABLE = 'activity_unavailable';
    public const OPERATION_BUSY = 'operation_busy';
    public const REQUEST_ID_REUSED = 'request_id_reused';
    public const UNSUPPORTED_TAG = 'unsupported_tag';
    public const TAG_NOT_NDEF = 'tag_not_ndef';
    public const TAG_READ_ONLY = 'tag_read_only';
    public const TAG_TOO_SMALL = 'tag_too_small';
    public const TAG_LOST = 'tag_lost';
    public const IO_ERROR = 'io_error';
    public const RESULT_NOT_FOUND = 'result_not_found';
    public const RESULT_NOT_READY = 'result_not_ready';
    public const RESULT_ALREADY_CONSUMED = 'result_already_consumed';
    public const RESULT_EXPIRED = 'result_expired';
    public const OPERATION_INTERRUPTED = 'operation_interrupted';
    public const INVALID_NATIVE_RESPONSE = 'invalid_native_response';
    public const UNKNOWN_ERROR = 'unknown_error';

    private const MESSAGES = [
        self::INVALID_PARAMETERS => 'The NFC request contains invalid fields.',
        self::REQUEST_TOO_LARGE => 'The NFC request is too large.',
        self::INVALID_REQUEST_ID => 'The NFC request ID is invalid.',
        self::EMPTY_TEXT => 'The NFC write text cannot be empty.',
        self::TEXT_TOO_LARGE => 'The NFC text exceeds the supported size.',
        self::BRIDGE_UNAVAILABLE => 'The native NFC bridge is unavailable.',
        self::NFC_UNAVAILABLE => 'This device does not support NFC.',
        self::NFC_DISABLED => 'NFC is disabled on this device.',
        self::ACTIVITY_UNAVAILABLE => 'The Android activity is unavailable.',
        self::OPERATION_BUSY => 'Another NFC operation is already active.',
        self::REQUEST_ID_REUSED => 'The NFC request ID has already been used.',
        self::UNSUPPORTED_TAG => 'The NFC tag format is not supported.',
        self::TAG_NOT_NDEF => 'The NFC tag does not support NDEF.',
        self::TAG_READ_ONLY => 'The NFC tag is read-only.',
        self::TAG_TOO_SMALL => 'The NFC tag does not have enough storage.',
        self::TAG_LOST => 'The NFC tag was removed during the operation.',
        self::IO_ERROR => 'An NFC communication error occurred.',
        self::RESULT_NOT_FOUND => 'No NFC result exists for this request.',
        self::RESULT_NOT_READY => 'The NFC result is not ready.',
        self::RESULT_ALREADY_CONSUMED => 'The NFC result has already been consumed.',
        self::RESULT_EXPIRED => 'The NFC result has expired.',
        self::OPERATION_INTERRUPTED => 'The NFC operation was interrupted or its outcome is unknown.',
        self::INVALID_NATIVE_RESPONSE => 'The native NFC response is invalid.',
        self::UNKNOWN_ERROR => 'The NFC operation could not be completed.',
    ];

    public static function isKnown(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, self::MESSAGES);
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES[self::UNKNOWN_ERROR];
    }
}
