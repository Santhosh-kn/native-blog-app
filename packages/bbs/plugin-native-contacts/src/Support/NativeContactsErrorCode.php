<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Support;

final class NativeContactsErrorCode
{
    public const INVALID_PARAMETERS = 'INVALID_PARAMETERS';
    public const REQUEST_TOO_LARGE = 'REQUEST_TOO_LARGE';
    public const INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';
    public const INVALID_MODE = 'INVALID_MODE';
    public const INVALID_NAME = 'INVALID_NAME';
    public const INVALID_PHONE = 'INVALID_PHONE';
    public const INVALID_EMAIL = 'INVALID_EMAIL';
    public const INVALID_URI = 'INVALID_URI';
    public const BRIDGE_UNAVAILABLE = 'BRIDGE_UNAVAILABLE';
    public const ACTIVITY_UNAVAILABLE = 'ACTIVITY_UNAVAILABLE';
    public const OPERATION_UNAVAILABLE = 'OPERATION_UNAVAILABLE';
    public const OPERATION_BUSY = 'OPERATION_BUSY';
    public const REQUEST_ID_REUSED = 'REQUEST_ID_REUSED';
    public const LAUNCH_FAILED = 'LAUNCH_FAILED';
    public const CONTACT_ACCESS_DENIED = 'CONTACT_ACCESS_DENIED';
    public const INVALID_SELECTION = 'INVALID_SELECTION';
    public const CONTACT_READ_FAILED = 'CONTACT_READ_FAILED';
    public const RESULT_PERSISTENCE_FAILED = 'RESULT_PERSISTENCE_FAILED';
    public const RESULT_NOT_FOUND = 'RESULT_NOT_FOUND';
    public const RESULT_NOT_READY = 'RESULT_NOT_READY';
    public const RESULT_ALREADY_CONSUMED = 'RESULT_ALREADY_CONSUMED';
    public const RESULT_EXPIRED = 'RESULT_EXPIRED';
    public const OPERATION_INTERRUPTED = 'OPERATION_INTERRUPTED';
    public const INVALID_NATIVE_RESPONSE = 'INVALID_NATIVE_RESPONSE';
    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    private const MESSAGES = [
        self::INVALID_PARAMETERS => 'The contact request contains invalid fields.',
        self::REQUEST_TOO_LARGE => 'The contact request is too large.',
        self::INVALID_REQUEST_ID => 'The request ID is invalid.',
        self::INVALID_MODE => 'The contact selection mode is invalid.',
        self::INVALID_NAME => 'The contact name is invalid.',
        self::INVALID_PHONE => 'The phone number format is invalid.',
        self::INVALID_EMAIL => 'The email address format is invalid.',
        self::INVALID_URI => 'The contact reference is invalid.',
        self::BRIDGE_UNAVAILABLE => 'The native Contacts bridge is unavailable.',
        self::ACTIVITY_UNAVAILABLE => 'The Android activity is unavailable.',
        self::OPERATION_UNAVAILABLE => 'No compatible Android contact activity is available.',
        self::OPERATION_BUSY => 'A contact operation is already pending.',
        self::REQUEST_ID_REUSED => 'The request ID has already been used.',
        self::LAUNCH_FAILED => 'The Android contact screen could not be launched.',
        self::CONTACT_ACCESS_DENIED => 'Access to the selected contact item was denied.',
        self::INVALID_SELECTION => 'The contact picker returned an invalid selection.',
        self::CONTACT_READ_FAILED => 'The selected contact item could not be read.',
        self::RESULT_PERSISTENCE_FAILED => 'The contact result could not be stored safely.',
        self::RESULT_NOT_FOUND => 'No contact result exists for this request.',
        self::RESULT_NOT_READY => 'The contact result is not ready.',
        self::RESULT_ALREADY_CONSUMED => 'The contact result has already been consumed.',
        self::RESULT_EXPIRED => 'The selected contact data has expired.',
        self::OPERATION_INTERRUPTED => 'The contact operation outcome could not be established.',
        self::INVALID_NATIVE_RESPONSE => 'The native Contacts response is invalid.',
        self::UNKNOWN_ERROR => 'The contact operation could not be completed.',
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
