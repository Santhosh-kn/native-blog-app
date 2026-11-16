<?php

declare(strict_types=1);

use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Bbs\NativeNfc\Support\NativeNfcErrorCode;
use Bbs\NativeNfc\Support\NativePhpBridge;

if (!function_exists('nativephp_call')) {
    function nativephp_call(string $method, string $parameters): mixed
    {
        $GLOBALS['native_nfc_bridge_last_call'] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        if (($GLOBALS['native_nfc_bridge_throw'] ?? false) === true) {
            throw new RuntimeException('Simulated native bridge failure');
        }

        return $GLOBALS['native_nfc_bridge_response'] ?? null;
    }
}

beforeEach(function () {
    $GLOBALS['native_nfc_bridge_response'] = null;
    $GLOBALS['native_nfc_bridge_throw'] = false;
    $GLOBALS['native_nfc_bridge_last_call'] = null;
});

it('extracts a successful native response payload', function () {
    $GLOBALS['native_nfc_bridge_response'] = json_encode([
        'status' => 'success',
        'data' => ['supported' => true, 'enabled' => true],
    ]);

    $result = (new NativePhpBridge())->call('NativeNfc.IsAvailable');

    expect($result)->toBeObject()
        ->and($result->supported)->toBeTrue()
        ->and($result->enabled)->toBeTrue();
});

it('sends the correct method and JSON parameters to native code', function () {
    $GLOBALS['native_nfc_bridge_response'] = '{"status":"success","data":{"id":"550e8400-e29b-41d4-a716-446655440000"}}';

    (new NativePhpBridge())->call('NativeNfc.GetStatus', [
        'id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    expect($GLOBALS['native_nfc_bridge_last_call']['method'])->toBe('NativeNfc.GetStatus')
        ->and(json_decode($GLOBALS['native_nfc_bridge_last_call']['parameters'], true))
        ->toBe(['id' => '550e8400-e29b-41d4-a716-446655440000']);
});

it('maps invalid native parameters to the plugin error code', function () {
    $GLOBALS['native_nfc_bridge_response'] = json_encode([
        'status' => 'error',
        'code' => 'INVALID_PARAMETERS',
        'message' => 'Invalid parameters: invalid_request_id',
    ]);

    try {
        (new NativePhpBridge())->call('NativeNfc.StartScan', []);
        test()->fail('Expected NativeNfcException was not thrown');
    } catch (NativeNfcException $exception) {
        expect($exception->errorCode)->toBe(NativeNfcErrorCode::INVALID_PARAMETERS);
    }
});

it('maps operation busy errors correctly', function () {
    $GLOBALS['native_nfc_bridge_response'] = json_encode([
        'status' => 'error',
        'code' => 'EXECUTION_FAILED',
        'message' => 'Function execution failed: operation_busy',
    ]);

    try {
        (new NativePhpBridge())->call('NativeNfc.StartScan', []);
        test()->fail('Expected NativeNfcException was not thrown');
    } catch (NativeNfcException $exception) {
        expect($exception->errorCode)->toBe(NativeNfcErrorCode::OPERATION_BUSY);
    }
});

it('maps duplicate request IDs correctly', function () {
    $GLOBALS['native_nfc_bridge_response'] = json_encode([
        'status' => 'error',
        'code' => 'EXECUTION_FAILED',
        'message' => 'Function execution failed: duplicate_request_id',
    ]);

    try {
        (new NativePhpBridge())->call('NativeNfc.StartScan', []);
        test()->fail('Expected NativeNfcException was not thrown');
    } catch (NativeNfcException $exception) {
        expect($exception->errorCode)->toBe(NativeNfcErrorCode::REQUEST_ID_REUSED);
    }
});

it('maps not found results correctly', function () {
    $GLOBALS['native_nfc_bridge_response'] = json_encode([
        'status' => 'error',
        'code' => 'EXECUTION_FAILED',
        'message' => 'Function execution failed: not_found',
    ]);

    try {
        (new NativePhpBridge())->call('NativeNfc.GetStatus', []);
        test()->fail('Expected NativeNfcException was not thrown');
    } catch (NativeNfcException $exception) {
        expect($exception->errorCode)->toBe(NativeNfcErrorCode::RESULT_NOT_FOUND);
    }
});

it('rejects malformed native JSON', function () {
    $GLOBALS['native_nfc_bridge_response'] = '{"status":';

    expect(fn () => (new NativePhpBridge())->call('NativeNfc.IsAvailable'))
        ->toThrow(NativeNfcException::class);
});

it('rejects an unknown bridge method without calling native code', function () {
    $result = (new NativePhpBridge())->call('NativeNfc.Unknown');

    expect($result)->toBeNull()
        ->and($GLOBALS['native_nfc_bridge_last_call'])->toBeNull();
});

it('returns null when the native bridge throws a transport exception', function () {
    $GLOBALS['native_nfc_bridge_throw'] = true;

    expect((new NativePhpBridge())->call('NativeNfc.IsAvailable'))->toBeNull();
});

it('returns null when the native response is not a string', function () {
    $GLOBALS['native_nfc_bridge_response'] = ['status' => 'success'];

    expect((new NativePhpBridge())->call('NativeNfc.IsAvailable'))->toBeNull();
});

it('rejects a successful response with a non-object payload', function () {
    $GLOBALS['native_nfc_bridge_response'] = '{"status":"success","data":[]}';

    expect(fn () => (new NativePhpBridge())->call('NativeNfc.IsAvailable'))
        ->toThrow(NativeNfcException::class);
});