<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Support;

final class RemoteConfigErrorCode
{
    public const INVALID_METHOD = 'INVALID_METHOD';
    public const INVALID_OPTIONS = 'INVALID_OPTIONS';
    public const INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';
    public const INVALID_KEY = 'INVALID_KEY';
    public const INVALID_TYPE = 'INVALID_TYPE';
    public const INVALID_VALUE = 'INVALID_VALUE';
    public const INVALID_SETTINGS = 'INVALID_SETTINGS';
    public const REQUEST_TOO_LARGE = 'REQUEST_TOO_LARGE';
    public const LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';
    public const NATIVE_UNAVAILABLE = 'NATIVE_UNAVAILABLE';
    public const INVALID_NATIVE_RESPONSE = 'INVALID_NATIVE_RESPONSE';
    public const NOT_INITIALIZED = 'NOT_INITIALIZED';
    public const DEFAULTS_NOT_READY = 'DEFAULTS_NOT_READY';
    public const BUSY = 'BUSY';
    public const REQUEST_ID_CONFLICT = 'REQUEST_ID_CONFLICT';
    public const FETCH_FAILED = 'FETCH_FAILED';
    public const ACTIVATION_FAILED = 'ACTIVATION_FAILED';
    public const CONFIGURE_FAILED = 'CONFIGURE_FAILED';
    public const DEFAULTS_FAILED = 'DEFAULTS_FAILED';
    public const OPERATION_TIMEOUT = 'OPERATION_TIMEOUT';
    public const THROTTLED = 'THROTTLED';
    public const PROCESS_INTERRUPTED = 'PROCESS_INTERRUPTED';
    public const RESULT_NOT_FOUND = 'RESULT_NOT_FOUND';
    public const PERSIST_FAILED = 'PERSIST_FAILED';

    public const ALL = [
        self::INVALID_METHOD, self::INVALID_OPTIONS, self::INVALID_REQUEST_ID,
        self::INVALID_KEY, self::INVALID_TYPE, self::INVALID_VALUE,
        self::INVALID_SETTINGS, self::REQUEST_TOO_LARGE, self::LIMIT_EXCEEDED,
        self::NATIVE_UNAVAILABLE, self::INVALID_NATIVE_RESPONSE,
        self::NOT_INITIALIZED, self::DEFAULTS_NOT_READY, self::BUSY,
        self::REQUEST_ID_CONFLICT, self::FETCH_FAILED, self::ACTIVATION_FAILED,
        self::CONFIGURE_FAILED, self::DEFAULTS_FAILED, self::OPERATION_TIMEOUT,
        self::THROTTLED, self::PROCESS_INTERRUPTED, self::RESULT_NOT_FOUND,
        self::PERSIST_FAILED,
    ];

    private function __construct() {}
}
