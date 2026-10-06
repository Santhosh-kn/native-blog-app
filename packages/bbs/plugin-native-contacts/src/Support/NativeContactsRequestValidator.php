<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Support;

use Throwable;

final class NativeContactsRequestValidator
{
    public const MAX_REQUEST_BYTES = 8_192;
    public const MAX_NAME_CODE_POINTS = 120;
    public const MAX_PHONE_LENGTH = 64;
    public const MAX_EMAIL_LENGTH = 254;
    public const MAX_URI_LENGTH = 2_048;

    private const FIELDS = [
        'pick' => ['id', 'mode'],
        'create' => ['id', 'name', 'phone', 'email'],
        'open' => ['id', 'uri'],
        'get_status' => ['id'],
        'consume_result' => ['id'],
    ];

    /**
     * Returns a controlled error code, or null for a valid request.
     *
     * @param array<string, mixed> $parameters
     */
    public static function validate(string $operation, array $parameters): ?string
    {
        $allowed = self::FIELDS[$operation] ?? null;

        if ($allowed === null) {
            return NativeContactsErrorCode::INVALID_PARAMETERS;
        }

        foreach (array_keys($parameters) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                return NativeContactsErrorCode::INVALID_PARAMETERS;
            }
        }

        try {
            $json = json_encode((object) $parameters, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return NativeContactsErrorCode::INVALID_PARAMETERS;
        }

        if (strlen($json) > self::MAX_REQUEST_BYTES) {
            return NativeContactsErrorCode::REQUEST_TOO_LARGE;
        }

        if (! self::isRequestId($parameters['id'] ?? null)) {
            return NativeContactsErrorCode::INVALID_REQUEST_ID;
        }

        if ($operation === 'pick') {
            return in_array(
                $parameters['mode'] ?? null,
                ['contact', 'phone', 'email'],
                true,
            ) ? null : NativeContactsErrorCode::INVALID_MODE;
        }

        if ($operation === 'open') {
            return self::isContactUri($parameters['uri'] ?? null)
                ? null
                : NativeContactsErrorCode::INVALID_URI;
        }

        if ($operation === 'create') {
            foreach ([
                'name' => NativeContactsErrorCode::INVALID_NAME,
                'phone' => NativeContactsErrorCode::INVALID_PHONE,
                'email' => NativeContactsErrorCode::INVALID_EMAIL,
            ] as $field => $error) {
                if (! array_key_exists($field, $parameters)) {
                    continue;
                }

                $valid = match ($field) {
                    'name' => self::isName($parameters[$field]),
                    'phone' => self::isPhone($parameters[$field]),
                    'email' => self::isEmail($parameters[$field]),
                };

                if (! $valid) {
                    return $error;
                }
            }
        }

        return null;
    }

    public static function isRequestId(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $value,
        ) === 1;
    }

    public static function isName(mixed $value): bool
    {
        if (
            ! is_string($value) ||
            trim($value) === '' ||
            strlen($value) > self::MAX_NAME_CODE_POINTS * 4 ||
            preg_match('//u', $value) !== 1 ||
            preg_match('/[\x00-\x1F\x7F]/', $value) !== 0
        ) {
            return false;
        }

        $count = preg_match_all('/./us', $value);

        return is_int($count) && $count <= self::MAX_NAME_CODE_POINTS;
    }

    public static function isPhone(mixed $value): bool
    {
        if (
            ! is_string($value) ||
            strlen($value) > self::MAX_PHONE_LENGTH ||
            preg_match('/\A\+?[0-9(). -]+\z/D', $value) !== 1
        ) {
            return false;
        }

        $digits = preg_replace('/[^0-9]/', '', $value);
        $count = strlen($digits ?? '');

        return $count >= 3 && $count <= 15;
    }

    public static function isEmail(mixed $value): bool
    {
        if (
            ! is_string($value) ||
            strlen($value) > self::MAX_EMAIL_LENGTH ||
            substr_count($value, '@') !== 1
        ) {
            return false;
        }

        [$local, $domain] = explode('@', $value, 2);

        $localPattern = <<<'REGEX'
/\A[A-Za-z0-9!#$%&'*+\/=?^_`{|}~.-]+\z/D
REGEX;

        if (
            strlen($local) > 64 ||
            preg_match($localPattern, $local) !== 1 ||
            str_starts_with($local, '.') ||
            str_ends_with($local, '.') ||
            str_contains($local, '..')
        ) {
            return false;
        }

        $labels = explode('.', $domain);

        if (
            count($labels) < 2 ||
            preg_match('/\A[A-Za-z]{2,63}\z/D', end($labels)) !== 1
        ) {
            return false;
        }

        foreach ($labels as $label) {
            if (
                strlen($label) > 63 ||
                preg_match(
                    '/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/D',
                    $label,
                ) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    public static function isContactUri(mixed $value): bool
    {
        if (
            ! is_string($value) ||
            strlen($value) > self::MAX_URI_LENGTH ||
            preg_match('/[\x00-\x20\x7F]/', $value) !== 0 ||
            preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 0
        ) {
            return false;
        }

        $parts = parse_url($value);

        if (
            ! is_array($parts) ||
            array_diff(array_keys($parts), ['scheme', 'host', 'path']) !== [] ||
            ($parts['scheme'] ?? null) !== 'content' ||
            ($parts['host'] ?? null) !== 'com.android.contacts'
        ) {
            return false;
        }

        $path = $parts['path'] ?? '';

        if (preg_match('/\A\/contacts\/([1-9][0-9]{0,18})\z/D', $path, $match) === 1) {
            return self::isAndroidId($match[1]);
        }

        if (
            preg_match(
                '/\A\/contacts\/lookup\/([^\/]+)(?:\/([1-9][0-9]{0,18}))?\z/D',
                $path,
                $match,
            ) !== 1
        ) {
            return false;
        }

        $lookup = rawurldecode($match[1]);

        return $lookup !== '.' &&
            $lookup !== '..' &&
            preg_match('//u', $lookup) === 1 &&
            preg_match('/[\x00-\x1F\x7F]/', $lookup) === 0 &&
            (! isset($match[2]) || self::isAndroidId($match[2]));
    }

    private static function isAndroidId(string $value): bool
    {
        return strlen($value) < 19 ||
            (strlen($value) === 19 && strcmp($value, '9223372036854775807') <= 0);
    }
}
