<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Support;

use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Throwable;

/** Strict response boundary. Objects returned here contain only validated fields. */
final class RemoteConfigResponse
{
    public const OPERATIONS = ['Configure', 'SetDefaults', 'Fetch', 'Activate', 'FetchAndActivate'];
    public const NATIVE_STATUSES = ['pending', 'running', 'succeeded', 'unchanged', 'failed', 'timed_out', 'interrupted', 'rejected', 'not_found'];
    public const FETCH_STATUSES = ['no_fetch_yet', 'success', 'failure', 'throttled'];

    private function __construct() {}

    public static function availability(?object $raw): object
    {
        $error = $raw === null ? Error::NATIVE_UNAVAILABLE : Error::INVALID_NATIVE_RESPONSE;
        if (! self::bounded($raw) || ! self::keys($raw, ['platform', 'available', 'errorCode']) || $raw->platform !== 'android' ||
            ! is_bool($raw->available) || ! self::error($raw->errorCode) ||
            ($raw->available && $raw->errorCode !== null) || (! $raw->available && $raw->errorCode === null)) {
            return (object) ['platform' => 'android', 'available' => false, 'errorCode' => $error];
        }

        return (object) ['platform' => 'android', 'available' => $raw->available, 'errorCode' => $raw->errorCode];
    }

    /** @param array<string, string> $expected */
    public static function values(?object $raw, array $expected): object
    {
        if (! self::bounded($raw) || ! self::keys($raw, ['success', 'values', 'errorCode']) || ! is_bool($raw->success) ||
            ! self::error($raw->errorCode) || ! is_object($raw->values)) {
            return self::valuesFailure($raw === null ? Error::NATIVE_UNAVAILABLE : Error::INVALID_NATIVE_RESPONSE);
        }
        if (! $raw->success) {
            return $raw->errorCode !== null && get_object_vars($raw->values) === []
                ? self::valuesFailure($raw->errorCode) : self::valuesFailure(Error::INVALID_NATIVE_RESPONSE);
        }
        if ($raw->errorCode !== null || ! self::keys($raw->values, array_keys($expected))) {
            return self::valuesFailure(Error::INVALID_NATIVE_RESPONSE);
        }
        $values = [];
        foreach ($expected as $key => $type) {
            $entry = $raw->values->{$key};
            if (! self::keys($entry, ['type', 'value', 'source']) || $entry->type !== $type ||
                ! Contract::isTypedValue($type, $entry->value) || ! in_array($entry->source, Contract::SOURCES, true)) {
                return self::valuesFailure(Error::INVALID_NATIVE_RESPONSE);
            }
            $value = $type === 'decimal' ? (float) $entry->value : $entry->value;
            $values[$key] = (object) ['type' => $type, 'value' => $value, 'source' => $entry->source];
        }

        return (object) ['success' => true, 'values' => (object) $values, 'errorCode' => null];
    }

    public static function valuesFailure(string $code): object
    {
        return (object) ['success' => false, 'values' => (object) [], 'errorCode' => $code];
    }

