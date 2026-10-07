<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Tests;

use Bbs\NativeMediaOptimizer\Support\NativePhpBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativePhpBridgeTest extends TestCase
{
    public function test_empty_parameters_are_sent_as_an_object(): void
    {
        $bridge = new NativePhpBridge(static function (string $method, string $request): string {
            self::assertSame('NativeMediaOptimizer.IsAvailable', $method);
            self::assertSame('{}', $request);
            return '{"available":true}';
        });
        self::assertTrue($bridge->call('NativeMediaOptimizer.IsAvailable')->available);
    }

    #[DataProvider('validResponses')]
    public function test_direct_and_enveloped_responses(string $response): void
    {
        $bridge = new NativePhpBridge(static fn (): string => $response);
        self::assertSame('running', $bridge->call('NativeMediaOptimizer.GetStatus', ['id' => 'test'])->status);
    }

    public static function validResponses(): iterable
    {
        yield 'direct' => ['{"status":"running"}'];
        yield 'envelope' => ['{"status":"success","data":{"status":"running"}}'];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_responses_are_rejected(mixed $response): void
    {
        $bridge = new NativePhpBridge(static fn (): mixed => $response);
        self::assertNull($bridge->call('NativeMediaOptimizer.GetStatus'));
    }

    public static function invalidResponses(): iterable
    {
        foreach ([null, 1, true, [], (object) [], '', '{', 'null', 'true', '1', '"text"', '[]', '{"data":null}', '{"data":[]}', '{"data":"text"}', str_repeat(' ', 16385)] as $index => $response) {
            yield "invalid $index" => [$response];
        }
        yield 'nested too deeply' => [str_repeat('{"a":', 20).'{}'.str_repeat('}', 20)];
    }

    public function test_unknown_method_never_calls_native(): void
    {
        $called = false;
        $bridge = new NativePhpBridge(static function () use (&$called): string {
            $called = true;
            return '{}';
        });
        self::assertNull($bridge->call('System.RunCommand'));
        self::assertFalse($called);
    }

    public function test_oversized_request_never_calls_native(): void
    {
        $called = false;
        $bridge = new NativePhpBridge(static function () use (&$called): string {
            $called = true;
            return '{}';
        });
        self::assertNull($bridge->call('NativeMediaOptimizer.GetStatus', ['id' => str_repeat('a', 8192)]));
        self::assertFalse($called);
    }

    public function test_malformed_request_never_calls_native(): void
    {
        $called = false;
        $bridge = new NativePhpBridge(static function () use (&$called): string {
            $called = true;
            return '{}';
        });
        self::assertNull($bridge->call('NativeMediaOptimizer.GetStatus', ['id' => "\xff"]));
        self::assertFalse($called);
    }

    public function test_native_exception_is_contained(): void
    {
        $bridge = new NativePhpBridge(static function (): never {
            throw new RuntimeException('private input');
        });
        self::assertNull($bridge->call('NativeMediaOptimizer.GetStatus'));
    }
}
