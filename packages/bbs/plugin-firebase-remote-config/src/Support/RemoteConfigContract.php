<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Support;

use JsonException;

/** Wire contract shared with the Android implementation in the next increment. */
final class RemoteConfigContract
{
    public const MAX_REQUEST_BYTES = 8192;
    public const MAX_RESPONSE_BYTES = 16384;
    public const JSON_DEPTH = 16;
    public const MAX_PARAMETERS = 32;
    public const MAX_KEY_BYTES = 128;
    public const MAX_STRING_BYTES = 1024;
    public const MAX_SAFE_INTEGER = 9_007_199_254_740_991;
    public const DEFAULT_FETCH_INTERVAL_SECONDS = 43_200;
    public const MAX_FETCH_INTERVAL_SECONDS = 604_800;
    public const DEFAULT_FETCH_TIMEOUT_SECONDS = 60;
    public const MAX_FETCH_TIMEOUT_SECONDS = 120;
    public const TYPES = ['string', 'boolean', 'integer', 'decimal'];
    public const SOURCES = ['remote', 'default', 'static'];
    public const METHODS = [
        'FirebaseRemoteConfig.IsAvailable',
        'FirebaseRemoteConfig.Configure',
        'FirebaseRemoteConfig.SetDefaults',
        'FirebaseRemoteConfig.GetValues',
        'FirebaseRemoteConfig.Fetch',
        'FirebaseRemoteConfig.Activate',
        'FirebaseRemoteConfig.FetchAndActivate',
        'FirebaseRemoteConfig.GetStatus',
        'FirebaseRemoteConfig.GetInfo',
    ];

    private function __construct() {}

    /** @param array<string, mixed> $parameters */
    public static function validate(string $method, array $parameters): ?string
    {
        if (! in_array($method, self::METHODS, true)) {
            return RemoteConfigErrorCode::INVALID_METHOD;
        }

        try {
            $json = self::encode($parameters);
        } catch (JsonException) {
            return RemoteConfigErrorCode::INVALID_OPTIONS;
        }
        if (strlen($json) > self::MAX_REQUEST_BYTES) {
            return RemoteConfigErrorCode::REQUEST_TOO_LARGE;
        }

        $operation = substr($method, strlen('FirebaseRemoteConfig.'));
        $expected = match ($operation) {
            'IsAvailable', 'GetInfo' => [],
            'Configure' => ['id', 'minimum_fetch_interval_seconds', 'fetch_timeout_seconds'],
            'SetDefaults' => ['id', 'defaults'],
            'GetValues' => ['values'],
            default => ['id'],
        };
        if (! self::exactKeys($parameters, $expected)) {
            return RemoteConfigErrorCode::INVALID_OPTIONS;
        }
        if (in_array('id', $expected, true) && ! self::isRequestId($parameters['id'])) {
            return RemoteConfigErrorCode::INVALID_REQUEST_ID;
        }

        return match ($operation) {
            'Configure' => self::settingsError($parameters),
            'SetDefaults' => self::defaultsError($parameters['defaults']),
            'GetValues' => self::readsError($parameters['values']),
            default => null,
        };
    }

    /** @param array<string, mixed> $parameters */
    public static function encode(array $parameters): string
    {
        // PRESERVE_ZERO_FRACTION keeps a decimal default such as 1.0 a decimal.
        return json_encode(
            $parameters === [] ? (object) [] : $parameters,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            self::JSON_DEPTH,
        );
    }

    public static function isRequestId(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $value,
        ) === 1;
    }

    public static function isKey(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= self::MAX_KEY_BYTES &&
            preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $value) === 1;
    }

    public static function isTypedValue(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value) && strlen($value) <= self::MAX_STRING_BYTES &&
                preg_match('//u', $value) === 1,
            'boolean' => is_bool($value),
            'integer' => is_int($value) && $value >= -self::MAX_SAFE_INTEGER && $value <= self::MAX_SAFE_INTEGER,
            'decimal' => (is_float($value) && is_finite($value)) ||
                (is_int($value) && $value >= -self::MAX_SAFE_INTEGER && $value <= self::MAX_SAFE_INTEGER),
            default => false,
        };
    }

    /** @param array<mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    /** @param array<string, mixed> $parameters */
    private static function settingsError(array $parameters): ?string
    {
        $interval = $parameters['minimum_fetch_interval_seconds'];
        $timeout = $parameters['fetch_timeout_seconds'];

        return is_int($interval) && $interval >= 0 && $interval <= self::MAX_FETCH_INTERVAL_SECONDS &&
            is_int($timeout) && $timeout >= 1 && $timeout <= self::MAX_FETCH_TIMEOUT_SECONDS
            ? null : RemoteConfigErrorCode::INVALID_SETTINGS;
    }

    private static function collectionError(mixed $value): ?string
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return RemoteConfigErrorCode::INVALID_OPTIONS;
        }

        return count($value) <= self::MAX_PARAMETERS ? null : RemoteConfigErrorCode::LIMIT_EXCEEDED;
    }

    private static function defaultsError(mixed $defaults): ?string
    {
        if (($error = self::collectionError($defaults)) !== null) {
            return $error;
        }
        foreach ($defaults as $key => $entry) {
            if (! self::isKey($key)) {
                return RemoteConfigErrorCode::INVALID_KEY;
            }
            if (! is_array($entry) || ! self::exactKeys($entry, ['type', 'value'])) {
                return RemoteConfigErrorCode::INVALID_OPTIONS;
            }
            if (! in_array($entry['type'], self::TYPES, true)) {
                return RemoteConfigErrorCode::INVALID_TYPE;
            }
            if (! self::isTypedValue($entry['type'], $entry['value'])) {
                return RemoteConfigErrorCode::INVALID_VALUE;
            }
        }

        return null;
    }

    private static function readsError(mixed $values): ?string
    {
        if (($error = self::collectionError($values)) !== null) {
            return $error;
        }
        foreach ($values as $key => $type) {
            if (! self::isKey($key)) {
                return RemoteConfigErrorCode::INVALID_KEY;
            }
            if (! in_array($type, self::TYPES, true)) {
                return RemoteConfigErrorCode::INVALID_TYPE;
            }
        }

        return null;
    }
}
