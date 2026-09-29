<?php

declare(strict_types=1);

namespace Bbs\NativePasskeys;

use Bbs\NativePasskeys\Contracts\NativeBridge;
use Bbs\NativePasskeys\Support\NativePasskeysErrorCode;
use Bbs\NativePasskeys\Support\NativePasskeysResult;
use Illuminate\Support\Str;
use JsonException;

final class NativePasskeys
{
    public const OPERATION_CREATE = 'create';

    public const OPERATION_AUTHENTICATE = 'authenticate';

    public const MINIMUM_ANDROID_API = 28;

    public const MAX_REQUEST_JSON_BYTES = 262_144;

    public const MAX_RESPONSE_JSON_BYTES = 1_048_576;

    public function __construct(
        private readonly NativeBridge $bridge,
    ) {}

    public function isAvailable(): object
    {
        $response = $this->bridge->call(
            'NativePasskeys.IsAvailable',
        );

        if ($response === null) {
            return $this->availability(
                available: false,
                errorCode:
                    NativePasskeysErrorCode::
                        CREDENTIAL_MANAGER_UNAVAILABLE,
            );
        }

        $payload = get_object_vars($response);
        $available = ($payload['available'] ?? false) === true;
        $apiLevel = $this->normalizeInteger(
            $payload['apiLevel'] ?? null,
        );

        if ($available) {
            if (
                $apiLevel !== null &&
                $apiLevel < self::MINIMUM_ANDROID_API
            ) {
                return $this->availability(
                    available: false,
                    apiLevel: $apiLevel,
                    errorCode:
                        NativePasskeysErrorCode::
                            ANDROID_VERSION_UNSUPPORTED,
                );
            }

            return $this->availability(
                available: true,
                apiLevel: $apiLevel,
            );
        }

        return $this->availability(
            available: false,
            apiLevel: $apiLevel,
            errorCode: $this->normalizeErrorCode(
                $payload['errorCode'] ?? null,
                NativePasskeysErrorCode::
                    CREDENTIAL_MANAGER_UNAVAILABLE,
            ),
        );
    }

    public function create(
        string $requestJson,
        ?string $id = null,
    ): object {
        return $this->start(
            operation: self::OPERATION_CREATE,
            method: 'NativePasskeys.Create',
            requestJson: $requestJson,
            id: $id,
        );
    }

    public function authenticate(
        string $requestJson,
        ?string $id = null,
    ): object {
        return $this->start(
            operation: self::OPERATION_AUTHENTICATE,
            method: 'NativePasskeys.Authenticate',
            requestJson: $requestJson,
            id: $id,
        );
    }

    public function getStatus(
        string $id,
    ): NativePasskeysResult {
        return $this->resultCall(
            method: 'NativePasskeys.GetStatus',
            id: $id,
            expectCredentialResponse: false,
        );
    }

    public function consumeResult(
        string $id,
    ): NativePasskeysResult {
        return $this->resultCall(
            method: 'NativePasskeys.ConsumeResult',
            id: $id,
            expectCredentialResponse: true,
        );
    }

    public function cancel(
        string $id,
    ): NativePasskeysResult {
        return $this->resultCall(
            method: 'NativePasskeys.Cancel',
            id: $id,
            expectCredentialResponse: false,
        );
    }

    private function start(
        string $operation,
        string $method,
        string $requestJson,
        ?string $id,
    ): object {
        $requestId = $this->resolveRequestId(
            $id,
            generateWhenEmpty: true,
        );

        if ($requestId === null) {
            return $this->rejected(
                id: (string) Str::uuid(),
                operation: $operation,
                errorCode:
                    NativePasskeysErrorCode::
                        INVALID_REQUEST_ID,
            );
        }

        $requestError = $this->requestJsonError(
            requestJson: $requestJson,
            operation: $operation,
        );

        if ($requestError !== null) {
            return $this->rejected(
                id: $requestId,
                operation: $operation,
                errorCode: $requestError,
            );
        }

        $response = $this->bridge->call(
            $method,
            [
                'id' => $requestId,
                'request_json' => $requestJson,
            ],
        );

        if ($response === null) {
            return $this->rejected(
                id: $requestId,
                operation: $operation,
                errorCode:
                    NativePasskeysErrorCode::
                        ACTIVITY_UNAVAILABLE,
            );
        }

        $payload = get_object_vars($response);

        if (($payload['accepted'] ?? false) !== true) {
            return $this->rejected(
                id: $requestId,
                operation: $operation,
                errorCode: $this->normalizeErrorCode(
                    $payload['errorCode'] ?? null,
                    $operation === self::OPERATION_CREATE
                        ? NativePasskeysErrorCode::
                            CREATE_FAILED
                        : NativePasskeysErrorCode::
                            AUTHENTICATION_FAILED,
                ),
            );
        }

        if (
            ($payload['id'] ?? null) !== $requestId ||
            ($payload['operation'] ?? null) !== $operation ||
            ($payload['status'] ?? null) !== 'pending'
        ) {
            return $this->rejected(
                id: $requestId,
                operation: $operation,
                errorCode:
                    NativePasskeysErrorCode::UNKNOWN_ERROR,
            );
        }

        return (object) [
            'accepted' => true,
            'id' => $requestId,
            'operation' => $operation,
            'status' => 'pending',
            'errorCode' => null,
            'errorMessage' => null,
        ];
    }

