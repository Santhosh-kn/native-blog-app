<?php
declare(strict_types=1);

namespace Bbs\NativeNfc\Support;

use Bbs\NativeNfc\Contracts\NativeBridge;
use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Throwable;

final class NativePhpBridge implements NativeBridge
{
    private const MAX_REQUEST_BYTES = 8192;
    private const MAX_RESPONSE_BYTES = 16384;

    private const METHODS = [
        'NativeNfc.IsAvailable',
        'NativeNfc.StartScan',
        'NativeNfc.StartWrite',
        'NativeNfc.GetStatus',
        'NativeNfc.StopSession',
        'NativeNfc.ConsumeResult',
    ];

    public function call(string $method, array $parameters = []): ?object
    {
        if (!in_array($method, self::METHODS, true) || !function_exists('nativephp_call')) {
            return null;
        }

        foreach (array_keys($parameters) as $key) {
            if (!is_string($key)) {
                return null;
            }
        }

        try {
            $encoded = json_encode((object) $parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            if (strlen($encoded) > self::MAX_REQUEST_BYTES) {
                return null;
            }

            $response = nativephp_call($method, $encoded);

            if (!is_string($response) || trim($response) === '' || strlen($response) > self::MAX_RESPONSE_BYTES) {
                return null;
            }

        } catch (Throwable) {
            return null;
        }

        try {
            $decoded = json_decode($response, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new NativeNfcException(
                NativeNfcErrorCode::INVALID_NATIVE_RESPONSE,
                NativeNfcErrorCode::message(NativeNfcErrorCode::INVALID_NATIVE_RESPONSE)
            );
        }

        if (!is_object($decoded)) {
            throw new NativeNfcException(
                NativeNfcErrorCode::INVALID_NATIVE_RESPONSE,
                NativeNfcErrorCode::message(NativeNfcErrorCode::INVALID_NATIVE_RESPONSE)
            );
        }

        if (($decoded->status ?? null) === 'error') {
            $nativeCode = is_string($decoded->code ?? null) ? $decoded->code : 'UNKNOWN_ERROR';
            $message = is_string($decoded->message ?? null) ? $decoded->message : '';
            $detail = $message;

            if (str_starts_with($message, 'Function execution failed: ')) {
                $detail = substr($message, strlen('Function execution failed: '));
            } elseif (str_starts_with($message, 'Invalid parameters: ')) {
                $detail = substr($message, strlen('Invalid parameters: '));
            }

            $code = match ($detail) {
                'duplicate_request_id' => NativeNfcErrorCode::REQUEST_ID_REUSED,
                'not_found' => NativeNfcErrorCode::RESULT_NOT_FOUND,
                default => NativeNfcErrorCode::isKnown($detail)
                    ? $detail
                    : NativeNfcErrorCode::UNKNOWN_ERROR,
            };

            if (in_array($nativeCode, ['INVALID_JSON', 'INVALID_PARAMETERS'], true)) {
                $code = NativeNfcErrorCode::INVALID_PARAMETERS;
            } elseif ($nativeCode === 'UNKNOWN_ERROR') {
                $code = NativeNfcErrorCode::UNKNOWN_ERROR;
            }

            throw new NativeNfcException($code, NativeNfcErrorCode::message($code));
        }

        // NativePHP may return a direct payload or a success/data envelope.
        $payload = (($decoded->status ?? null) === 'success'
            && property_exists($decoded, 'data'))
                ? $decoded->data
                : $decoded;

        if (!is_object($payload)) {
            throw new NativeNfcException(
                NativeNfcErrorCode::INVALID_NATIVE_RESPONSE,
                NativeNfcErrorCode::message(NativeNfcErrorCode::INVALID_NATIVE_RESPONSE)
            );
        }

        return $payload;
    }
}
