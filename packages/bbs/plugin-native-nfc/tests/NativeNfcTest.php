<?php

declare(strict_types=1);

use Bbs\NativeNfc\Contracts\NativeBridge;
use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Bbs\NativeNfc\NativeNfc;
use Bbs\NativeNfc\Support\NativeNfcErrorCode;

function fakeNfcBridge(callable $handler): NativeBridge
{
    return new class($handler) implements NativeBridge {
        public array $calls = [];

        public function __construct(private readonly mixed $handler) {}

        public function call(string $method, array $parameters = []): ?object
        {
            $this->calls[] = ['method' => $method, 'parameters' => $parameters];

            return ($this->handler)($method, $parameters);
        }
    };
}

function nfcApiResult(array $overrides = []): object
{
    return (object) array_merge([
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'operation' => 'start_scan',
        'status' => 'pending',
        'consumed' => false,
        'created_at_ms' => 1790000000000,
        'completed_at_ms' => null,
        'error_code' => null,
    ], $overrides);
}

it('checks NFC availability', function () {
    $bridge = fakeNfcBridge(fn () => (object) [
        'supported' => true,
        'enabled' => true,
        'availability' => 'available',
    ]);

    $result = (new NativeNfc($bridge))->isAvailable();

    expect($result->supported)->toBeTrue()
        ->and($result->enabled)->toBeTrue()
        ->and($result->availability)->toBe('available');
});

it('starts a scan with the supplied request ID', function () {
    $id = '550e8400-e29b-41d4-a716-446655440000';

    $bridge = fakeNfcBridge(fn () => nfcApiResult());

    $result = (new NativeNfc($bridge))->startScan($id);

    expect($result->id)->toBe($id)
        ->and($result->status)->toBe('pending')
        ->and($bridge->calls[0]['method'])->toBe('NativeNfc.StartScan')
        ->and($bridge->calls[0]['parameters'])->toBe(['id' => $id]);
});

it('generates a UUID when starting a scan without an ID', function () {
    $bridge = fakeNfcBridge(fn ($method, $parameters) => nfcApiResult([
        'id' => $parameters['id'],
    ]));

    $result = (new NativeNfc($bridge))->startScan();

    expect($result->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('starts a write with the supplied text and request ID', function () {
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $bridge = fakeNfcBridge(fn () => nfcApiResult([
        'operation' => 'start_write',
    ]));

    $result = (new NativeNfc($bridge))->startWrite('Hello NFC', $id);

    expect($result->operation)->toBe('start_write')
        ->and($bridge->calls[0]['method'])->toBe('NativeNfc.StartWrite')
        ->and($bridge->calls[0]['parameters'])->toBe([
            'id' => $id,
            'text' => 'Hello NFC',
        ]);
});

it('gets session status', function () {
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $bridge = fakeNfcBridge(fn () => nfcApiResult([
        'status' => 'written',
        'operation' => 'start_write',
        'completed_at_ms' => 1790000001000,
    ]));

    $result = (new NativeNfc($bridge))->getStatus($id);

    expect($result->status)->toBe('written')
        ->and($bridge->calls[0]['method'])->toBe('NativeNfc.GetStatus');
});

it('stops a session', function () {
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $bridge = fakeNfcBridge(fn () => nfcApiResult([
        'status' => 'cancelled',
        'completed_at_ms' => 1790000001000,
    ]));

    $result = (new NativeNfc($bridge))->stopSession($id);

    expect($result->status)->toBe('cancelled')
        ->and($bridge->calls[0]['method'])->toBe('NativeNfc.StopSession');
});

it('consumes a scanned text result', function () {
    $bridge = fakeNfcBridge(fn () => (object) [
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'status' => 'read',
        'consumed' => true,
        'text' => 'Hello from NFC',
    ]);

    $text = (new NativeNfc($bridge))->consumeResult(
        '550e8400-e29b-41d4-a716-446655440000'
    );

    expect($text)->toBe('Hello from NFC')
        ->and($bridge->calls[0]['method'])->toBe('NativeNfc.ConsumeResult');
});

it('rejects an invalid request ID before calling the bridge', function () {
    $bridge = fakeNfcBridge(fn () => null);

    expect(fn () => (new NativeNfc($bridge))->getStatus('invalid-id'))
        ->toThrow(NativeNfcException::class);

    expect($bridge->calls)->toBe([]);
});

it('rejects an invalid availability response', function () {
    $bridge = fakeNfcBridge(fn () => (object) [
        'supported' => 'yes',
        'enabled' => true,
        'availability' => 'available',
    ]);

    expect(fn () => (new NativeNfc($bridge))->isAvailable())
        ->toThrow(NativeNfcException::class);
});

it('reports an unavailable native bridge', function () {
    $bridge = fakeNfcBridge(fn () => null);

    try {
        (new NativeNfc($bridge))->isAvailable();
        test()->fail('Expected NativeNfcException was not thrown');
    } catch (NativeNfcException $exception) {
        expect($exception->errorCode)->toBe(NativeNfcErrorCode::BRIDGE_UNAVAILABLE);
    }
});

it('rejects an invalid consume response', function () {
    $bridge = fakeNfcBridge(fn () => (object) [
        'status' => 'read',
        'consumed' => false,
        'text' => 'Hello NFC',
    ]);

    expect(fn () => (new NativeNfc($bridge))->consumeResult(
        '550e8400-e29b-41d4-a716-446655440000'
    ))->toThrow(NativeNfcException::class);
});