    private function resultCall(
        string $method,
        string $id,
        bool $expectCredentialResponse,
    ): NativePasskeysResult {
        $requestId = $this->resolveRequestId(
            $id,
            generateWhenEmpty: false,
        );

        if ($requestId === null) {
            return $this->failedResult(
                id: (string) Str::uuid(),
                errorCode:
                    NativePasskeysErrorCode::
                        INVALID_REQUEST_ID,
            );
        }

        $response = $this->bridge->call(
            $method,
            ['id' => $requestId],
        );

        if ($response === null) {
            return $this->failedResult(
                id: $requestId,
                errorCode:
                    NativePasskeysErrorCode::
                        ACTIVITY_UNAVAILABLE,
            );
        }

        return $this->normalizeResult(
            id: $requestId,
            response: $response,
            expectCredentialResponse:
                $expectCredentialResponse,
        );
    }

    private function normalizeResult(
        string $id,
        object $response,
        bool $expectCredentialResponse,
    ): NativePasskeysResult {
        $payload = get_object_vars($response);

        if (($payload['id'] ?? null) !== $id) {
            return $this->failedResult(
                id: $id,
                errorCode:
                    NativePasskeysErrorCode::UNKNOWN_ERROR,
            );
        }

        $status = $payload['status'] ?? null;
        $operation = $this->normalizeOperation(
            $payload['operation'] ?? null,
        );
        $success = ($payload['success'] ?? false) === true;
        $cancelled = ($payload['cancelled'] ?? false) === true;
        $consumed = ($payload['consumed'] ?? false) === true;

        if ($status === 'pending') {
            if (
                $operation === null ||
                $success ||
                $cancelled ||
                $consumed
            ) {
                return $this->failedResult(
                    id: $id,
                    errorCode:
                        NativePasskeysErrorCode::
                            UNKNOWN_ERROR,
                );
            }

            return new NativePasskeysResult(
                id: $id,
                operation: $operation,
                status: 'pending',
                success: false,
                cancelled: false,
                consumed: false,
            );
        }

        if ($status === 'succeeded') {
            if (
                $operation === null ||
                ! $success ||
                $cancelled
            ) {
                return $this->failedResult(
                    id: $id,
                    errorCode:
                        NativePasskeysErrorCode::
                            UNKNOWN_ERROR,
                );
            }

            $responseJson = null;

            if ($expectCredentialResponse) {
                if (! $consumed) {
                    return $this->failedResult(
                        id: $id,
                        operation: $operation,
                        errorCode:
                            NativePasskeysErrorCode::
                                UNKNOWN_ERROR,
                    );
                }

                $responseJson =
                    $this->normalizeResponseJson(
                        $payload['responseJson'] ?? null,
                    );

                if ($responseJson === null) {
                    return $this->failedResult(
                        id: $id,
                        operation: $operation,
                        errorCode:
                            NativePasskeysErrorCode::
                                UNKNOWN_ERROR,
                    );
                }
            }

            return new NativePasskeysResult(
                id: $id,
                operation: $operation,
                status: 'succeeded',
                success: true,
                cancelled: false,
                consumed: $consumed,
                responseJson: $responseJson,
            );
        }

        if ($status === 'cancelled') {
            if (
                $operation === null ||
                $success ||
                ! $cancelled
            ) {
                return $this->failedResult(
                    id: $id,
                    errorCode:
                        NativePasskeysErrorCode::
                            UNKNOWN_ERROR,
                );
            }

            return new NativePasskeysResult(
                id: $id,
                operation: $operation,
                status: 'cancelled',
                success: false,
                cancelled: true,
                consumed: $consumed,
            );
        }

        $errorCode = $this->normalizeErrorCode(
            $payload['errorCode'] ?? null,
            NativePasskeysErrorCode::UNKNOWN_ERROR,
        );

        if ($status === 'not_found') {
            $errorCode =
                NativePasskeysErrorCode::RESULT_NOT_FOUND;
        } elseif ($status !== 'failed') {
            $errorCode =
                NativePasskeysErrorCode::UNKNOWN_ERROR;
        }

        return $this->failedResult(
            id: $id,
            operation: $operation,
            errorCode: $errorCode,
            status: $status === 'not_found'
                ? 'not_found'
                : 'failed',
            consumed: $consumed,
        );
    }

