<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\FirebaseRemoteConfigDemo as Demo;
use App\Support\FirebaseRemoteConfigDemoState as State;
use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigResponse as Result;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class FirebaseRemoteConfigController extends Controller
{
    public function __construct(private readonly FirebaseRemoteConfig $native, private readonly Demo $demo,
        private readonly State $state) {}

    public function index(Request $request): Response
    {
        return response()->view('firebase-remote-config', ['remoteConfig' => $this->data($request)])
            ->withHeaders($this->headers());
    }

    public function snapshot(Request $request): JsonResponse
    {
        return $this->json($this->data($request));
    }

    public function start(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > Contract::MAX_REQUEST_BYTES) {
            return $this->error(Error::REQUEST_TOO_LARGE, 413);
        }
        $input = $request->except('_token');
        $operation = $input['operation'] ?? null;
        $id = $input['id'] ?? null;
        if (! in_array($operation, Result::OPERATIONS, true) ||
            array_diff(array_keys($input), $operation === 'Configure' ? ['id', 'operation', 'settings'] : ['id', 'operation']) !== []) {
            return $this->error(Error::INVALID_OPTIONS, 422);
        }
        if (! Contract::isRequestId($id)) { return $this->error(Error::INVALID_REQUEST_ID, 422); }
        $settings = $input['settings'] ?? null;
        if ($operation === 'Configure' && (! is_array($settings) ||
            array_diff(array_keys($settings), ['minimum_fetch_interval_seconds', 'fetch_timeout_seconds']) !== [])) {
            return $this->error(Error::INVALID_SETTINGS, 422);
        }
        $parameters = match ($operation) {
            'Configure' => ['id' => $id, 'minimum_fetch_interval_seconds' => array_key_exists('minimum_fetch_interval_seconds', $settings)
                ? $settings['minimum_fetch_interval_seconds'] : Contract::DEFAULT_FETCH_INTERVAL_SECONDS,
                'fetch_timeout_seconds' => array_key_exists('fetch_timeout_seconds', $settings)
                    ? $settings['fetch_timeout_seconds'] : Contract::DEFAULT_FETCH_TIMEOUT_SECONDS],
            'SetDefaults' => ['id' => $id, 'defaults' => Demo::DEFAULTS],
            default => ['id' => $id],
        };
        $error = Contract::validate('FirebaseRemoteConfig.'.$operation, $parameters);
        if ($error !== null) { return $this->error($error, 422); }
        // Stable input order permits replay of an HTTP request with the same ID.
        $fingerprint = hash('sha256', json_encode([$operation, $parameters], JSON_THROW_ON_ERROR));
        $owned = $this->owned($request)[$id] ?? null;
        if ($owned !== null && ($owned['operation'] !== $operation || $owned['fingerprint'] !== $fingerprint)) {
            return $this->error(Error::REQUEST_ID_CONFLICT, 409);
        }
        if (! $this->state->remember($request->session(), $this->owner($request), $id, $operation, $fingerprint)) {
            return $this->error(Error::LIMIT_EXCEEDED, 429);
        }
        $request->session()->save();
        $reply = match ($operation) {
            'Configure' => $this->native->configure(array_diff_key($parameters, ['id' => true]), $id),
            'SetDefaults' => $this->native->setDefaults(Demo::DEFAULTS, $id),
            'Fetch' => $this->native->fetch($id),
            'Activate' => $this->native->activate($id),
            'FetchAndActivate' => $this->native->fetchAndActivate($id),
        };

        return $this->record($request, $reply, $operation);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $owned = $this->owned($request)[$id] ?? null;
        if ($owned === null) { return $this->error(Error::RESULT_NOT_FOUND, 404); }

        return $this->record($request, $this->native->getStatus($id), $owned['operation']);
    }

    private function record(Request $request, object $reply, string $operation): JsonResponse
    {
        if ($reply->operation !== null && $reply->operation !== $operation) {
            $reply = Result::unconfirmed($reply->id, $operation, Error::INVALID_NATIVE_RESPONSE);
        }
        if ($reply->terminal) { $this->state->finish($request->session(), $this->owner($request), $reply->id); }
        $message = match (true) {
            $reply->status === 'unconfirmed' => 'The operation outcome is unconfirmed. Refresh this same request and current values. '.Demo::message($reply->errorCode),
            ! $reply->terminal => 'The settings operation is running. Current values remain usable.',
            $reply->status === 'unchanged' => 'No new values to activate. The current configuration is unchanged.',
            $reply->success && $operation === 'Fetch' => 'Fetch succeeded. Use Activate to apply the fetched configuration.',
            $reply->success && in_array($operation, ['Activate', 'FetchAndActivate'], true) => 'Configuration activated. New settings apply to future actions.',
            $reply->success && $operation === 'SetDefaults' => 'Local defaults registered. Cached activated values are ready to read.',
            $reply->success => 'Fetch settings applied. No fetch or activation was started.',
            default => Demo::message($reply->errorCode),
        };
        $code = match (true) {
            $reply->status === 'unconfirmed' => 503,
            $reply->status === 'rejected' => 422,
            ! $reply->terminal => 202,
            default => 200,
        };

        return $this->json([...(array) $reply, 'message' => $message], $code);
    }

    private function data(Request $request): array
    {
        $operations = [];
        foreach ($this->owned($request) as $id => $entry) {
            $operations[] = ['id' => $id, 'operation' => $entry['operation'], 'terminal' => $entry['terminal']];
        }

        return [...$this->demo->snapshot(), 'operations' => $operations];
    }

    private function owned(Request $request): array { return $this->state->read($request->session(), $this->owner($request))['operations']; }
    private function owner(Request $request): string { return (string) $request->user()->getAuthIdentifier(); }
    private function headers(): array { return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']; }
    private function json(array $data, int $status = 200): JsonResponse { return response()->json($data, $status)->withHeaders($this->headers()); }
    private function error(string $code, int $status): JsonResponse { return $this->json(['success' => false, 'errorCode' => $code, 'message' => Demo::message($code)], $status); }
}
