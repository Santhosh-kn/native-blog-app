<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Support\NativePhpBridge;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativePhpBridgeTest extends TestCase
{
    public function test_wraps_empty_parameters_as_json_object(): void
    {
        $bridge = new NativePhpBridge(static function (string $method, string $request): string {
            self::assertSame('FirebaseRemoteConfig.IsAvailable', $method);
            self::assertSame('{}', $request);

            return '{"data":{"available":true}}';
        });
        self::assertTrue($bridge->call('FirebaseRemoteConfig.IsAvailable')->available);
    }

    public function test_unwraps_native_envelope_without_modifying_types(): void
    {
        $bridge = new NativePhpBridge(static fn (): string => '{"data":{"flag":false,"count":80,"scale":1.25,"source":"remote"}}');
        $result = $bridge->call('FirebaseRemoteConfig.GetInfo');
        self::assertFalse($result->flag);
        self::assertSame(80, $result->count);
        self::assertSame(1.25, $result->scale);
        self::assertSame('remote', $result->source);
    }

    public function test_accepts_direct_object_response_for_existing_bridge_compatibility(): void
    {
        $bridge = new NativePhpBridge(static fn (): string => '{"available":true}');
        self::assertTrue($bridge->call('FirebaseRemoteConfig.IsAvailable')->available);
    }

    #[DataProvider('invalidReplies')]
    public function test_controls_malformed_native_replies(mixed $response): void
    {
        $bridge = new NativePhpBridge(static fn (): mixed => $response);
        self::assertNull($bridge->call('FirebaseRemoteConfig.IsAvailable'));
    }

    public static function invalidReplies(): array
    {
        return [
            'null' => [null], 'empty' => [''], 'invalid json' => ['{'], 'json null' => ['null'],
            'json array' => ['[]'], 'json string' => ['"value"'], 'json number' => ['80'],
            'json boolean' => ['true'], 'integer transport' => [80], 'array transport' => [[]],
            'object transport' => [(object) []], 'null data' => ['{"data":null}'],
            'array data' => ['{"data":[]}'], 'string data' => ['{"data":"value"}'],
            'too large' => ['{"data":{"text":"'.str_repeat('x', Contract::MAX_RESPONSE_BYTES).'"}}'],
            'too deep' => [str_repeat('{"nested":', Contract::JSON_DEPTH + 1).'{}'.str_repeat('}', Contract::JSON_DEPTH + 1)],
            'bad utf8' => ["{\"text\":\"\xFF\"}"],
        ];
    }

    public function test_does_not_call_native_for_invalid_requests(): void
    {
        $calls = 0;
        $bridge = new NativePhpBridge(static function () use (&$calls): string {
            $calls++;

            return '{}';
        });
        self::assertNull($bridge->call('FirebaseRemoteConfig.Reset'));
        self::assertNull($bridge->call('FirebaseRemoteConfig.Fetch', ['id' => 'bad']));
        self::assertNull($bridge->call('FirebaseRemoteConfig.IsAvailable', ['extra' => true]));
        self::assertNull($bridge->call('FirebaseRemoteConfig.GetValues', ['values' => ['flag' => 'json']]));
        self::assertNull($bridge->call('FirebaseRemoteConfig.IsAvailable', ['large' => str_repeat('x', Contract::MAX_REQUEST_BYTES)]));
        self::assertSame(0, $calls);
    }

    public function test_native_exceptions_are_controlled(): void
    {
        $bridge = new NativePhpBridge(static function (): never {
            throw new RuntimeException('internal detail must not reach the caller');
        });
        self::assertNull($bridge->call('FirebaseRemoteConfig.IsAvailable'));
    }

    public function test_encodes_valid_default_request_with_decimal_type_preserved(): void
    {
        $id = '123e4567-e89b-42d3-a456-426614174000';
        $bridge = new NativePhpBridge(static function (string $method, string $request) use ($id): string {
            self::assertSame('FirebaseRemoteConfig.SetDefaults', $method);
            $decoded = json_decode($request, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($id, $decoded['id']);
            self::assertSame(1.0, $decoded['defaults']['scale']['value']);
            self::assertFalse($decoded['defaults']['flag']['value']);

            return '{}';
        });
        self::assertIsObject($bridge->call('FirebaseRemoteConfig.SetDefaults', ['id' => $id, 'defaults' => [
            'scale' => ['type' => 'decimal', 'value' => 1.0],
            'flag' => ['type' => 'boolean', 'value' => false],
        ]]));
    }
}
