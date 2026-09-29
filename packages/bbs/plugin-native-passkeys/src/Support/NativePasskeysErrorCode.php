<?php

declare(strict_types=1);

namespace Bbs\NativePasskeys\Support;

final class NativePasskeysErrorCode
{
    public const INVALID_REQUEST_ID =
        'INVALID_REQUEST_ID';

    public const INVALID_OPERATION =
        'INVALID_OPERATION';

    public const INVALID_REQUEST_JSON =
        'INVALID_REQUEST_JSON';

    public const REQUEST_JSON_TOO_LARGE =
        'REQUEST_JSON_TOO_LARGE';

    public const ANDROID_VERSION_UNSUPPORTED =
        'ANDROID_VERSION_UNSUPPORTED';

    public const CREDENTIAL_MANAGER_UNAVAILABLE =
        'CREDENTIAL_MANAGER_UNAVAILABLE';

    public const ACTIVITY_UNAVAILABLE =
        'ACTIVITY_UNAVAILABLE';

    public const REQUEST_BUSY =
        'REQUEST_BUSY';

    public const CREATE_FAILED =
        'CREATE_FAILED';

    public const AUTHENTICATION_FAILED =
        'AUTHENTICATION_FAILED';

    public const NO_CREDENTIAL =
        'NO_CREDENTIAL';

    public const PROVIDER_CONFIGURATION_ERROR =
        'PROVIDER_CONFIGURATION_ERROR';

    public const OPERATION_INTERRUPTED =
        'OPERATION_INTERRUPTED';

    public const RESULT_NOT_FOUND =
        'RESULT_NOT_FOUND';

    public const RESULT_NOT_TERMINAL =
        'RESULT_NOT_TERMINAL';

    public const RESULT_ALREADY_CONSUMED =
        'RESULT_ALREADY_CONSUMED';

    public const RESULT_PERSISTENCE_FAILED =
        'RESULT_PERSISTENCE_FAILED';

    public const UNKNOWN_ERROR =
        'UNKNOWN_ERROR';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::INVALID_REQUEST_ID,
            self::INVALID_OPERATION,
            self::INVALID_REQUEST_JSON,
            self::REQUEST_JSON_TOO_LARGE,
            self::ANDROID_VERSION_UNSUPPORTED,
            self::CREDENTIAL_MANAGER_UNAVAILABLE,
            self::ACTIVITY_UNAVAILABLE,
            self::REQUEST_BUSY,
            self::CREATE_FAILED,
            self::AUTHENTICATION_FAILED,
            self::NO_CREDENTIAL,
            self::PROVIDER_CONFIGURATION_ERROR,
            self::OPERATION_INTERRUPTED,
            self::RESULT_NOT_FOUND,
            self::RESULT_NOT_TERMINAL,
            self::RESULT_ALREADY_CONSUMED,
            self::RESULT_PERSISTENCE_FAILED,
            self::UNKNOWN_ERROR,
        ];
    }

    public static function isKnown(string $code): bool
    {
        return in_array(
            $code,
            self::values(),
            true,
        );
    }

    public static function message(string $code): string
    {
        return match ($code) {
            self::INVALID_REQUEST_ID =>
                'The request ID must be a valid UUID.',

            self::INVALID_OPERATION =>
                'The requested passkey operation is invalid.',

            self::INVALID_REQUEST_JSON =>
                'Valid server-generated WebAuthn request JSON is required.',

            self::REQUEST_JSON_TOO_LARGE =>
                'The WebAuthn request JSON exceeds the allowed size.',

            self::ANDROID_VERSION_UNSUPPORTED =>
                'Passkeys require Android API level 28 or newer.',

            self::CREDENTIAL_MANAGER_UNAVAILABLE =>
                'Android Credential Manager is unavailable.',

            self::ACTIVITY_UNAVAILABLE =>
                'The passkey request cannot access an Android activity.',

            self::REQUEST_BUSY =>
                'Another passkey request is already active.',

            self::CREATE_FAILED =>
                'Passkey registration did not complete.',

            self::AUTHENTICATION_FAILED =>
                'Passkey authentication did not complete.',

            self::NO_CREDENTIAL =>
                'No matching passkey credential was found.',

            self::PROVIDER_CONFIGURATION_ERROR =>
                'No compatible credential provider is configured.',

            self::OPERATION_INTERRUPTED =>
                'The passkey operation was interrupted.',

            self::RESULT_NOT_FOUND =>
                'No saved passkey result was found.',

            self::RESULT_NOT_TERMINAL =>
                'The passkey request has not completed.',

            self::RESULT_ALREADY_CONSUMED =>
                'The passkey result has already been consumed.',

            self::RESULT_PERSISTENCE_FAILED =>
                'The passkey result could not be safely persisted.',

            default =>
                'The passkey operation could not be completed.',
        };
    }

    private function __construct() {}
}
