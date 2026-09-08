<?php

declare(strict_types=1);

namespace App\Support;

use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class NativeDocumentPickerFileGuard
{
    private const MAX_PATH_BYTES = 4096;

    private const MAX_ORIGINAL_NAME_BYTES = 255;

    /**
     * @param  array{mime_types: list<string>, max_size: int}  $requestOptions
     * @return array{
     *     request_id: string,
     *     original_name: string,
     *     mime_type: string,
     *     size: int
     * }|null
     */
    public function verifiedSelection(
        NativeDocumentPickerResult $result,
        string $requestId,
        array $requestOptions,
    ): ?array {
        if (! $this->validRequestOptions($requestOptions)) {
            Log::warning('Native document-picker request options rejected', [
                'request_id' => $requestId,
                'has_mime_types' => is_array(
                    $requestOptions['mime_types'] ?? null,
                ),
                'max_size_is_integer' => is_int(
                    $requestOptions['max_size'] ?? null,
                ),
            ]);

            return null;
        }

        $originalName = $this->normalizedOriginalName(
            $result->originalName,
        );

        $mimeTypeAllowed = is_string($result->mimeType) &&
            in_array(
                $result->mimeType,
                $requestOptions['mime_types'],
                true,
            );

        $sizeIsValid = is_int($result->size) &&
            $result->size >= 0 &&
            $result->size <= $requestOptions['max_size'];

        $checks = [
            'request_id_is_uuid' => Str::isUuid($requestId),
            'status_is_succeeded' => $result->status === 'succeeded',
            'success_is_true' => $result->success,
            'cancelled_is_false' => ! $result->cancelled,
            'request_ids_match' => hash_equals($requestId, $result->id),
            'has_path' => is_string($result->path),
            'original_name_is_safe' => $originalName !== null,
            'has_mime_type' => is_string($result->mimeType),
            'mime_type_is_allowed' => $mimeTypeAllowed,
            'size_is_valid' => $sizeIsValid,
            'has_no_error_code' => $result->errorCode === null,
            'has_no_error_message' => $result->errorMessage === null,
        ];

        if (in_array(false, $checks, true)) {
            Log::warning(
                'Native document-picker metadata rejected',
                [
                    'request_id' => $requestId,
                    ...$checks,
                ],
            );

            return null;
        }

        $privatePath = $this->resolvePrivateFile($result->path);

        if ($privatePath === null) {
            Log::warning('Native document-picker private file rejected', [
                'request_id' => $requestId,
            ]);

            return null;
        }

        clearstatcache(true, $privatePath);

        $actualSize = filesize($privatePath);

        if (! is_int($actualSize) || $actualSize !== $result->size) {
            Log::warning('Native document-picker byte size rejected', [
                'request_id' => $requestId,
                'native_size' => $result->size,
                'actual_size' => is_int($actualSize)
                    ? $actualSize
                    : null,
            ]);

            return null;
        }

        return [
            'request_id' => $requestId,
            'original_name' => $originalName,
            'mime_type' => $result->mimeType,
            'size' => $result->size,
        ];
    }

    /**
     * @param  array{mime_types: list<string>, max_size: int}  $requestOptions
     */
    private function validRequestOptions(array $requestOptions): bool
    {
        $mimeTypes = $requestOptions['mime_types'] ?? null;
        $maxSize = $requestOptions['max_size'] ?? null;

        if (
            ! is_array($mimeTypes) ||
            ! array_is_list($mimeTypes) ||
            $mimeTypes === [] ||
            ! is_int($maxSize) ||
            $maxSize < 1 ||
            $maxSize > NativeDocumentPickerState::MAX_DEMO_SIZE
        ) {
            return false;
        }

        foreach ($mimeTypes as $mimeType) {
            if (! is_string($mimeType) || ! in_array($mimeType, NativeDocumentPickerState::ALLOWED_MIME_TYPES, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizedOriginalName(?string $name): ?string
    {
        if (! is_string($name) || preg_match('//u', $name) !== 1) {
            return null;
        }

        $name = trim($name);

        if ($name === '' || strlen($name) > self::MAX_ORIGINAL_NAME_BYTES || in_array($name, ['.', '..'], true) || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $name) !== 0) {
            return null;
        }

        return $name;
    }

    private function resolvePrivateFile(string $path): ?string
    {
        if (
            $path === '' ||
            strlen($path) > self::MAX_PATH_BYTES ||
            str_contains($path, "\0") ||
            preg_match('//u', $path) !== 1
        ) {
            return null;
        }

        $root = realpath(
            app()->storagePath('app/native-document-picker'),
        );

        $file = realpath($path);

        $rootAvailable = is_string($root);
        $fileAvailable = is_string($file);
        $isFile = $fileAvailable && is_file($file);
        $isReadable = $fileAvailable && is_readable($file);

        if (
            ! $rootAvailable ||
            ! $fileAvailable ||
            ! $isFile ||
            ! $isReadable
        ) {
            Log::warning(
                'Native document-picker private-file guard rejected',
                [
                    'root_available' => $rootAvailable,
                    'file_available' => $fileAvailable,
                    'is_file' => $isFile,
                    'is_readable' => $isReadable,
                ],
            );

            return null;
        }

        $normalizedRoot = rtrim(
            str_replace('\\', '/', $root),
            '/',
        );

        $normalizedFile = str_replace('\\', '/', $file);

        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedRoot = strtolower($normalizedRoot);
            $normalizedFile = strtolower($normalizedFile);
        }

        $insideRoot = str_starts_with(
            $normalizedFile,
            $normalizedRoot.'/',
        );

        if (! $insideRoot) {
            Log::warning(
                'Native document-picker private-file guard rejected',
                [
                    'root_available' => true,
                    'file_available' => true,
                    'is_file' => true,
                    'is_readable' => true,
                    'inside_root' => false,
                ],
            );

            return null;
        }

        return $file;
    }
}