    public static function info(?object $raw): object
    {
        if (! self::bounded($raw)) {
            return self::infoFailure($raw === null ? Error::NATIVE_UNAVAILABLE : Error::INVALID_NATIVE_RESPONSE);
        }
        if (self::keys($raw, ['success', 'errorCode']) && $raw->success === false &&
            self::error($raw->errorCode) && $raw->errorCode !== null) {
            return self::infoFailure($raw->errorCode);
        }
        if (! self::keys($raw, ['success', 'ready', 'defaultsReady', 'lastFetchStatus', 'lastSuccessfulFetchAtMs',
            'minimumFetchIntervalSeconds', 'fetchTimeoutSeconds', 'activeOperationId', 'sdkBusy', 'errorCode']) ||
            $raw->success !== true || $raw->errorCode !== null || ! is_bool($raw->ready) ||
            ! is_bool($raw->defaultsReady) || ! is_bool($raw->sdkBusy) ||
            ! in_array($raw->lastFetchStatus, self::FETCH_STATUSES, true) ||
            ! self::timestamp($raw->lastSuccessfulFetchAtMs) ||
            ! self::integerRange($raw->minimumFetchIntervalSeconds, 0, Contract::MAX_FETCH_INTERVAL_SECONDS) ||
            ! self::integerRange($raw->fetchTimeoutSeconds, 1, Contract::MAX_FETCH_TIMEOUT_SECONDS) ||
            ($raw->activeOperationId !== null && ! Contract::isRequestId($raw->activeOperationId)) ||
            ($raw->activeOperationId !== null && ! $raw->sdkBusy) ||
            ($raw->defaultsReady && ! $raw->ready) ||
            ($raw->lastFetchStatus === 'success' && $raw->lastSuccessfulFetchAtMs === null)) {
            return self::infoFailure($raw === null ? Error::NATIVE_UNAVAILABLE : Error::INVALID_NATIVE_RESPONSE);
        }

        return (object) get_object_vars($raw);
    }

    public static function infoFailure(string $code): object
    {
        return (object) ['success' => false, 'errorCode' => $code];
    }

    /** $expectedOperation is null for a status query; otherwise it is enforced. */
    public static function operation(?object $raw, string $id, ?string $expectedOperation = null): object
    {
        // Runtime/persistence failure can occur after a write or SDK mutation.
        // Preserve correlation and uncertainty; never fabricate a terminal rejection.
        if (self::bounded($raw) && self::keys($raw, ['id', 'operation', 'success', 'errorCode']) &&
            $raw->id === $id && $raw->operation === $expectedOperation && $raw->success === false &&
            in_array($raw->errorCode, [Error::PERSIST_FAILED, Error::NATIVE_UNAVAILABLE,
                Error::LIMIT_EXCEEDED, Error::INVALID_NATIVE_RESPONSE], true)) {
            return self::unconfirmed($id, $expectedOperation, $raw->errorCode);
        }
        $invalid = static fn (): object => self::unconfirmed($id, $expectedOperation,
            $raw === null ? Error::NATIVE_UNAVAILABLE : Error::INVALID_NATIVE_RESPONSE);
        if (! self::bounded($raw) || ! self::keys($raw, ['id', 'operation', 'accepted', 'status', 'terminal', 'success',
            'fetched', 'activated', 'startedAtMs', 'finishedAtMs', 'throttleEndTimeMs', 'errorCode']) ||
            $raw->id !== $id || ! in_array($raw->status, self::NATIVE_STATUSES, true) ||
            ! is_bool($raw->accepted) || ! is_bool($raw->terminal) || ! is_bool($raw->success) ||
            ! self::nullableBoolean($raw->fetched) || ! self::nullableBoolean($raw->activated) ||
            ! self::timestamp($raw->startedAtMs) || ! self::timestamp($raw->finishedAtMs) ||
            ! self::timestamp($raw->throttleEndTimeMs) || ! self::error($raw->errorCode)) {
            return $invalid();
        }
        if ($raw->status === 'not_found') {
            if ($expectedOperation !== null || $raw->operation !== null || $raw->accepted || ! $raw->terminal ||
                $raw->success || $raw->errorCode !== Error::RESULT_NOT_FOUND ||
                $raw->fetched !== null || $raw->activated !== null || $raw->startedAtMs !== null ||
                $raw->finishedAtMs !== null || $raw->throttleEndTimeMs !== null) {
                return $invalid();
            }

            return (object) get_object_vars($raw);
        }
        if (! in_array($raw->operation, self::OPERATIONS, true) ||
            ($expectedOperation !== null && $raw->operation !== $expectedOperation)) {
            return $invalid();
        }
        $active = in_array($raw->status, ['pending', 'running'], true);
        $successful = in_array($raw->status, ['succeeded', 'unchanged'], true);
        $rejected = $raw->status === 'rejected';
        if ($raw->terminal === $active || $raw->success !== $successful || $raw->accepted === $rejected ||
            (($active || $successful) && $raw->errorCode !== null) ||
            (! $active && ! $successful && $raw->errorCode === null) ||
            ($active && $raw->finishedAtMs !== null) ||
            (! $rejected && $raw->status !== 'pending' && $raw->startedAtMs === null) ||
            (! $active && ! $rejected && $raw->finishedAtMs === null) ||
            ($raw->startedAtMs !== null && $raw->finishedAtMs !== null && $raw->finishedAtMs < $raw->startedAtMs) ||
            ($raw->throttleEndTimeMs !== null && $raw->errorCode !== Error::THROTTLED) ||
            ($raw->status === 'timed_out' && $raw->errorCode !== Error::OPERATION_TIMEOUT) ||
            ($raw->status === 'interrupted' && $raw->errorCode !== Error::PROCESS_INTERRUPTED) ||
            ($raw->status === 'unchanged' && ! in_array($raw->operation, ['Activate', 'FetchAndActivate'], true))) {
            return $invalid();
        }
        if ($active && ($raw->activated !== null || ($raw->fetched !== null && $raw->operation !== 'FetchAndActivate'))) {
            return $invalid();
        }
        if ($rejected && ($raw->fetched !== null || $raw->activated !== null ||
            $raw->startedAtMs !== null || $raw->finishedAtMs !== null)) {
            return $invalid();
        }
        if (in_array($raw->operation, ['Configure', 'SetDefaults'], true) && ($raw->fetched !== null || $raw->activated !== null)) {
            return $invalid();
        }
        if ($raw->operation === 'Fetch' && $raw->activated !== null) {
            return $invalid();
        }
        if ($raw->operation === 'Activate' && $raw->fetched !== null) {
            return $invalid();
        }
        if ($successful && match ($raw->operation) {
            'Fetch' => $raw->fetched !== true,
            'Activate' => $raw->activated !== ($raw->status === 'succeeded'),
            'FetchAndActivate' => $raw->fetched !== true || $raw->activated !== ($raw->status === 'succeeded'),
            default => false,
        }) {
            return $invalid();
        }
        if (! $successful && $raw->activated === true) {
            return $invalid();
        }

        return (object) get_object_vars($raw);
    }

