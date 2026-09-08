<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker\Support;

final class NativeDocumentPickerErrorCode
{
    public const INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';

    public const INVALID_MIME_TYPES = 'INVALID_MIME_TYPES';

    public const INVALID_MAX_SIZE = 'INVALID_MAX_SIZE';

    public const ACTIVITY_UNAVAILABLE = 'ACTIVITY_UNAVAILABLE';

    public const PICKER_UNAVAILABLE = 'PICKER_UNAVAILABLE';

    public const PICKER_BUSY = 'PICKER_BUSY';

    public const PICKER_LAUNCH_FAILED = 'PICKER_LAUNCH_FAILED';

    public const UNSUPPORTED_MIME_TYPE = 'UNSUPPORTED_MIME_TYPE';

    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';

    public const SOURCE_UNREADABLE = 'SOURCE_UNREADABLE';

    public const INVALID_DESTINATION = 'INVALID_DESTINATION';

    public const PRIVATE_STORAGE_FAILED = 'PRIVATE_STORAGE_FAILED';

    public const COPY_FAILED = 'COPY_FAILED';

    public const RESULT_NOT_FOUND = 'RESULT_NOT_FOUND';

    public const RESULT_PERSISTENCE_FAILED =
        'RESULT_PERSISTENCE_FAILED';

    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::INVALID_REQUEST_ID,
            self::INVALID_MIME_TYPES,
            self::INVALID_MAX_SIZE,
            self::ACTIVITY_UNAVAILABLE,
            self::PICKER_UNAVAILABLE,
            self::PICKER_BUSY,
            self::PICKER_LAUNCH_FAILED,
            self::UNSUPPORTED_MIME_TYPE,
            self::FILE_TOO_LARGE,
            self::SOURCE_UNREADABLE,
            self::INVALID_DESTINATION,
            self::PRIVATE_STORAGE_FAILED,
            self::COPY_FAILED,
            self::RESULT_NOT_FOUND,
            self::RESULT_PERSISTENCE_FAILED,
            self::UNKNOWN_ERROR,
        ];
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::values(), true);
    }

    public static function message(string $code): string
    {
        return match ($code) {
            self::INVALID_REQUEST_ID => 'The request ID must be a valid UUID.',
            self::INVALID_MIME_TYPES => 'At least one valid MIME type is required.',
            self::INVALID_MAX_SIZE => 'The maximum file size is invalid.',
            self::ACTIVITY_UNAVAILABLE => 'The native document picker cannot access an activity.',
            self::PICKER_UNAVAILABLE => 'The system document picker is unavailable.',
            self::PICKER_BUSY => 'Another document picker request is already active.',
            self::PICKER_LAUNCH_FAILED => 'The system document picker could not be opened.',
            self::UNSUPPORTED_MIME_TYPE => 'The selected document type is not allowed.',
            self::FILE_TOO_LARGE => 'The selected document exceeds the maximum file size.',
            self::SOURCE_UNREADABLE => 'The selected document could not be read.',
            self::INVALID_DESTINATION => 'The private storage destination is invalid.',
            self::PRIVATE_STORAGE_FAILED => 'Application-private document storage is unavailable.',
            self::COPY_FAILED => 'The selected document could not be copied safely.',
            self::RESULT_NOT_FOUND => 'No saved result was found for this request.',
            self::RESULT_PERSISTENCE_FAILED => 'The picker result could not be saved for recovery.',
            default => 'The document picker could not complete the request.',
        };
    }

    private function __construct() {}
}
