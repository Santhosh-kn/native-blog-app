<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Support;

final class NativeMediaOptimizerErrorCode
{
    public const INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';
    public const INVALID_SOURCE_ID = 'INVALID_SOURCE_ID';
    public const INVALID_OPTIONS = 'INVALID_OPTIONS';
    public const REQUEST_TOO_LARGE = 'REQUEST_TOO_LARGE';
    public const INVALID_DIMENSIONS = 'INVALID_DIMENSIONS';
    public const INVALID_QUALITY = 'INVALID_QUALITY';
    public const INVALID_FORMAT = 'INVALID_FORMAT';
    public const INVALID_BITRATE = 'INVALID_BITRATE';
    public const INVALID_TIME_RANGE = 'INVALID_TIME_RANGE';
    public const NATIVE_UNAVAILABLE = 'NATIVE_UNAVAILABLE';
    public const INVALID_NATIVE_RESPONSE = 'INVALID_NATIVE_RESPONSE';
    public const SOURCE_UNAVAILABLE = 'SOURCE_UNAVAILABLE';
    public const UNSUPPORTED_MEDIA = 'UNSUPPORTED_MEDIA';
    public const DECODE_FAILED = 'DECODE_FAILED';
    public const ENCODE_FAILED = 'ENCODE_FAILED';
    public const OUTPUT_FAILED = 'OUTPUT_FAILED';
    public const PERSIST_FAILED = 'PERSIST_FAILED';
    public const BUSY = 'BUSY';
    public const RESULT_NOT_FOUND = 'RESULT_NOT_FOUND';
    public const PROCESS_INTERRUPTED = 'PROCESS_INTERRUPTED';
    public const OUTPUT_IN_USE = 'OUTPUT_IN_USE';
    public const OUTPUT_NOT_FOUND = 'OUTPUT_NOT_FOUND';
    public const LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

    private const MESSAGES = [
        self::INVALID_REQUEST_ID => 'A lowercase UUID version 4 request ID is required.',
        self::INVALID_SOURCE_ID => 'A lowercase UUID version 4 picker document ID is required.',
        self::INVALID_OPTIONS => 'The processing options are invalid.',
        self::REQUEST_TOO_LARGE => 'The processing request exceeds the JSON size limit.',
        self::INVALID_DIMENSIONS => 'The requested dimensions are outside the supported limits.',
        self::INVALID_QUALITY => 'Quality must be an integer from 1 to 100 and is unavailable for PNG.',
        self::INVALID_FORMAT => 'The requested output format is unsupported.',
        self::INVALID_BITRATE => 'The requested bitrate is outside the supported limits.',
        self::INVALID_TIME_RANGE => 'The requested media timestamps are invalid.',
        self::NATIVE_UNAVAILABLE => 'Native Android media processing is unavailable.',
        self::INVALID_NATIVE_RESPONSE => 'The native processor returned an invalid response.',
        self::SOURCE_UNAVAILABLE => 'The selected private media file is unavailable.',
        self::UNSUPPORTED_MEDIA => 'This media type or codec is unsupported.',
        self::DECODE_FAILED => 'The media could not be decoded.',
        self::ENCODE_FAILED => 'The media could not be encoded with the requested settings.',
        self::OUTPUT_FAILED => 'The processed output could not be saved or verified.',
        self::PERSIST_FAILED => 'The processing state could not be read or saved.',
        self::BUSY => 'Another processing job is active.',
        self::RESULT_NOT_FOUND => 'The processing request was not found.',
        self::PROCESS_INTERRUPTED => 'Processing was interrupted. Start a new request to retry.',
        self::OUTPUT_IN_USE => 'The output is currently in use.',
        self::OUTPUT_NOT_FOUND => 'The processed output is unavailable.',
        self::LIMIT_EXCEEDED => 'The media exceeds a supported processing or storage limit.',
    ];

    public static function isKnown(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, self::MESSAGES);
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Native media processing failed.';
    }
}