    private function requestJsonError(
        string $requestJson,
        string $operation,
    ): ?string {
        if (
            strlen($requestJson) >
                self::MAX_REQUEST_JSON_BYTES
        ) {
            return NativePasskeysErrorCode::
                REQUEST_JSON_TOO_LARGE;
        }

        if (
            trim($requestJson) === '' ||
            preg_match('//u', $requestJson) !== 1
        ) {
            return NativePasskeysErrorCode::
                INVALID_REQUEST_JSON;
        }

        try {
            $options = json_decode(
                $requestJson,
                false,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return NativePasskeysErrorCode::
                INVALID_REQUEST_JSON;
        }

        if (
            ! is_object($options) ||
            ! property_exists($options, 'challenge') ||
            ! is_string($options->challenge) ||
            trim($options->challenge) === ''
        ) {
            return NativePasskeysErrorCode::
                INVALID_REQUEST_JSON;
        }

        if ($operation === self::OPERATION_AUTHENTICATE) {
            return null;
        }

        if ($operation !== self::OPERATION_CREATE) {
            return NativePasskeysErrorCode::
                INVALID_OPERATION;
        }

        if (
            ! property_exists($options, 'rp') ||
            ! is_object($options->rp) ||
            ! property_exists($options, 'user') ||
            ! is_object($options->user) ||
            ! property_exists(
                $options,
                'pubKeyCredParams',
            ) ||
            ! is_array($options->pubKeyCredParams) ||
            $options->pubKeyCredParams === []
        ) {
            return NativePasskeysErrorCode::
                INVALID_REQUEST_JSON;
        }

        return null;
    }

    private function normalizeResponseJson(
        mixed $responseJson,
    ): ?string {
        if (
            ! is_string($responseJson) ||
            trim($responseJson) === '' ||
            strlen($responseJson) >
                self::MAX_RESPONSE_JSON_BYTES ||
            preg_match('//u', $responseJson) !== 1
        ) {
            return null;
        }

        try {
            $decoded = json_decode(
                $responseJson,
                false,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return null;
        }

        return is_object($decoded)
            ? $responseJson
            : null;
    }

    private function resolveRequestId(
        mixed $id,
        bool $generateWhenEmpty,
    ): ?string {
        if ($id === null) {
            return $generateWhenEmpty
                ? (string) Str::uuid()
                : null;
        }

        if (! is_string($id)) {
            return null;
        }

        $id = trim($id);

        if ($id === '') {
            return $generateWhenEmpty
                ? (string) Str::uuid()
                : null;
        }

        if (! Str::isUuid($id)) {
            return null;
        }

        return strtolower($id);
    }

    private function normalizeOperation(
        mixed $operation,
    ): ?string {
        return in_array(
            $operation,
            [
                self::OPERATION_CREATE,
                self::OPERATION_AUTHENTICATE,
            ],
            true,
        )
            ? $operation
            : null;
    }

    private function normalizeErrorCode(
        mixed $errorCode,
        string $fallback,
    ): string {
        if (
            ! is_string($errorCode) ||
            ! NativePasskeysErrorCode::isKnown(
                $errorCode,
            )
        ) {
            return $fallback;
        }

        return $errorCode;
    }

    private function normalizeInteger(
        mixed $value,
    ): ?int {
        return is_int($value) && $value >= 0
            ? $value
            : null;
    }

    private function availability(
        bool $available,
        ?int $apiLevel = null,
        ?string $errorCode = null,
    ): object {
        return (object) [
            'available' => $available,
            'platform' => 'android',
            'apiLevel' => $apiLevel,
            'minimumApiLevel' =>
                self::MINIMUM_ANDROID_API,
            'errorCode' => $errorCode,
            'errorMessage' => $errorCode === null
                ? null
                : NativePasskeysErrorCode::message(
                    $errorCode,
                ),
        ];
    }

    private function rejected(
        string $id,
        string $operation,
        string $errorCode,
    ): object {
        return (object) [
            'accepted' => false,
            'id' => $id,
            'operation' => $operation,
            'status' => 'failed',
            'errorCode' => $errorCode,
            'errorMessage' =>
                NativePasskeysErrorCode::message(
                    $errorCode,
                ),
        ];
    }

    private function failedResult(
        string $id,
        string $errorCode,
        ?string $operation = null,
        string $status = 'failed',
        bool $consumed = false,
    ): NativePasskeysResult {
        return new NativePasskeysResult(
            id: $id,
            operation: $operation,
            status: $status,
            success: false,
            cancelled: false,
            consumed: $consumed,
            errorCode: $errorCode,
            errorMessage:
                NativePasskeysErrorCode::message(
                    $errorCode,
                ),
        );
    }
}
