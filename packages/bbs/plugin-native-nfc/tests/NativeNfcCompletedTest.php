<?php

declare(strict_types=1);

use Bbs\NativeNfc\Events\NativeNfcCompleted;
use Bbs\NativeNfc\Support\NativeNfcErrorCode as ErrorCode;

function validNfcEvent(array $changes = []): NativeNfcCompleted
{
    return new NativeNfcCompleted(...array_replace([
        'id' => '11111111-1111-4111-8111-111111111111',
        'operation' => 'start_scan',
        'status' => 'read',
        'consumed' => false,
        'createdAtMs' => 1000,
        'completedAtMs' => 2000,
        'errorCode' => null,
    ], $changes));
}

it('accepts a completed scan event', function () {
    $event = validNfcEvent();

    expect($event->status)->toBe('read')
        ->and($event->operation)->toBe('start_scan');
});

it('accepts a completed write event', function () {
    $event = validNfcEvent([
        'operation' => 'start_write',
        'status' => 'written',
    ]);

    expect($event->status)->toBe('written');
});

it('accepts a cancelled operation', function () {
    expect(validNfcEvent([
        'status' => 'cancelled',
    ])->status)->toBe('cancelled');
});

it('accepts a failed operation with a supported error', function () {
    $event = validNfcEvent([
        'status' => 'failed',
        'errorCode' => ErrorCode::TAG_LOST,
    ]);

    expect($event->errorCode)->toBe(ErrorCode::TAG_LOST);
});

it('accepts an operation with an unknown outcome', function () {
    $event = validNfcEvent([
        'status' => 'unknown',
        'errorCode' => ErrorCode::OPERATION_INTERRUPTED,
    ]);

    expect($event->status)->toBe('unknown');
});

it('allows consumed state only for a read event', function () {
    $event = validNfcEvent(['consumed' => true]);

    expect($event->consumed)->toBeTrue();
});

it('serializes only completion metadata', function () {
    $event = validNfcEvent();
    $metadata = $event->toMetadata();

    expect($metadata)->toBe([
        'id' => '11111111-1111-4111-8111-111111111111',
        'operation' => 'start_scan',
        'status' => 'read',
        'consumed' => false,
        'createdAtMs' => 1000,
        'completedAtMs' => 2000,
        'errorCode' => null,
    ]);

    expect(json_encode($event, JSON_THROW_ON_ERROR))
        ->not->toContain('scanned text')
        ->and($metadata)->not->toHaveKey('text');
});

it('rejects invalid completion metadata', function (array $changes) {
    expect(fn () => validNfcEvent($changes))
        ->toThrow(InvalidArgumentException::class, 'Invalid NFC completion metadata.');
})->with([
    'invalid UUID' => [['id' => 'invalid-id']],
    'unsupported operation' => [['operation' => 'execute']],
    'pending status' => [['status' => 'pending']],
    'not found status' => [['status' => 'not_found']],
    'read with write operation' => [[
        'operation' => 'start_write',
        'status' => 'read',
    ]],
    'written with scan operation' => [[
        'operation' => 'start_scan',
        'status' => 'written',
    ]],
    'failed without error' => [[
        'status' => 'failed',
        'errorCode' => null,
    ]],
    'unknown with unsupported error' => [[
        'status' => 'unknown',
        'errorCode' => ErrorCode::TAG_LOST,
    ]],
    'unknown error code' => [[
        'status' => 'failed',
        'errorCode' => 'private-error',
    ]],
    'consumed write result' => [[
        'operation' => 'start_write',
        'status' => 'written',
        'consumed' => true,
    ]],
    'zero creation timestamp' => [['createdAtMs' => 0]],
    'completion before creation' => [['completedAtMs' => 999]],
]);