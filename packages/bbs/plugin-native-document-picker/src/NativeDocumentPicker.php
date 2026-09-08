<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker;

use Bbs\NativeDocumentPicker\Contracts\NativeBridge;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerErrorCode;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Illuminate\Support\Str;

final class NativeDocumentPicker
{
    public const DEFAULT_MAX_SIZE = 20_971_520;

    public const MAX_CONFIGURABLE_SIZE = 1_073_741_824;

    public const DEFAULT_MIME_TYPES = [
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAX_MIME_TYPE_COUNT = 32;

    private const MAX_PATH_LENGTH = 4096;

    private const MIME_TYPE_PATTERN =
        '/\A(?:\*\/\*|[a-z0-9][a-z0-9!#$&^_.+%-]*\/(?:\*|[a-z0-9][a-z0-9!#$&^_.+%-]*))\z/D';

    public function __construct(
        private readonly NativeBridge $bridge,
        private readonly string $privateStoragePath,
    ) {}

    /**
     * Start a request with Android's system document picker.
     *
     * @param array{
     *     id?: string|null,
     *     mime_types?: list<string>,
     *     max_size?: int
     * } $options
     */
    public function pick(array $options = []): object
    {
        $requestId = $this->resolveRequestId(
            $options['id'] ?? null,
            generateWhenEmpty: true,
        );

        if ($requestId === null) {
            return $this->rejected(
                requestId: (string) Str::uuid(),
                errorCode: NativeDocumentPickerErrorCode::INVALID_REQUEST_ID,
            );
        }

        $mimeTypes = array_key_exists('mime_types', $options)
            ? $this->normalizeMimeTypes($options['mime_types'])
            : self::DEFAULT_MIME_TYPES;

        if ($mimeTypes === null) {
            return $this->rejected(
                requestId: $requestId,
                errorCode: NativeDocumentPickerErrorCode::INVALID_MIME_TYPES,
            );
        }

        $maxSize = array_key_exists('max_size', $options)
            ? $options['max_size']
            : self::DEFAULT_MAX_SIZE;

        if (
            ! is_int($maxSize) ||
            $maxSize < 1 ||
            $maxSize > self::MAX_CONFIGURABLE_SIZE
        ) {
            return $this->rejected(
                requestId: $requestId,
                errorCode: NativeDocumentPickerErrorCode::INVALID_MAX_SIZE,
            );
        }

        if (! $this->isSafePrivateStoragePath()) {
            return $this->rejected(
                requestId: $requestId,
                errorCode: NativeDocumentPickerErrorCode::INVALID_DESTINATION,
            );
        }

        $response = $this->bridge->call(
            'NativeDocumentPicker.Pick',
            [
                'id' => $requestId,
                'mime_types' => $mimeTypes,
                'max_size' => $maxSize,
                'destination_path' => $this->privateStoragePath,
            ],
        );

        if ($response === null) {
            return $this->rejected(
                requestId: $requestId,
                errorCode: NativeDocumentPickerErrorCode::ACTIVITY_UNAVAILABLE,
            );
        }

        $payload = get_object_vars($response);

        if (($payload['accepted'] ?? false) !== true) {
            return $this->rejected(
                requestId: $requestId,
                errorCode: $this->normalizeErrorCode(
                    $payload['errorCode'] ?? null,
                    NativeDocumentPickerErrorCode::PICKER_UNAVAILABLE,
                ),
            );
        }

        return (object) [
            'accepted' => true,
            'id' => $requestId,
            'status' => 'pending',
            'errorCode' => null,
            'errorMessage' => null,
        ];
    }

    public function getStatus(string $id): NativeDocumentPickerResult
    {
        $requestId = $this->resolveRequestId(
            $id,
            generateWhenEmpty: false,
        );

        if ($requestId === null) {
            return $this->failedResult(
                requestId: (string) Str::uuid(),
                errorCode: NativeDocumentPickerErrorCode::INVALID_REQUEST_ID,
            );
        }

        $response = $this->bridge->call(
            'NativeDocumentPicker.GetStatus',
            ['id' => $requestId],
        );

        if ($response === null) {
            return $this->failedResult(
                requestId: $requestId,
                errorCode: NativeDocumentPickerErrorCode::ACTIVITY_UNAVAILABLE,
            );
        }

        return $this->normalizeResult($requestId, $response);
    }

    private function resolveRequestId(
        mixed $requestId,
        bool $generateWhenEmpty,
    ): ?string {
        if ($requestId === null) {
            return $generateWhenEmpty
                ? (string) Str::uuid()
                : null;
        }

        if (! is_string($requestId)) {
            return null;
        }

        $requestId = trim($requestId);

        if ($requestId === '') {
            return $generateWhenEmpty
                ? (string) Str::uuid()
                : null;
        }

        if (! Str::isUuid($requestId)) {
            return null;
        }

        return strtolower($requestId);
    }

    /**
     * @return list<string>|null
     */
    private function normalizeMimeTypes(mixed $mimeTypes): ?array
    {
        if (
            ! is_array($mimeTypes) ||
            ! array_is_list($mimeTypes) ||
            $mimeTypes === [] ||
            count($mimeTypes) > self::MAX_MIME_TYPE_COUNT
        ) {
            return null;
        }

        $normalized = [];

        foreach ($mimeTypes as $mimeType) {
            if (! is_string($mimeType)) {
                return null;
            }

            $mimeType = strtolower(trim($mimeType));

            if (
                $mimeType === '' ||
                strlen($mimeType) > 127 ||
                preg_match(self::MIME_TYPE_PATTERN, $mimeType) !== 1
            ) {
                return null;
            }

            $normalized[$mimeType] = true;
        }

        return array_keys($normalized);
    }

    private function isSafePrivateStoragePath(): bool
    {
        return trim($this->privateStoragePath) !== '' &&
            ! str_contains($this->privateStoragePath, "\0") &&
            preg_match('//u', $this->privateStoragePath) === 1;
    }

    private function normalizeResult(
        string $requestId,
        object $response,
    ): NativeDocumentPickerResult {
        $payload = get_object_vars($response);
        $status = $payload['status'] ?? null;

        if ($status === 'pending') {
            return new NativeDocumentPickerResult(
                id: $requestId,
                status: 'pending',
                success: false,
                cancelled: false,
            );
        }

        $success = ($payload['success'] ?? false) === true;
        $cancelled = ($payload['cancelled'] ?? false) === true;

        if ($success && ! $cancelled) {
            $path = $this->normalizePath(
                $payload['path'] ?? null,
            );
            $originalName = $this->normalizeOriginalName(
                $payload['originalName'] ?? null,
            );
            $mimeType = $this->normalizeResultMimeType(
                $payload['mimeType'] ?? null,
            );
            $size = $this->normalizeResultSize(
                $payload['size'] ?? null,
            );

            if (
                $path === null ||
                $originalName === null ||
                $mimeType === null ||
                $size === null
            ) {
                return $this->failedResult(requestId: $requestId, errorCode: NativeDocumentPickerErrorCode::UNKNOWN_ERROR);
            }

            return new NativeDocumentPickerResult(
                id: $requestId,
                status: 'succeeded',
                success: true,
                cancelled: false,
                path: $path,
                originalName: $originalName,
                mimeType: $mimeType,
                size: $size,
            );
        }

        if ($cancelled && ! $success) {
            return new NativeDocumentPickerResult(
                id: $requestId,
                status: 'cancelled',
                success: false,
                cancelled: true,
            );
        }

        $errorCode = $this->normalizeErrorCode(
            $payload['errorCode'] ?? null,
            NativeDocumentPickerErrorCode::UNKNOWN_ERROR,
        );

        return $this->failedResult(
            requestId: $requestId,
            errorCode: $errorCode,
            status: $errorCode === NativeDocumentPickerErrorCode::RESULT_NOT_FOUND
                ? 'not_found'
                : 'failed',
        );
    }

    private function normalizeResultSize(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= self::MAX_CONFIGURABLE_SIZE ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            return null;
        }

        $maximum = (string) self::MAX_CONFIGURABLE_SIZE;

        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeResultMimeType(mixed $mimeType): ?string
    {
        if (! is_string($mimeType)) {
            return null;
        }

        $mimeType = strtolower(trim($mimeType));

        if (
            $mimeType === '' ||
            strlen($mimeType) > 127 ||
            str_contains($mimeType, '*') ||
            preg_match(self::MIME_TYPE_PATTERN, $mimeType) !== 1
        ) {
            return null;
        }

        return $mimeType;
    }

    private function normalizePath(mixed $path): ?string
    {
        if (
            ! is_string($path) ||
            $path === '' ||
            strlen($path) > self::MAX_PATH_LENGTH ||
            preg_match('//u', $path) !== 1 ||
            preg_match('/[\x00-\x1F\x7F]/u', $path) === 1
        ) {
            return null;
        }

        return $path;
    }

    private function normalizeOriginalName(mixed $value): ?string
    {
        $value = $this->normalizeText($value, 255);

        if ($value === null) {
            return null;
        }

        $value = str_replace(['/', '\\'], '_', $value);
        $value = trim($value, " .\t\n\r\0\x0B");

        if ($value === '' || $value === '.' || $value === '..') {
            return null;
        }

        return $value;
    }

    private function normalizeText(
        mixed $value,
        int $maximumLength,
    ): ?string {
        if (! is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($value === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maximumLength);
        }

        return substr($value, 0, $maximumLength);
    }

    private function normalizeErrorCode(
        mixed $errorCode,
        string $fallback,
    ): string {
        if (
            ! is_string($errorCode) ||
            ! NativeDocumentPickerErrorCode::isKnown($errorCode)
        ) {
            return $fallback;
        }

        return $errorCode;
    }

    private function rejected(
        string $requestId,
        string $errorCode,
    ): object {
        return (object) [
            'accepted' => false,
            'id' => $requestId,
            'status' => 'failed',
            'errorCode' => $errorCode,
            'errorMessage' => NativeDocumentPickerErrorCode::message(
                $errorCode,
            ),
        ];
    }

    private function failedResult(
        string $requestId,
        string $errorCode,
        string $status = 'failed',
    ): NativeDocumentPickerResult {
        return new NativeDocumentPickerResult(
            id: $requestId,
            status: $status,
            success: false,
            cancelled: false,
            errorCode: $errorCode,
            errorMessage: NativeDocumentPickerErrorCode::message(
                $errorCode,
            ),
        );
    }
}
