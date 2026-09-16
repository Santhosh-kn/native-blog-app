<?php

declare(strict_types=1);

namespace Bbs\NativeBackgroundTransfer\Support;

final class NativeBackgroundTransferErrorCode
{
    public const INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';

    public const INVALID_SOURCE_DOCUMENT_ID = 'INVALID_SOURCE_DOCUMENT_ID';

    public const SOURCE_DOCUMENT_UNAVAILABLE = 'SOURCE_DOCUMENT_UNAVAILABLE';

    public const INVALID_HTTP_METHOD = 'INVALID_HTTP_METHOD';

    public const DUPLICATE_TRANSFER_ID = 'DUPLICATE_TRANSFER_ID';

    public const INVALID_URL = 'INVALID_URL';

    public const HTTPS_REQUIRED = 'HTTPS_REQUIRED';

    public const INVALID_MAX_SIZE = 'INVALID_MAX_SIZE';

    public const INVALID_FILE_NAME = 'INVALID_FILE_NAME';

    public const INVALID_MIME_TYPE = 'INVALID_MIME_TYPE';

    public const TRANSFER_NOT_FOUND = 'TRANSFER_NOT_FOUND';

    public const RESULT_ALREADY_CONSUMED = 'RESULT_ALREADY_CONSUMED';

    public const SCHEDULER_UNAVAILABLE = 'SCHEDULER_UNAVAILABLE';

    public const STORAGE_UNAVAILABLE = 'STORAGE_UNAVAILABLE';

    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';

    public const INVALID_CONTENT_TYPE = 'INVALID_CONTENT_TYPE';

    public const NETWORK_ERROR = 'NETWORK_ERROR';

    public const HTTP_ERROR = 'HTTP_ERROR';

    public const RESULT_PERSISTENCE_FAILED = 'RESULT_PERSISTENCE_FAILED';

    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::INVALID_REQUEST_ID,
            self::INVALID_SOURCE_DOCUMENT_ID,
            self::SOURCE_DOCUMENT_UNAVAILABLE,
            self::INVALID_HTTP_METHOD,
            self::DUPLICATE_TRANSFER_ID,
            self::INVALID_URL,
            self::HTTPS_REQUIRED,
            self::INVALID_MAX_SIZE,
            self::INVALID_FILE_NAME,
            self::INVALID_MIME_TYPE,
            self::TRANSFER_NOT_FOUND,
            self::RESULT_ALREADY_CONSUMED,
            self::SCHEDULER_UNAVAILABLE,
            self::STORAGE_UNAVAILABLE,
            self::FILE_TOO_LARGE,
            self::INVALID_CONTENT_TYPE,
            self::NETWORK_ERROR,
            self::HTTP_ERROR,
            self::RESULT_PERSISTENCE_FAILED,
            self::UNKNOWN_ERROR,
        ];
    }

    public static function isKnown(string $errorCode): bool
    {
        return in_array($errorCode, self::all(), true);
    }

    public static function message(string $errorCode): string
    {
        return match ($errorCode) {
            self::INVALID_REQUEST_ID =>
                'The transfer ID must be a valid UUID.',

            self::INVALID_SOURCE_DOCUMENT_ID =>
                'The source document ID must be a valid UUID.',

            self::SOURCE_DOCUMENT_UNAVAILABLE =>
                'The selected source document is not available for upload.',

            self::INVALID_HTTP_METHOD =>
                'The upload method must be POST or PUT.',

            self::DUPLICATE_TRANSFER_ID =>
                'A transfer already exists with this ID.',

            self::INVALID_URL =>
                'The transfer address is invalid.',

            self::HTTPS_REQUIRED =>
                'Only secure HTTPS transfers are allowed.',

            self::INVALID_MAX_SIZE =>
                'The maximum transfer size is invalid.',

            self::INVALID_FILE_NAME =>
                'The requested file name is invalid.',

            self::INVALID_MIME_TYPE =>
                'The requested file type is invalid.',

            self::TRANSFER_NOT_FOUND =>
                'No saved transfer was found.',

            self::RESULT_ALREADY_CONSUMED =>
                'The transfer result has already been consumed.',

            self::SCHEDULER_UNAVAILABLE =>
                'Background transfer scheduling is unavailable.',

            self::STORAGE_UNAVAILABLE =>
                'Application-private transfer storage is unavailable.',

            self::FILE_TOO_LARGE =>
                'The transferred file exceeds the allowed size.',

            self::INVALID_CONTENT_TYPE =>
                'The downloaded file type is not allowed.',

            self::NETWORK_ERROR =>
                'The transfer could not continue because of a network error.',

            self::HTTP_ERROR =>
                'The remote server rejected the transfer.',

            self::RESULT_PERSISTENCE_FAILED =>
                'The transfer state could not be safely persisted.',

            default =>
                'The background transfer could not be completed.',
        };
    }
}