    public static function rejected(?string $id, ?string $operation, string $code): object
    {
        return self::synthetic($id, $operation, 'rejected', true, $code);
    }

    public static function unconfirmed(string $id, ?string $operation, string $code): object
    {
        // A lost acknowledgement does not prove that the native operation never started.
        return self::synthetic($id, $operation, 'unconfirmed', false, $code);
    }

    private static function synthetic(?string $id, ?string $operation, string $status, bool $terminal, string $code): object
    {
        return (object) ['id' => $id, 'operation' => $operation, 'accepted' => false, 'status' => $status,
            'terminal' => $terminal, 'success' => false, 'fetched' => null, 'activated' => null,
            'startedAtMs' => null, 'finishedAtMs' => null, 'throttleEndTimeMs' => null, 'errorCode' => $code];
    }

    /** @param list<string> $expected */
    private static function keys(mixed $value, array $expected): bool
    {
        if (! is_object($value)) {
            return false;
        }
        $keys = array_keys(get_object_vars($value));
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    private static function error(mixed $value): bool
    {
        return $value === null || in_array($value, Error::ALL, true);
    }

    private static function timestamp(mixed $value): bool
    {
        return $value === null || self::integerRange($value, 1, Contract::MAX_SAFE_INTEGER);
    }

    private static function integerRange(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    private static function nullableBoolean(mixed $value): bool
    {
        return $value === null || is_bool($value);
    }

    private static function bounded(?object $value): bool
    {
        if ($value === null) {
            return false;
        }
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES |
                JSON_PRESERVE_ZERO_FRACTION, Contract::JSON_DEPTH);

            return strlen($encoded) <= Contract::MAX_RESPONSE_BYTES;
        } catch (Throwable) {
            return false;
        }
    }
}
