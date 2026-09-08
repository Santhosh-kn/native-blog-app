<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Session\Store;
use Illuminate\Support\Str;

final class NativeDocumentPickerState
{
    public const DEFAULT_MAX_SIZE = 20_971_520;

    public const MAX_DEMO_SIZE = 104_857_600;

    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const REQUEST_SESSION_KEY = 'native_document_picker_requests';

    private const SELECTION_SESSION_KEY = 'native_document_picker_selection';

    private const MAX_REMEMBERED_REQUESTS = 10;

    /**
     * @param  list<string>  $mimeTypes
     */
    public function rememberRequest(
        Store $session,
        string $requestId,
        array $mimeTypes,
        int $maxSize,
    ): void {
        $requests = $session->get(self::REQUEST_SESSION_KEY, []);

        if (! is_array($requests)) {
            $requests = [];
        }

        $requests = array_filter(
            $requests,
            static fn (mixed $value): bool => is_array($value),
        );

        $requests = array_slice(
            $requests,
            -(self::MAX_REMEMBERED_REQUESTS - 1),
            null,
            true,
        );

        $requests[$requestId] = [
            'mime_types' => $mimeTypes,
            'max_size' => $maxSize,
        ];

        $session->put(self::REQUEST_SESSION_KEY, $requests);
    }

    /**
     * @return array{mime_types: list<string>, max_size: int}|null
     */
    public function ownedRequest(
        Store $session,
        string $requestId,
    ): ?array {
        $requests = $session->get(self::REQUEST_SESSION_KEY, []);

        if (! is_array($requests)) {
            return null;
        }

        return $this->normalizeRequestOptions(
            $requests[$requestId] ?? null,
        );
    }

    public function forgetRequest(
        Store $session,
        string $requestId,
    ): void {
        $requests = $session->get(self::REQUEST_SESSION_KEY, []);

        if (! is_array($requests)) {
            $session->forget(self::REQUEST_SESSION_KEY);

            return;
        }

        unset($requests[$requestId]);

        if ($requests === []) {
            $session->forget(self::REQUEST_SESSION_KEY);

            return;
        }

        $session->put(self::REQUEST_SESSION_KEY, $requests);
    }

    /**
     * @param  array{
     *     request_id: string,
     *     original_name: string,
     *     mime_type: string,
     *     size: int
     * }  $selection
     */
    public function rememberSelection(
        Store $session,
        array $selection,
    ): void {
        $session->put(self::SELECTION_SESSION_KEY, $selection);
    }

    public function forgetSelection(Store $session): void
    {
        $session->forget(self::SELECTION_SESSION_KEY);
    }

    /**
     * @return array{
     *     request_id: string,
     *     original_name: string,
     *     mime_type: string,
     *     size: int
     * }|null
     */
    public function selection(Store $session): ?array
    {
        $selection = $session->get(self::SELECTION_SESSION_KEY);

        if (! is_array($selection)) {
            if ($selection !== null) {
                $session->forget(self::SELECTION_SESSION_KEY);
            }

            return null;
        }

        $requestId = $selection['request_id'] ?? null;
        $originalName = $selection['original_name'] ?? null;
        $mimeType = $selection['mime_type'] ?? null;
        $size = $selection['size'] ?? null;

        if (
            ! is_string($requestId) ||
            ! Str::isUuid($requestId) ||
            ! is_string($originalName) ||
            trim($originalName) === '' ||
            strlen($originalName) > 255 ||
            ! is_string($mimeType) ||
            ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true) ||
            ! is_int($size) ||
            $size < 0 ||
            $size > self::MAX_DEMO_SIZE
        ) {
            $session->forget(self::SELECTION_SESSION_KEY);

            return null;
        }

        return [
            'request_id' => $requestId,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * @return array{mime_types: list<string>, max_size: int}|null
     */
    private function normalizeRequestOptions(mixed $options): ?array
    {
        if (! is_array($options)) {
            return null;
        }

        $mimeTypes = $options['mime_types'] ?? null;
        $maxSize = $options['max_size'] ?? null;

        if (
            ! is_array($mimeTypes) ||
            ! array_is_list($mimeTypes) ||
            $mimeTypes === [] ||
            count($mimeTypes) > count(self::ALLOWED_MIME_TYPES) ||
            ! is_int($maxSize) ||
            $maxSize < 1 ||
            $maxSize > self::MAX_DEMO_SIZE
        ) {
            return null;
        }

        $normalizedMimeTypes = [];

        foreach ($mimeTypes as $mimeType) {
            if (
                ! is_string($mimeType) ||
                ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
            ) {
                return null;
            }

            $normalizedMimeTypes[$mimeType] = true;
        }

        return [
            'mime_types' => array_keys($normalizedMimeTypes),
            'max_size' => $maxSize,
        ];
    }
}
