<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Support;

use Bbs\NativeContacts\Contracts\NativeBridge;
use Throwable;

final class NativePhpBridge implements NativeBridge
{
    private const MAX_REQUEST_BYTES = 8_192;

    private const MAX_RESPONSE_BYTES = 16_384;

    private const METHODS = [
        'NativeContacts.IsAvailable',
        'NativeContacts.Pick',
        'NativeContacts.Create',
        'NativeContacts.Open',
        'NativeContacts.GetStatus',
        'NativeContacts.ConsumeResult',
    ];

    public function call(string $method, array $parameters = []): ?object
    {
        if (
            ! in_array($method, self::METHODS, true) ||
            ! function_exists('nativephp_call')
        ) {
            return null;
        }

        foreach (array_keys($parameters) as $key) {
            if (! is_string($key)) {
                return null;
            }
        }

        try {
            $encoded = json_encode(
                (object) $parameters,
                JSON_THROW_ON_ERROR,
            );

            if (strlen($encoded) > self::MAX_REQUEST_BYTES) {
                return null;
            }

            $response = nativephp_call($method, $encoded);

            if (
                ! is_string($response) ||
                trim($response) === '' ||
                strlen($response) > self::MAX_RESPONSE_BYTES
            ) {
                return null;
            }

            $decoded = json_decode(
                $response,
                false,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_object($decoded)) {
            return null;
        }

        $payload = property_exists($decoded, 'data')
            ? $decoded->data
            : $decoded;

        return is_object($payload) ? $payload : null;
    }
}
