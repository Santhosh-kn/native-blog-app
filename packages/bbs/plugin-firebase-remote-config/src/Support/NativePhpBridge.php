<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Support;

use Bbs\FirebaseRemoteConfig\Contracts\NativeBridge;
use Closure;
use Throwable;

/** Transport only. Operation-specific response validation belongs to the PHP API. */
final class NativePhpBridge implements NativeBridge
{
    public function __construct(private readonly ?Closure $caller = null) {}

    public function call(string $method, array $parameters = []): ?object
    {
        if ($this->caller === null && ! function_exists('nativephp_call')) {
            return null;
        }

        try {
            if (RemoteConfigContract::validate($method, $parameters) !== null) {
                return null;
            }
            $request = RemoteConfigContract::encode($parameters);
            $response = $this->caller !== null
                ? ($this->caller)($method, $request)
                : nativephp_call($method, $request);

            if (! is_string($response) || $response === '' || strlen($response) > RemoteConfigContract::MAX_RESPONSE_BYTES) {
                return null;
            }
            $envelope = json_decode($response, false, RemoteConfigContract::JSON_DEPTH, JSON_THROW_ON_ERROR);
            if (! is_object($envelope)) {
                return null;
            }
            $payload = property_exists($envelope, 'data') ? $envelope->data : $envelope;

            return is_object($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }
}
