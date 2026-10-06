<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Support;

use Bbs\NativeCalendar\Contracts\NativeBridge;
use Closure;
use Throwable;

final class NativePhpBridge implements NativeBridge
{
    private const MAX_REQUEST_BYTES = 8192;

    private const MAX_RESPONSE_BYTES = 16384;

    private const METHODS = [
        'NativeCalendar.IsAvailable',
        'NativeCalendar.CreateEvent',
        'NativeCalendar.Open',
        'NativeCalendar.GetStatus',
    ];

    public function __construct(
        private readonly ?Closure $caller = null,
    ) {}

    public function call(string $method, array $parameters = []): ?object
    {
        if (! in_array($method, self::METHODS, true)) {
            return null;
        }

        if ($this->caller === null && ! function_exists('nativephp_call')) {
            return null;
        }

        try {
            $request = json_encode(
                $parameters === [] ? (object) [] : $parameters,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                16,
            );

            if (strlen($request) > self::MAX_REQUEST_BYTES) {
                return null;
            }

            $response = $this->caller !== null
                ? ($this->caller)($method, $request)
                : nativephp_call($method, $request);

            if (
                ! is_string($response) ||
                $response === '' ||
                strlen($response) > self::MAX_RESPONSE_BYTES
            ) {
                return null;
            }

            $envelope = json_decode(
                $response,
                false,
                16,
                JSON_THROW_ON_ERROR,
            );

            if (! is_object($envelope)) {
                return null;
            }

            $payload = property_exists($envelope, 'data')
                ? $envelope->data
                : $envelope;

            return is_object($payload) ? $payload : null;
        } catch (Throwable) {
            // Never expose native exceptions or request contents.
            return null;
        }
    }
}
