<?php

declare(strict_types=1);

namespace Bbs\NativeBackgroundTransfer;

use Bbs\NativeBackgroundTransfer\Contracts\NativeBridge;
use Bbs\NativeBackgroundTransfer\Support\NativeBackgroundTransferErrorCode;
use Bbs\NativeBackgroundTransfer\Support\NativeBackgroundTransferResult;
use Illuminate\Support\Str;

final class NativeBackgroundTransfer
{
    public const DEFAULT_MAX_SIZE = 104_857_600;

    public const MAX_CONFIGURABLE_SIZE = 1_073_741_824;

    public const DEFAULT_MIME_TYPES = [
        'application/pdf',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAX_URL_LENGTH = 4096;

    private const MAX_MIME_TYPE_COUNT = 32;

    private const MIME_TYPE_PATTERN =
        '/\A[a-z0-9][a-z0-9!#$&^_.+%-]*\/(?:\*|[a-z0-9][a-z0-9!#$&^_.+%-]*)\z/D';

    private const VALID_STATUSES = [
        'queued',
        'running',
        'succeeded',
        'failed',
        'cancelled',
    ];

    public function __construct(
        private readonly NativeBridge $bridge,
    ) {}

    /**
     * @param array{
     *     id?: string|null,
     *     url?: mixed,
     *     mime_types?: mixed,
     *     max_size?: mixed
     * } $options
     */
    public function startDownload(array $options): object
    {
        $requestId = $this->resolveRequestId(
            $options['id'] ?? null,
            generateWhenEmpty: true,
        );

        if ($requestId === null) {
            return $this->rejected(
                id: (string) Str::uuid(),
                errorCode: NativeBackgroundTransferErrorCode::INVALID_REQUEST_ID,
            );
        }

        $urlValidation = $this->validateHttpsUrl(
            $options['url'] ?? null,
        );

        if ($urlValidation['url'] === null) {
            return $this->rejected(
                id: $requestId,
                errorCode: $urlValidation['errorCode'],
            );
        }

        $mimeTypes = array_key_exists('mime_types', $options)
            ? $this->normalizeMimeTypes($options['mime_types'])
            : self::DEFAULT_MIME_TYPES;

        if ($mimeTypes === null) {
            return $this->rejected(
                id: $requestId,
                errorCode: NativeBackgroundTransferErrorCode::INVALID_MIME_TYPE,
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
                id: $requestId,
                errorCode: NativeBackgroundTransferErrorCode::INVALID_MAX_SIZE,
            );
        }

        $response = $this->bridge->call(
            'NativeBackgroundTransfer.StartDownload',
            [
                'id' => $requestId,
                'url' => $urlValidation['url'],
                'mime_types' => $mimeTypes,
                'max_size' => $maxSize,
            ],
        );

        if ($response === null) {
            return $this->rejected(
                id: $requestId,
                errorCode: NativeBackgroundTransferErrorCode::SCHEDULER_UNAVAILABLE,
            );
        }

        $payload = get_object_vars($response);

        if (($payload['accepted'] ?? false) !== true) {
            return $this->rejected(
                id: $requestId,
                errorCode: $this->normalizeErrorCode(
                    $payload['errorCode']
                        ?? $payload['error_code']
                        ?? null,
                    NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
                ),
            );
        }

        $responseId = $this->resolveRequestId(
            $payload['id'] ?? null,
            generateWhenEmpty: false,
        );

        if ($responseId !== $requestId) {
            return $this->rejected(
                id: $requestId,
                errorCode: NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
            );
        }

        $status = $payload['status'] ?? null;

        if (! is_string($status) || ! in_array($status, ['queued', 'running'], true)) {
            return $this->rejected(
                id: $requestId,
                errorCode: NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
            );
        }

        return (object) [
            'accepted' => true,
            'id' => $requestId,
            'type' => 'download',
            'status' => $status,
            'errorCode' => null,
            'errorMessage' => null,
        ];
    }

    public function getStatus(string $id): NativeBackgroundTransferResult
    {
        return $this->requestResult(
            method: 'NativeBackgroundTransfer.GetStatus',
            id: $id,
        );
    }

    /**
     * @return list<NativeBackgroundTransferResult>
     */
    public function listTransfers(): array
    {
        $response = $this->bridge->call(
            'NativeBackgroundTransfer.ListTransfers',
        );

        if ($response === null) {
            return [];
        }

        $payload = get_object_vars($response);
        $items = $payload['transfers'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $item = (object) $item;
            }

            if (! is_object($item)) {
                continue;
            }

            $id = $this->resolveRequestId(
                get_object_vars($item)['id'] ?? null,
                generateWhenEmpty: false,
            );

            if ($id === null) {
                continue;
            }

            $result = $this->normalizeResult(
                expectedId: $id,
                response: $item,
            );

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    public function cancel(string $id): NativeBackgroundTransferResult
    {
        return $this->requestResult(
            method: 'NativeBackgroundTransfer.Cancel',
            id: $id,
        );
    }

    public function consumeResult(string $id): NativeBackgroundTransferResult
    {
        return $this->requestResult(
            method: 'NativeBackgroundTransfer.ConsumeResult',
            id: $id,
        );
    }

    private function requestResult(
        string $method,
        string $id,
    ): NativeBackgroundTransferResult {
        $requestId = $this->resolveRequestId(
            $id,
            generateWhenEmpty: false,
        );

        if ($requestId === null) {
            return $this->failedResult(
                id: (string) Str::uuid(),
                errorCode: NativeBackgroundTransferErrorCode::INVALID_REQUEST_ID,
            );
        }

        $response = $this->bridge->call(
            $method,
            ['id' => $requestId],
        );

        if ($response === null) {
            return $this->failedResult(
                id: $requestId,
                errorCode: NativeBackgroundTransferErrorCode::SCHEDULER_UNAVAILABLE,
            );
        }

        return $this->normalizeResult(
            expectedId: $requestId,
            response: $response,
        ) ?? $this->failedResult(
            id: $requestId,
            errorCode: NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
        );
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
     * @return array{url: ?string, errorCode: string}
     */
    private function validateHttpsUrl(mixed $value): array
    {
        if (! is_string($value)) {
            return [
                'url' => null,
                'errorCode' => NativeBackgroundTransferErrorCode::INVALID_URL,
            ];
        }

        $url = trim($value);

        if (
            $url === '' ||
            strlen($url) > self::MAX_URL_LENGTH ||
            preg_match('//u', $url) !== 1 ||
            preg_match('/[\x00-\x20\x7F]/', $url) === 1
        ) {
            return [
                'url' => null,
                'errorCode' => NativeBackgroundTransferErrorCode::INVALID_URL,
            ];
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return [
                'url' => null,
                'errorCode' => NativeBackgroundTransferErrorCode::INVALID_URL,
            ];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'https') {
            return [
                'url' => null,
                'errorCode' => NativeBackgroundTransferErrorCode::HTTPS_REQUIRED,
            ];
        }

        if (
            ! isset($parts['host']) ||
            trim((string) $parts['host']) === '' ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['fragment'])
        ) {
            return [
                'url' => null,
                'errorCode' => NativeBackgroundTransferErrorCode::INVALID_URL,
            ];
        }

        if (
            isset($parts['port']) &&
            (
                ! is_int($parts['port']) ||
                $parts['port'] < 1 ||
                $parts['port'] > 65535
            )
        ) {
            return [
                'url' => null,
                'errorCode' => NativeBackgroundTransferErrorCode::INVALID_URL,
            ];
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [
                'url' => null,
                'errorCode' => NativeBackgroundTransferErrorCode::INVALID_URL,
            ];
        }

        return [
            'url' => $url,
            'errorCode' => NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
        ];
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

    private function normalizeResult(
        string $expectedId,
        object $response,
    ): ?NativeBackgroundTransferResult {
        $payload = get_object_vars($response);

        $id = $this->resolveRequestId(
            $payload['id'] ?? null,
            generateWhenEmpty: false,
        );

        if ($id !== $expectedId) {
            return null;
        }

        $type = $payload['type'] ?? null;

        if ($type !== 'download') {
            return null;
        }

        $status = $payload['status'] ?? null;

        if (
            ! is_string($status) ||
            ! in_array($status, self::VALID_STATUSES, true)
        ) {
            return null;
        }

        $transferredBytes = $this->normalizeByteCount(
            $payload['transferredBytes']
                ?? $payload['transferred_bytes']
                ?? 0,
        );

        if ($transferredBytes === null) {
            return null;
        }

        $totalBytes = $this->normalizeNullableByteCount(
            $payload['totalBytes']
                ?? $payload['total_bytes']
                ?? null,
        );

        if (
            $totalBytes !== null &&
            $transferredBytes > $totalBytes
        ) {
            return null;
        }

        $progress = $this->normalizeProgress(
            $payload['progress'] ?? null,
        );

        if (
            $totalBytes !== null &&
            $totalBytes > 0 &&
            $progress !== intdiv($transferredBytes * 100, $totalBytes)
        ) {
            return null;
        }

        $fileId = $this->normalizeOptionalUuid(
            $payload['fileId']
                ?? $payload['file_id']
                ?? null,
        );

        $displayName = $this->normalizeOptionalText(
            $payload['displayName']
                ?? $payload['display_name']
                ?? null,
            255,
        );

        $mimeType = $this->normalizeOptionalMimeType(
            $payload['mimeType']
                ?? $payload['mime_type']
                ?? null,
        );

        $size = $this->normalizeNullableByteCount(
            $payload['size'] ?? null,
        );

        $errorCode = $payload['errorCode']
            ?? $payload['error_code']
            ?? null;

        $consumed = ($payload['consumed'] ?? false) === true;

        if ($status === 'succeeded') {
            if (
                $fileId === null ||
                $displayName === null ||
                $mimeType === null ||
                $size === null ||
                $size !== $transferredBytes
            ) {
                return null;
            }

            $errorCode = null;
        } elseif ($status === 'failed') {
            $errorCode = $this->normalizeErrorCode(
                $errorCode,
                NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
            );
        } else {
            $errorCode = null;
        }

        return new NativeBackgroundTransferResult(
            id: $id,
            type: 'download',
            status: $status,
            transferredBytes: $transferredBytes,
            totalBytes: $totalBytes,
            progress: $progress,
            fileId: $fileId,
            displayName: $displayName,
            mimeType: $mimeType,
            size: $size,
            errorCode: $errorCode,
            errorMessage: $errorCode === null
                ? null
                : NativeBackgroundTransferErrorCode::message($errorCode),
            consumed: $consumed,
        );
    }

    private function normalizeByteCount(mixed $value): ?int
    {
        if (! is_int($value)) {
            return null;
        }

        if (
            $value < 0 ||
            $value > self::MAX_CONFIGURABLE_SIZE
        ) {
            return null;
        }

        return $value;
    }

    private function normalizeNullableByteCount(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->normalizeByteCount($value);
    }

    private function normalizeProgress(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (
            ! is_int($value) ||
            $value < 0 ||
            $value > 100
        ) {
            return null;
        }

        return $value;
    }

    private function normalizeOptionalUuid(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->resolveRequestId(
            $value,
            generateWhenEmpty: false,
        );
    }

    private function normalizeOptionalMimeType(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (
            $value === '' ||
            strlen($value) > 127 ||
            str_contains($value, '*') ||
            preg_match(self::MIME_TYPE_PATTERN, $value) !== 1
        ) {
            return null;
        }

        return $value;
    }

    private function normalizeOptionalText(
        mixed $value,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (
            ! is_string($value) ||
            preg_match('//u', $value) !== 1
        ) {
            return null;
        }

        $value = preg_replace(
            '/[\x00-\x1F\x7F]+/u',
            ' ',
            $value,
        );

        if (! is_string($value)) {
            return null;
        }

        $value = trim(
            preg_replace('/\s+/u', ' ', $value) ?? '',
        );

        if ($value === '') {
            return null;
        }

        $value = str_replace(
            ['/', '\\'],
            '_',
            $value,
        );

        if (function_exists('mb_substr')) {
            return mb_substr(
                $value,
                0,
                $maximumLength,
            );
        }

        return substr(
            $value,
            0,
            $maximumLength,
        );
    }

    private function normalizeErrorCode(
        mixed $errorCode,
        string $fallback,
    ): string {
        if (
            ! is_string($errorCode) ||
            ! NativeBackgroundTransferErrorCode::isKnown($errorCode)
        ) {
            return $fallback;
        }

        return $errorCode;
    }

    private function rejected(
        string $id,
        string $errorCode,
    ): object {
        return (object) [
            'accepted' => false,
            'id' => $id,
            'type' => 'download',
            'status' => 'failed',
            'errorCode' => $errorCode,
            'errorMessage' =>
                NativeBackgroundTransferErrorCode::message(
                    $errorCode,
                ),
        ];
    }

    private function failedResult(
        string $id,
        string $errorCode,
    ): NativeBackgroundTransferResult {
        return new NativeBackgroundTransferResult(
            id: $id,
            type: 'download',
            status: 'failed',
            errorCode: $errorCode,
            errorMessage:
                NativeBackgroundTransferErrorCode::message(
                    $errorCode,
                ),
        );
    }
}