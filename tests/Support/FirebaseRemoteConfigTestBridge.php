<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\FirebaseRemoteConfigDemo as Demo;
use Bbs\FirebaseRemoteConfig\Contracts\NativeBridge;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Closure;

/** Deterministic HTTP test double. It never initializes Firebase or uses a network. */
final class FirebaseRemoteConfigTestBridge implements NativeBridge
{
    public array $calls = [];
    public array $started = [];
    public array $records = [];
    public array $values = [];
    public ?Closure $reply = null;
    public array $info = ['success' => true, 'ready' => true, 'defaultsReady' => true,
        'lastFetchStatus' => 'no_fetch_yet', 'lastSuccessfulFetchAtMs' => null,
        'minimumFetchIntervalSeconds' => 43200, 'fetchTimeoutSeconds' => 60,
        'activeOperationId' => null, 'sdkBusy' => false, 'errorCode' => null];

    public function __construct()
    {
        foreach (Demo::DEFAULTS as $key => $entry) { $this->values[$key] = [...$entry, 'source' => 'default']; }
    }

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = [$method, $parameters];
        if ($this->reply !== null) { return ($this->reply)($method, $parameters); }

        return $this->respond($method, $parameters);
    }

    public function respond(string $method, array $parameters): ?object
    {
        $operation = substr($method, strlen('FirebaseRemoteConfig.'));
        if ($operation === 'GetInfo') { return (object) $this->info; }
        if ($operation === 'IsAvailable') { return (object) ['platform' => 'android', 'available' => true, 'errorCode' => null]; }
        if ($operation === 'GetValues') {
            $values = [];
            foreach ($parameters['values'] as $key => $type) { $values[$key] = (object) $this->values[$key]; }
            return (object) ['success' => true, 'values' => (object) $values, 'errorCode' => null];
        }
        $id = $parameters['id'];
        if ($operation === 'GetStatus') {
            return $this->records[$id] ?? (object) ['id' => $id, 'operation' => null, 'accepted' => false,
                'status' => 'not_found', 'terminal' => true, 'success' => false, 'fetched' => null,
                'activated' => null, 'startedAtMs' => null, 'finishedAtMs' => null,
                'throttleEndTimeMs' => null, 'errorCode' => Error::RESULT_NOT_FOUND];
        }
        if (isset($this->records[$id])) { return clone $this->records[$id]; }
        $this->started[$id] = [$operation, $parameters];
        $this->info['activeOperationId'] = $id; $this->info['sdkBusy'] = true;
        return $this->records[$id] = self::record($id, $operation);
    }

    public static function record(string $id, string $operation, string $status = 'pending', ?string $error = null): object
    {
        $active = in_array($status, ['pending', 'running'], true);
        $success = in_array($status, ['succeeded', 'unchanged'], true);
        return (object) ['id' => $id, 'operation' => $operation, 'accepted' => $status !== 'rejected',
            'status' => $status, 'terminal' => ! $active, 'success' => $success,
            'fetched' => $success && in_array($operation, ['Fetch', 'FetchAndActivate'], true) ? true : null,
            'activated' => $success && in_array($operation, ['Activate', 'FetchAndActivate'], true) ? $status === 'succeeded' : null,
            'startedAtMs' => in_array($status, ['pending', 'rejected'], true) ? null : 1000,
            'finishedAtMs' => $active || $status === 'rejected' ? null : 2000,
            'throttleEndTimeMs' => $error === Error::THROTTLED ? 3000 : null, 'errorCode' => $error];
    }

    public function finish(string $id, string $status = 'succeeded', ?string $error = null): void
    {
        $operation = $this->records[$id]->operation;
        $this->records[$id] = self::record($id, $operation, $status, $error);
        $this->info['activeOperationId'] = null; $this->info['sdkBusy'] = false;
        if ($status === 'succeeded' || $status === 'unchanged') {
            if ($operation === 'SetDefaults') { $this->info['defaultsReady'] = true; }
            if (in_array($operation, ['Fetch', 'FetchAndActivate'], true)) {
                $this->info['lastFetchStatus'] = 'success'; $this->info['lastSuccessfulFetchAtMs'] = 2000;
            }
            if ($operation === 'Configure') {
                $this->info['minimumFetchIntervalSeconds'] = $this->started[$id][1]['minimum_fetch_interval_seconds'];
                $this->info['fetchTimeoutSeconds'] = $this->started[$id][1]['fetch_timeout_seconds'];
            }
        }
    }
}
