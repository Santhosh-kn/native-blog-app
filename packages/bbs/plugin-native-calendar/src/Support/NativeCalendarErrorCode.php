<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Support;

final class NativeCalendarErrorCode
{
    public const INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';
    public const INVALID_OPTIONS = 'INVALID_OPTIONS';
    public const INVALID_TITLE = 'INVALID_TITLE';
    public const INVALID_DESCRIPTION = 'INVALID_DESCRIPTION';
    public const INVALID_LOCATION = 'INVALID_LOCATION';
    public const INVALID_TIME_RANGE = 'INVALID_TIME_RANGE';
    public const INVALID_ALL_DAY = 'INVALID_ALL_DAY';
    public const INVALID_TIME_ZONE = 'INVALID_TIME_ZONE';
    public const INVALID_RECURRENCE = 'INVALID_RECURRENCE';
    public const INVALID_EVENT_ID = 'INVALID_EVENT_ID';
    public const INVALID_TARGET = 'INVALID_TARGET';
    public const REQUEST_TOO_LARGE = 'REQUEST_TOO_LARGE';
    public const UNSUPPORTED_PLATFORM = 'UNSUPPORTED_PLATFORM';
    public const UNSUPPORTED_ANDROID_VERSION = 'UNSUPPORTED_ANDROID_VERSION';
    public const BRIDGE_UNAVAILABLE = 'BRIDGE_UNAVAILABLE';
    public const INVALID_NATIVE_RESPONSE = 'INVALID_NATIVE_RESPONSE';
    public const NO_CALENDAR_APP = 'NO_CALENDAR_APP';
    public const ACTIVITY_UNAVAILABLE = 'ACTIVITY_UNAVAILABLE';
    public const REQUEST_ALREADY_EXISTS = 'REQUEST_ALREADY_EXISTS';
    public const REQUEST_IN_PROGRESS = 'REQUEST_IN_PROGRESS';
    public const LAUNCH_FAILED = 'LAUNCH_FAILED';
    public const INTERRUPTED = 'INTERRUPTED';
    public const PERSIST_FAILED = 'PERSIST_FAILED';
    public const RESULT_NOT_FOUND = 'RESULT_NOT_FOUND';
    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    private const MESSAGES = [
        self::INVALID_REQUEST_ID => 'The request ID must be a lowercase UUID v4.',
        self::INVALID_OPTIONS => 'The request contains unsupported options.',
        self::INVALID_TITLE => 'The event title is invalid.',
        self::INVALID_DESCRIPTION => 'The event description is invalid.',
        self::INVALID_LOCATION => 'The event location is invalid.',
        self::INVALID_TIME_RANGE => 'The event time range is invalid.',
        self::INVALID_ALL_DAY => 'The all-day setting or date boundaries are invalid.',
        self::INVALID_TIME_ZONE => 'The event time zone is invalid.',
        self::INVALID_RECURRENCE => 'The recurrence rule is invalid or unsupported.',
        self::INVALID_EVENT_ID => 'The event ID must be a positive integer.',
        self::INVALID_TARGET => 'Choose a valid calendar date or event target.',
        self::REQUEST_TOO_LARGE => 'The request exceeds the supported size.',
        self::UNSUPPORTED_PLATFORM => 'Native Calendar is supported on Android only.',
        self::UNSUPPORTED_ANDROID_VERSION => 'Native Calendar requires Android API 33 or later.',
        self::BRIDGE_UNAVAILABLE => 'The native Calendar bridge is unavailable.',
        self::INVALID_NATIVE_RESPONSE => 'The native Calendar response could not be validated.',
        self::NO_CALENDAR_APP => 'No compatible calendar application is available.',
        self::ACTIVITY_UNAVAILABLE => 'The application cannot launch calendar UI right now.',
        self::REQUEST_ALREADY_EXISTS => 'This request ID has already been used.',
        self::REQUEST_IN_PROGRESS => 'Another calendar request is pending.',
        self::LAUNCH_FAILED => 'The calendar editor or viewer could not be launched.',
        self::INTERRUPTED => 'The calendar operation was interrupted; its outcome is unknown.',
        self::PERSIST_FAILED => 'Calendar request state could not be stored safely.',
        self::RESULT_NOT_FOUND => 'No calendar request was found for this ID.',
        self::UNKNOWN_ERROR => 'The calendar operation could not be completed.',
    ];

    public static function isKnown(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, self::MESSAGES);
    }

    public static function canonical(mixed $code): string
    {
        return self::isKnown($code) ? $code : self::UNKNOWN_ERROR;
    }

    public static function message(mixed $code): string
    {
        return self::MESSAGES[self::canonical($code)];
    }
}
