<?php

declare(strict_types=1);

use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Bbs\NativeNfc\Support\NativeNfcRequestValidator;

it('accepts a valid scan request', function () {
    $id = '550e8400-e29b-41d4-a716-446655440000';
    expect(NativeNfcRequestValidator::validateIdRequest(['id' => $id]))->toBe(['id' => $id]);
});

it('accepts a valid write request', function () {
    $request = ['id' => '550e8400-e29b-41d4-a716-446655440000', 'text' => 'Hello NFC'];
    expect(NativeNfcRequestValidator::validateWriteRequest($request))->toBe($request);
});

it('accepts newlines and tabs in write text', function () {
    $request = ['id' => '550e8400-e29b-41d4-a716-446655440000', 'text' => "First line\nSecond line\tTabbed"];
    expect(NativeNfcRequestValidator::validateWriteRequest($request))->toBe($request);
});

it('accepts multibyte UTF-8 text within the byte limit', function () {
    $request = ['id' => '550e8400-e29b-41d4-a716-446655440000', 'text' => str_repeat('ಕ', 1365)];
    expect(strlen($request['text']))->toBeLessThanOrEqual(4096);
    expect(NativeNfcRequestValidator::validateWriteRequest($request))->toBe($request);
});

it('rejects an invalid UUID', function () {
    expect(fn () => NativeNfcRequestValidator::validateIdRequest(['id' => 'not-a-uuid']))
        ->toThrow(NativeNfcException::class);
});

it('rejects unexpected request parameters', function () {
    expect(fn () => NativeNfcRequestValidator::validateIdRequest([
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'extra' => true,
    ]))->toThrow(NativeNfcException::class);
});

it('rejects empty write text', function () {
    expect(fn () => NativeNfcRequestValidator::validateWriteRequest([
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'text' => '   ',
    ]))->toThrow(NativeNfcException::class);
});

it('rejects write text exceeding 4 KB', function () {
    expect(fn () => NativeNfcRequestValidator::validateWriteRequest([
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'text' => str_repeat('a', 4097),
    ]))->toThrow(NativeNfcException::class);
});

it('rejects a request exceeding 8 KB', function () {
    expect(fn () => NativeNfcRequestValidator::validateWriteRequest([
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'text' => str_repeat('a', 4096),
        'extra' => str_repeat('b', 5000),
    ]))->toThrow(NativeNfcException::class);
});

it('rejects invalid control characters', function () {
    expect(fn () => NativeNfcRequestValidator::validateWriteRequest([
        'id' => '550e8400-e29b-41d4-a716-446655440000',
        'text' => "Hello\x00NFC",
    ]))->toThrow(NativeNfcException::class);
});