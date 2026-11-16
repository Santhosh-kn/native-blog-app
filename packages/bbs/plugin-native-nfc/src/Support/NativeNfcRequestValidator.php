<?php

declare(strict_types=1);

namespace Bbs\NativeNfc\Support;

use Bbs\NativeNfc\Exceptions\NativeNfcException;
use JsonException;

final class NativeNfcRequestValidator
{
    private const MAX_REQUEST_BYTES = 8192;
    private const MAX_TEXT_BYTES = 4096;
    private const UUID_V4_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/D';

    /** @param array<string, mixed> $parameters
     *  @return array{id: string}
     */
    public static function validateIdRequest(array $parameters): array
    {
        self::assertExactKeys($parameters, ['id']);
        self::assertRequestSize($parameters);
        $id = $parameters['id'] ?? null;

        if (!is_string($id) || strlen($id) !== 36 || preg_match(self::UUID_V4_PATTERN, $id) !== 1) {
            self::fail(NativeNfcErrorCode::INVALID_REQUEST_ID);
        }

        return ['id' => $id];
    }

    /** @param array<string, mixed> $parameters
     *  @return array{id: string, text: string}
     */
    public static function validateWriteRequest(array $parameters): array
    {
        self::assertExactKeys($parameters, ['id', 'text']);
        self::assertRequestSize($parameters);
        $id = $parameters['id'] ?? null;
        $text = $parameters['text'] ?? null;

        if (!is_string($id) || strlen($id) !== 36 || preg_match(self::UUID_V4_PATTERN, $id) !== 1) {
            self::fail(NativeNfcErrorCode::INVALID_REQUEST_ID);
        }

        if (!is_string($text) || trim($text) === '') {
            self::fail(NativeNfcErrorCode::EMPTY_TEXT);
        }

        if (preg_match('//u', $text) !== 1) {
            self::fail(NativeNfcErrorCode::INVALID_PARAMETERS);
        }

        if (strlen($text) > self::MAX_TEXT_BYTES) {
            self::fail(NativeNfcErrorCode::TEXT_TOO_LARGE);
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            self::fail(NativeNfcErrorCode::INVALID_PARAMETERS);
        }

        return ['id' => $id, 'text' => $text];
    }

    /** @param array<string, mixed> $parameters
     *  @param list<string> $expectedKeys
     */
    private static function assertExactKeys(array $parameters, array $expectedKeys): void
    {
        $actualKeys = array_keys($parameters);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            self::fail(NativeNfcErrorCode::INVALID_PARAMETERS);
        }
    }

    /** @param array<string, mixed> $parameters */
    private static function assertRequestSize(array $parameters): void
    {
        try {
            $json = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            self::fail(NativeNfcErrorCode::INVALID_PARAMETERS);
        }

        if (strlen($json) > self::MAX_REQUEST_BYTES) {
            self::fail(NativeNfcErrorCode::REQUEST_TOO_LARGE);
        }
    }

    private static function fail(string $code): never
    {
        throw new NativeNfcException($code, NativeNfcErrorCode::message($code));
    }
}
