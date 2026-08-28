<?php

declare(strict_types=1);

namespace Bbs\NativePrinting\Support;

use Bbs\NativePrinting\Contracts\NativeBridge;
use Throwable;

final class NativePhpBridge implements NativeBridge
{
    public function call(
        string $method,
        array $parameters = [],
    ): ?object {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        try {
            $response = nativephp_call(
                $method,
                json_encode(
                    $parameters,
                    JSON_THROW_ON_ERROR,
                ),
            );

            if (
                ! is_string($response) ||
                trim($response) === ''
            ) {
                return null;
            }

            $decoded = json_decode(
                $response,
                false,
                512,
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

        if (is_object($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            return (object) $payload;
        }

        return null;
    }
}
