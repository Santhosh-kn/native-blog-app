<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RemoteConfigContractTest extends TestCase
{
    private const ID = '123e4567-e89b-42d3-a456-426614174000';

    #[DataProvider('validRequests')]
    public function test_accepts_valid_wire_requests(string $operation, array $parameters): void
    {
        self::assertNull(Contract::validate('FirebaseRemoteConfig.'.$operation, $parameters));
    }

    public static function validRequests(): array
    {
        return [
            'availability' => ['IsAvailable', []],
            'metadata' => ['GetInfo', []],
            'fetch' => ['Fetch', ['id' => self::ID]],
            'activate' => ['Activate', ['id' => self::ID]],
            'combined' => ['FetchAndActivate', ['id' => self::ID]],
            'status' => ['GetStatus', ['id' => self::ID]],
            'production settings' => ['Configure', ['id' => self::ID, 'minimum_fetch_interval_seconds' => 43200, 'fetch_timeout_seconds' => 60]],
            'development settings' => ['Configure', ['fetch_timeout_seconds' => 1, 'minimum_fetch_interval_seconds' => 0, 'id' => self::ID]],
            'maximum settings' => ['Configure', ['id' => self::ID, 'minimum_fetch_interval_seconds' => 604800, 'fetch_timeout_seconds' => 120]],
            'all default types' => ['SetDefaults', ['id' => self::ID, 'defaults' => [
                'welcome_message' => ['type' => 'string', 'value' => 'Welcome'],
                'show_media_optimizer' => ['type' => 'boolean', 'value' => true],
                'media_image_quality' => ['type' => 'integer', 'value' => 80],
                'scale' => ['type' => 'decimal', 'value' => 1.25],
            ]]],
            'all read types' => ['GetValues', ['values' => ['text' => 'string', 'flag' => 'boolean', 'quality' => 'integer', 'scale' => 'decimal']]],
            'maximum key' => ['GetValues', ['values' => [str_repeat('k', 128) => 'string']]],
            'maximum collection' => ['GetValues', ['values' => array_fill_keys(array_map(static fn (int $i): string => 'key_'.$i, range(1, 32)), 'string')]],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_rejects_invalid_wire_requests(string $method, array $parameters, string $error): void
    {
        self::assertSame($error, Contract::validate($method, $parameters));
    }

    public static function invalidRequests(): array
    {
        $method = static fn (string $operation): string => 'FirebaseRemoteConfig.'.$operation;
        $defaults = static fn (mixed $value): array => ['id' => self::ID, 'defaults' => $value];
        return [
            'unknown method' => ['FirebaseRemoteConfig.Reset', [], Error::INVALID_METHOD],
            'wrong namespace' => ['Other.Fetch', ['id' => self::ID], Error::INVALID_METHOD],
            'availability options' => [$method('IsAvailable'), ['extra' => true], Error::INVALID_OPTIONS],
            'metadata options' => [$method('GetInfo'), ['id' => self::ID], Error::INVALID_OPTIONS],
            'missing id' => [$method('Fetch'), [], Error::INVALID_OPTIONS],
            'extra option' => [$method('Fetch'), ['id' => self::ID, 'force' => true], Error::INVALID_OPTIONS],
            'numeric request key' => [$method('Fetch'), [self::ID], Error::INVALID_OPTIONS],
            'null id' => [$method('Fetch'), ['id' => null], Error::INVALID_REQUEST_ID],
            'number id' => [$method('Fetch'), ['id' => 42], Error::INVALID_REQUEST_ID],
            'nil id' => [$method('Fetch'), ['id' => '00000000-0000-0000-0000-000000000000'], Error::INVALID_REQUEST_ID],
            'uppercase id' => [$method('Fetch'), ['id' => strtoupper(self::ID)], Error::INVALID_REQUEST_ID],
            'id newline' => [$method('Fetch'), ['id' => self::ID."\n"], Error::INVALID_REQUEST_ID],
            'id invalid variant' => [$method('Fetch'), ['id' => '123e4567-e89b-42d3-7456-426614174000'], Error::INVALID_REQUEST_ID],
            'empty defaults' => [$method('SetDefaults'), $defaults([]), Error::INVALID_OPTIONS],
            'null defaults' => [$method('SetDefaults'), $defaults(null), Error::INVALID_OPTIONS],
            'object defaults' => [$method('SetDefaults'), $defaults(new stdClass), Error::INVALID_OPTIONS],
            'list defaults' => [$method('SetDefaults'), $defaults([['type' => 'string', 'value' => 'x']]), Error::INVALID_OPTIONS],
            'empty key' => [$method('SetDefaults'), $defaults(['' => ['type' => 'string', 'value' => 'x']]), Error::INVALID_KEY],
            'numeric key' => [$method('GetValues'), ['values' => [123 => 'string']], Error::INVALID_KEY],
            'oversized key' => [$method('GetValues'), ['values' => [str_repeat('k', 129) => 'string']], Error::INVALID_KEY],
            'key starts digit' => [$method('GetValues'), ['values' => ['1flag' => 'boolean']], Error::INVALID_KEY],
            'key punctuation' => [$method('GetValues'), ['values' => ['welcome.message' => 'string']], Error::INVALID_KEY],
            'key newline' => [$method('GetValues'), ['values' => ["flag\n" => 'boolean']], Error::INVALID_KEY],
            'unicode key' => [$method('GetValues'), ['values' => ['ಸ್ವಾಗತ' => 'string']], Error::INVALID_KEY],
            'entry not array' => [$method('SetDefaults'), $defaults(['text' => 'hello']), Error::INVALID_OPTIONS],
            'entry missing type' => [$method('SetDefaults'), $defaults(['text' => ['value' => 'hello']]), Error::INVALID_OPTIONS],
            'entry missing value' => [$method('SetDefaults'), $defaults(['text' => ['type' => 'string']]), Error::INVALID_OPTIONS],
            'entry extra property' => [$method('SetDefaults'), $defaults(['text' => ['type' => 'string', 'value' => 'hello', 'source' => 'remote']]), Error::INVALID_OPTIONS],
            'json is not native type' => [$method('SetDefaults'), $defaults(['text' => ['type' => 'json', 'value' => '{}']]), Error::INVALID_TYPE],
            'type null' => [$method('SetDefaults'), $defaults(['text' => ['type' => null, 'value' => 'hello']]), Error::INVALID_TYPE],
            'value wrong type' => [$method('SetDefaults'), $defaults(['flag' => ['type' => 'boolean', 'value' => 'true']]), Error::INVALID_VALUE],
            'value oversized' => [$method('SetDefaults'), $defaults(['text' => ['type' => 'string', 'value' => str_repeat('x', 1025)]]), Error::INVALID_VALUE],
            'integer unsafe' => [$method('SetDefaults'), $defaults(['count' => ['type' => 'integer', 'value' => 9_007_199_254_740_992]]), Error::INVALID_VALUE],
            'read unknown type' => [$method('GetValues'), ['values' => ['text' => 'json']], Error::INVALID_TYPE],
            'read nested type' => [$method('GetValues'), ['values' => ['text' => ['type' => 'string']]], Error::INVALID_TYPE],
            'read empty' => [$method('GetValues'), ['values' => []], Error::INVALID_OPTIONS],
            'read too many' => [$method('GetValues'), ['values' => array_fill_keys(array_map(static fn (int $i): string => 'key_'.$i, range(1, 33)), 'string')], Error::LIMIT_EXCEEDED],
            'defaults too many' => [$method('SetDefaults'), $defaults(array_fill_keys(array_map(static fn (int $i): string => 'key_'.$i, range(1, 33)), ['type' => 'integer', 'value' => 1])), Error::LIMIT_EXCEEDED],
            'request too large' => [$method('SetDefaults'), $defaults(array_fill_keys(array_map(static fn (int $i): string => 'key_'.$i, range(1, 9)), ['type' => 'string', 'value' => str_repeat('x', 1024)])), Error::REQUEST_TOO_LARGE],
            'bad utf8' => [$method('SetDefaults'), $defaults(['text' => ['type' => 'string', 'value' => "\xFF"]]), Error::INVALID_OPTIONS],
            'infinity' => [$method('SetDefaults'), $defaults(['number' => ['type' => 'decimal', 'value' => INF]]), Error::INVALID_OPTIONS],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_rejects_invalid_settings(mixed $interval, mixed $timeout): void
    {
        self::assertSame(Error::INVALID_SETTINGS, Contract::validate('FirebaseRemoteConfig.Configure', [
            'id' => self::ID, 'minimum_fetch_interval_seconds' => $interval, 'fetch_timeout_seconds' => $timeout,
        ]));
    }

    public static function invalidSettings(): array
    {
        return [
            'negative interval' => [-1, 60], 'interval above bound' => [604801, 60],
            'float interval' => [1.0, 60], 'string interval' => ['43200', 60], 'boolean interval' => [true, 60],
            'null interval' => [null, 60], 'zero timeout' => [43200, 0], 'negative timeout' => [43200, -1],
            'timeout above bound' => [43200, 121], 'float timeout' => [43200, 60.0],
            'string timeout' => [43200, '60'], 'boolean timeout' => [43200, false], 'null timeout' => [43200, null],
        ];
    }

    #[DataProvider('typedValues')]
    public function test_typed_values_have_explicit_boundaries(string $type, mixed $value, bool $valid): void
    {
        self::assertSame($valid, Contract::isTypedValue($type, $value));
    }

    public static function typedValues(): array
    {
        return [
            'empty text' => ['string', '', true], 'unicode text' => ['string', 'ಸ್ವಾಗತ', true],
            'maximum text' => ['string', str_repeat('x', 1024), true], 'oversized text' => ['string', str_repeat('x', 1025), false],
            'utf8 byte boundary' => ['string', str_repeat('é', 512), true], 'utf8 too many bytes' => ['string', str_repeat('é', 513), false],
            'invalid utf8' => ['string', "\xFF", false], 'number as string' => ['string', 1, false],
            'true boolean' => ['boolean', true, true], 'false boolean' => ['boolean', false, true],
            'string boolean' => ['boolean', 'true', false], 'integer boolean' => ['boolean', 1, false],
            'zero integer' => ['integer', 0, true], 'maximum integer' => ['integer', Contract::MAX_SAFE_INTEGER, true],
            'minimum integer' => ['integer', -Contract::MAX_SAFE_INTEGER, true],
            'integer too large' => ['integer', Contract::MAX_SAFE_INTEGER + 1, false],
            'integer too small' => ['integer', -Contract::MAX_SAFE_INTEGER - 1, false],
            'float integer' => ['integer', 80.0, false], 'string integer' => ['integer', '80', false], 'boolean integer' => ['integer', true, false],
            'decimal' => ['decimal', 1.25, true], 'whole decimal' => ['decimal', 1, true],
            'float decimal' => ['decimal', 1.0, true], 'negative decimal' => ['decimal', -1.25, true],
            'finite decimal' => ['decimal', PHP_FLOAT_MAX, true], 'nan' => ['decimal', NAN, false],
            'infinity' => ['decimal', INF, false], 'negative infinity' => ['decimal', -INF, false],
            'unsafe whole decimal' => ['decimal', Contract::MAX_SAFE_INTEGER + 1, false],
            'string decimal' => ['decimal', '1.25', false], 'boolean decimal' => ['decimal', false, false],
            'null value' => ['string', null, false], 'array value' => ['string', [], false], 'unknown type' => ['json', '{}', false],
        ];
    }

    public function test_empty_request_encodes_as_object_and_decimal_keeps_fraction(): void
    {
        self::assertSame('{}', Contract::encode([]));
        self::assertSame('{"value":1.0}', Contract::encode(['value' => 1.0]));
    }

    public function test_configure_requires_both_explicit_settings(): void
    {
        self::assertSame(Error::INVALID_OPTIONS, Contract::validate('FirebaseRemoteConfig.Configure', ['id' => self::ID]));
    }

    public function test_unencodable_resource_is_rejected(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            self::assertSame(Error::INVALID_OPTIONS, Contract::validate('FirebaseRemoteConfig.GetInfo', ['resource' => $resource]));
        } finally {
            fclose($resource);
        }
    }

    public function test_recursive_request_is_rejected(): void
    {
        $parameters = [];
        $parameters['recursive'] = &$parameters;
        self::assertSame(Error::INVALID_OPTIONS, Contract::validate('FirebaseRemoteConfig.GetInfo', $parameters));
    }
}
