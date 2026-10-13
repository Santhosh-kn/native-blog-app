<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;

final class RemoteConfigTestFixture
{
    public const ID = '123e4567-e89b-42d3-a456-426614174000';
    public const OTHER = '123e4567-e89b-42d3-a456-426614174001';

    public static function operation(string $operation = 'Fetch', string $status = 'pending'): object
    {
        $terminal = ! in_array($status, ['pending', 'running'], true);
        $success = in_array($status, ['succeeded', 'unchanged'], true);
        $error = match ($status) {
            'failed' => Error::FETCH_FAILED,
            'timed_out' => Error::OPERATION_TIMEOUT,
            'interrupted' => Error::PROCESS_INTERRUPTED,
            'rejected' => Error::BUSY,
            default => null,
        };

        return (object) ['id' => self::ID, 'operation' => $operation, 'accepted' => $status !== 'rejected',
            'status' => $status, 'terminal' => $terminal, 'success' => $success,
            'fetched' => $success && in_array($operation, ['Fetch', 'FetchAndActivate'], true) ? true : null,
            'activated' => $success && in_array($operation, ['Activate', 'FetchAndActivate'], true) ? $status === 'succeeded' : null,
            'startedAtMs' => $status === 'rejected' ? null : 1000,
            'finishedAtMs' => $terminal && $status !== 'rejected' ? 2000 : null,
            'throttleEndTimeMs' => null, 'errorCode' => $error];
    }

    public static function notFound(): object
    {
        return (object) ['id' => self::ID, 'operation' => null, 'accepted' => false, 'status' => 'not_found',
            'terminal' => true, 'success' => false, 'fetched' => null, 'activated' => null,
            'startedAtMs' => null, 'finishedAtMs' => null, 'throttleEndTimeMs' => null, 'errorCode' => Error::RESULT_NOT_FOUND];
    }

    public static function info(): object
    {
        return (object) ['success' => true, 'ready' => true, 'defaultsReady' => true,
            'lastFetchStatus' => 'success', 'lastSuccessfulFetchAtMs' => 1000,
            'minimumFetchIntervalSeconds' => 43200, 'fetchTimeoutSeconds' => 60,
            'activeOperationId' => null, 'sdkBusy' => false, 'errorCode' => null];
    }

    public static function values(string $type = 'string', mixed $value = 'Welcome', string $source = 'remote'): object
    {
        return (object) ['success' => true, 'values' => (object) ['example' => (object) [
            'type' => $type, 'value' => $value, 'source' => $source,
        ]], 'errorCode' => null];
    }
}
