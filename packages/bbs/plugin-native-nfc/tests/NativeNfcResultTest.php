<?php

declare(strict_types=1);

use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Bbs\NativeNfc\NativeNfcResult;

function validNfcResultData(array $overrides = []): object
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

it('accepts a valid pending scan result', function () {
    $result = NativeNfcResult::fromNative(validNfcResultData());

    expect($result->id)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($result->operation)->toBe('start_scan')
        ->and($result->status)->toBe('pending')
        ->and($result->consumed)->toBeFalse();
});

it('accepts a valid pending write result', function () {
    $result = NativeNfcResult::fromNative(validNfcResultData([
        'operation' => 'start_write',
    ]));

    expect($result->operation)->toBe('start_write')
        ->and($result->status)->toBe('pending');
});

it('accepts a completed read result', function () {
    $result = NativeNfcResult::fromNative(validNfcResultData([
        'status' => 'read',
        'completed_at_ms' => 1790000001000,
    ]));

    expect($result->status)->toBe('read')
        ->and($result->completedAtMs)->toBe(1790000001000);
});

it('accepts a completed written result', function () {
    $result = NativeNfcResult::fromNative(validNfcResultData([
        'operation' => 'start_write',
        'status' => 'written',
        'completed_at_ms' => 1790000001000,
    ]));

    expect($result->operation)->toBe('start_write')
        ->and($result->status)->toBe('written');
});

it('accepts a not found result', function () {
    $result = NativeNfcResult::fromNative((object) [
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'operation' => '',
        'status' => 'not_found',
        'consumed' => false,
        'created_at_ms' => 0,
        'completed_at_ms' => null,
        'error_code' => null,
    ]);

    expect($result->status)->toBe('not_found')
        ->and($result->operation)->toBe('');
});

it('rejects an invalid UUID', function () {
    expect(fn () => NativeNfcResult::fromNative(validNfcResultData([
        'id' => 'invalid-id',
    ])))->toThrow(NativeNfcException::class);
});

it('rejects an invalid operation', function () {
    expect(fn () => NativeNfcResult::fromNative(validNfcResultData([
        'operation' => 'delete',
    ])))->toThrow(NativeNfcException::class);
});

it('rejects an invalid status', function () {
    expect(fn () => NativeNfcResult::fromNative(validNfcResultData([
        'status' => 'success',
    ])))->toThrow(NativeNfcException::class);
});

it('rejects an unknown error code', function () {
    expect(fn () => NativeNfcResult::fromNative(validNfcResultData([
        'status' => 'failed',
        'error_code' => 'made_up_error',
    ])))->toThrow(NativeNfcException::class);
});

it('rejects a non boolean consumed value', function () {
    expect(fn () => NativeNfcResult::fromNative(validNfcResultData([
        'consumed' => 'false',
    ])))->toThrow(NativeNfcException::class);
});

it('rejects invalid timestamps', function () {
    expect(fn () => NativeNfcResult::fromNative(validNfcResultData([
        'created_at_ms' => 'not-a-timestamp',
    ])))->toThrow(NativeNfcException::class);
});