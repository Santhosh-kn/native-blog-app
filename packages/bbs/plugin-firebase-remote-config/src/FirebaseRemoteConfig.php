<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig;

use Bbs\FirebaseRemoteConfig\Contracts\NativeBridge;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigResponse as Response;
use Illuminate\Support\Str;
use Throwable;

final class FirebaseRemoteConfig
{
    public function __construct(private readonly NativeBridge $bridge) {}

    public function isAvailable(): object
    {
        return Response::availability($this->call('IsAvailable'));
    }

    /** @param array<string, mixed> $settings */
    public function configure(array $settings = [], ?string $id = null): object
    {
        if (array_diff(array_keys($settings), ['minimum_fetch_interval_seconds', 'fetch_timeout_seconds']) !== []) {
            return Response::rejected($id !== null && Contract::isRequestId($id) ? $id : null, 'Configure', Error::INVALID_OPTIONS);
        }

        return $this->start('Configure', [
            'minimum_fetch_interval_seconds' => array_key_exists('minimum_fetch_interval_seconds', $settings)
                ? $settings['minimum_fetch_interval_seconds'] : Contract::DEFAULT_FETCH_INTERVAL_SECONDS,
            'fetch_timeout_seconds' => array_key_exists('fetch_timeout_seconds', $settings)
                ? $settings['fetch_timeout_seconds'] : Contract::DEFAULT_FETCH_TIMEOUT_SECONDS,
        ], $id);
    }

    /** @param array<string, array{type: string, value: mixed}> $defaults */
    public function setDefaults(array $defaults, ?string $id = null): object
    {
        return $this->start('SetDefaults', ['defaults' => $defaults], $id);
    }

    /** @param array<string, string> $values */
    public function getValues(array $values): object
    {
        $parameters = ['values' => $values];
        $error = Contract::validate('FirebaseRemoteConfig.GetValues', $parameters);
        if ($error !== null) {
            return Response::valuesFailure($error);
        }

        return Response::values($this->call('GetValues', $parameters), $values);
    }

    public function getString(string $key): object { return $this->getValue($key, 'string'); }
    public function getBoolean(string $key): object { return $this->getValue($key, 'boolean'); }
    public function getInteger(string $key): object { return $this->getValue($key, 'integer'); }
    public function getDecimal(string $key): object { return $this->getValue($key, 'decimal'); }

    public function getValue(string $key, string $type): object
    {
        $result = $this->getValues([$key => $type]);
        $entry = $result->success ? $result->values->{$key} : null;

        return (object) ['success' => $result->success, 'key' => $key, 'type' => $type,
            'value' => $entry?->value, 'source' => $entry?->source, 'errorCode' => $result->errorCode];
    }

    public function fetch(?string $id = null): object { return $this->start('Fetch', [], $id); }
    public function activate(?string $id = null): object { return $this->start('Activate', [], $id); }
    public function fetchAndActivate(?string $id = null): object { return $this->start('FetchAndActivate', [], $id); }

    public function getStatus(string $id): object
    {
        if (! Contract::isRequestId($id)) {
            return Response::rejected(null, null, Error::INVALID_REQUEST_ID);
        }

        return Response::operation($this->call('GetStatus', ['id' => $id]), $id);
    }

    public function getInfo(): object
    {
        return Response::info($this->call('GetInfo'));
    }

    /** @param array<string, mixed> $parameters */
    private function start(string $operation, array $parameters, ?string $id): object
    {
        $id ??= (string) Str::uuid();
        $parameters = ['id' => $id, ...$parameters];
        $error = Contract::validate('FirebaseRemoteConfig.'.$operation, $parameters);
        if ($error !== null) {
            return Response::rejected(Contract::isRequestId($id) ? $id : null, $operation, $error);
        }

        return Response::operation($this->call($operation, $parameters), $id, $operation);
    }

    /** @param array<string, mixed> $parameters */
    private function call(string $operation, array $parameters = []): ?object
    {
        try {
            return $this->bridge->call('FirebaseRemoteConfig.'.$operation, $parameters);
        } catch (Throwable) {
            return null;
        }
    }
}
