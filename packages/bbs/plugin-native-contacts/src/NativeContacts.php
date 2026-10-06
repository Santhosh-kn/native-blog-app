<?php

declare(strict_types=1);

namespace Bbs\NativeContacts;

use Bbs\NativeContacts\Contracts\NativeBridge;
use Bbs\NativeContacts\Support\NativeContactsErrorCode;
use Bbs\NativeContacts\Support\NativeContactsRequestValidator;
use Bbs\NativeContacts\Support\NativeContactsResult;
use Illuminate\Support\Str;
use Throwable;

final class NativeContacts
{
    private const CAPABILITIES = [
        'pickContact',
        'pickPhone',
        'pickEmail',
        'create',
        'open',
    ];

    public function __construct(
        private readonly NativeBridge $bridge,
    ) {}

    public function isAvailable(): object
    {
        $response = $this->call('NativeContacts.IsAvailable');

        if ($response === null) {
            return $this->unavailable(
                NativeContactsErrorCode::BRIDGE_UNAVAILABLE,
            );
        }

        $data = get_object_vars($response);

        if (
            array_diff(array_keys($data), [
                'available', 'platform', 'apiLevel', 'minimumApiLevel',
                'capabilities', 'errorCode', 'errorMessage',
            ]) !== [] ||
            ! is_bool($data['available'] ?? null) ||
            ($data['platform'] ?? null) !== 'android' ||
            ($data['minimumApiLevel'] ?? null) !== 33 ||
            ! is_object($data['capabilities'] ?? null)
        ) {
            return $this->unavailable(
                NativeContactsErrorCode::INVALID_NATIVE_RESPONSE,
            );
        }

        $capabilities = get_object_vars($data['capabilities']);

        if (
            array_diff(array_keys($capabilities), self::CAPABILITIES) !== [] ||
            array_diff(self::CAPABILITIES, array_keys($capabilities)) !== []
        ) {
            return $this->unavailable(
                NativeContactsErrorCode::INVALID_NATIVE_RESPONSE,
            );
        }

        foreach ($capabilities as $value) {
            if (! is_bool($value)) {
                return $this->unavailable(
                    NativeContactsErrorCode::INVALID_NATIVE_RESPONSE,
                );
            }
        }

        $apiLevel = $data['apiLevel'] ?? null;
        $errorCode = $data['errorCode'] ?? null;

        if (
            ($apiLevel !== null && (! is_int($apiLevel) || $apiLevel < 0)) ||
            ($errorCode !== null && ! NativeContactsErrorCode::isKnown($errorCode)) ||
            $data['available'] !== in_array(true, $capabilities, true) ||
            ($data['available'] && (
                $apiLevel === null ||
                $apiLevel < 33 ||
                $errorCode !== null
            ))
        ) {
            return $this->unavailable(
                NativeContactsErrorCode::INVALID_NATIVE_RESPONSE,
            );
        }

        return (object) [
            'available' => $data['available'],
            'platform' => 'android',
            'apiLevel' => $apiLevel,
            'minimumApiLevel' => 33,
            'capabilities' => (object) $capabilities,
            'errorCode' => $errorCode,
            'errorMessage' => $errorCode === null
                ? null
                : NativeContactsErrorCode::message($errorCode),
        ];
    }

    /**
     * @param array{id?: mixed, mode?: mixed} $options
     */
    public function pick(array $options = []): NativeContactsResult
    {
        if (! array_key_exists('mode', $options)) {
            $options['mode'] = 'contact';
        }

        return $this->start('pick', $options);
    }

    /**
     * @param array{id?: mixed, name?: mixed, phone?: mixed, email?: mixed} $options
     */
    public function create(array $options = []): NativeContactsResult
    {
        return $this->start('create', $options);
    }

    /**
     * @param array{id?: mixed} $options
     */
    public function open(mixed $uri, array $options = []): NativeContactsResult
    {
        if (array_key_exists('uri', $options)) {
            return NativeContactsResult::failure(
                $this->safeRequestId($options['id'] ?? null),
                NativeContactsErrorCode::INVALID_PARAMETERS,
                'open',
            );
        }

        $options['uri'] = $uri;

        return $this->start('open', $options);
    }

    public function getStatus(mixed $id): NativeContactsResult
    {
        return $this->lookup('get_status', $id);
    }

    public function consumeResult(mixed $id): NativeContactsResult
    {
        return $this->lookup('consume_result', $id);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function start(string $operation, array $parameters): NativeContactsResult
    {
        if (! array_key_exists('id', $parameters)) {
            $parameters['id'] = (string) Str::uuid();
        }

        $id = $this->safeRequestId($parameters['id']);
        $mode = $operation === 'pick' &&
            in_array($parameters['mode'] ?? null, ['contact', 'phone', 'email'], true)
                ? $parameters['mode']
                : null;

        $error = NativeContactsRequestValidator::validate(
            $operation,
            $parameters,
        );

        if ($error !== null) {
            return NativeContactsResult::failure($id, $error, $operation, $mode);
        }

        $method = match ($operation) {
            'pick' => 'NativeContacts.Pick',
            'create' => 'NativeContacts.Create',
            'open' => 'NativeContacts.Open',
        };

        $response = $this->call($method, $parameters);

        if ($response === null) {
            return NativeContactsResult::failure(
                $id,
                NativeContactsErrorCode::BRIDGE_UNAVAILABLE,
                $operation,
                $mode,
            );
        }

        return NativeContactsResult::fromNative(
            response: $response,
            expectedId: $id,
            purpose: 'start',
            expectedOperation: $operation,
            expectedMode: $mode,
        );
    }

    private function lookup(string $operation, mixed $id): NativeContactsResult
    {
        $safeId = $this->safeRequestId($id);
        $parameters = ['id' => $id];

        $error = NativeContactsRequestValidator::validate(
            $operation,
            $parameters,
        );

        if ($error !== null) {
            return NativeContactsResult::failure($safeId, $error);
        }

        $method = $operation === 'get_status'
            ? 'NativeContacts.GetStatus'
            : 'NativeContacts.ConsumeResult';

        $response = $this->call($method, $parameters);

        if ($response === null) {
            return NativeContactsResult::failure(
                $safeId,
                NativeContactsErrorCode::BRIDGE_UNAVAILABLE,
            );
        }

        return NativeContactsResult::fromNative(
            response: $response,
            expectedId: $safeId,
            purpose: $operation === 'get_status' ? 'status' : 'consume',
        );
    }

    private function safeRequestId(mixed $id): string
    {
        return NativeContactsRequestValidator::isRequestId($id)
            ? $id
            : (string) Str::uuid();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function call(string $method, array $parameters = []): ?object
    {
        try {
            return $this->bridge->call($method, $parameters);
        } catch (Throwable) {
            return null;
        }
    }

    private function unavailable(string $errorCode): object
    {
        return (object) [
            'available' => false,
            'platform' => 'android',
            'apiLevel' => null,
            'minimumApiLevel' => 33,
            'capabilities' => (object) array_fill_keys(
                self::CAPABILITIES,
                false,
            ),
            'errorCode' => $errorCode,
            'errorMessage' => NativeContactsErrorCode::message($errorCode),
        ];
    }
}
