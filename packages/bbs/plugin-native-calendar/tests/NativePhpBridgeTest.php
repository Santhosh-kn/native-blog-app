<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Tests;

use Bbs\NativeCalendar\Support\NativePhpBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativePhpBridgeTest extends TestCase
{
    public static function supportedMethods(): iterable
    {
        foreach (['IsAvailable', 'CreateEvent', 'Open', 'GetStatus'] as $method) {
            yield $method => ['NativeCalendar.'.$method];
        }
    }

    #[DataProvider('supportedMethods')]
    public function test_calls_supported_methods_and_unwraps_data(string $method): void
    {
        $calls = [];
        $bridge = new NativePhpBridge(
            static function (string $calledMethod, string $request) use (&$calls): string {
                $calls[] = [$calledMethod, $request];

                return '{"data":{"safe":true}}';
            },
        );

        $result = $bridge->call($method, ['id' => 'demo']);

        self::assertSame([[$method, '{"id":"demo"}']], $calls);
        self::assertTrue($result->safe);
        self::assertFalse(property_exists($result, 'data'));
    }

    #[DataProvider('supportedMethods')]
    public function test_accepts_direct_native_response_objects(string $method): void
    {
        $calls = [];
        $bridge = new NativePhpBridge(
            static function (string $calledMethod, string $request) use (&$calls): string {
                $calls[] = [$calledMethod, $request];

                return '{"safe":true}';
            },
        );

        $result = $bridge->call($method, ['id' => 'demo']);

        self::assertSame([[$method, '{"id":"demo"}']], $calls);
        self::assertIsObject($result);
        self::assertTrue($result->safe);
        self::assertFalse(property_exists($result, 'data'));
    }

    public function test_accepts_an_empty_direct_response_object(): void
    {
        $bridge = new NativePhpBridge(static fn (): string => '{}');

        self::assertIsObject($bridge->call('NativeCalendar.GetStatus'));
    }

    public static function unsupportedMethods(): iterable
    {
        foreach ([
            'NativeCalendar.Execute',
            'NativeCalendar.Cancel',
            'NativeCalendar.ConsumeResult',
            'NativeContacts.GetStatus',
            'nativecalendar.GetStatus',
            '',
        ] as $method) {
            yield $method === '' ? 'empty' : $method => [$method];
        }
    }

    #[DataProvider('unsupportedMethods')]
    public function test_rejects_unsupported_methods_without_calling_native(string $method): void
    {
        $calls = 0;
        $bridge = new NativePhpBridge(
            static function () use (&$calls): string {
                $calls++;

                return '{"data":{}}';
            },
        );

        self::assertNull($bridge->call($method));
        self::assertSame(0, $calls);
    }

    public function test_empty_parameters_are_encoded_as_an_object(): void
    {
        $requests = [];
        $bridge = new NativePhpBridge(
            static function (string $method, string $request) use (&$requests): string {
                $requests[] = $request;

                return '{"data":{}}';
            },
        );

        self::assertIsObject($bridge->call('NativeCalendar.IsAvailable'));
        self::assertSame(['{}'], $requests);
    }

    public static function boundaryOffsets(): iterable
    {
        yield 'at limit' => [0, true];
        yield 'above limit' => [1, false];
    }

    #[DataProvider('boundaryOffsets')]
    public function test_request_byte_limit(int $offset, bool $accepted): void
    {
        $calls = 0;
        $receivedBytes = null;
        $bridge = new NativePhpBridge(
            static function (string $method, string $request) use (&$calls, &$receivedBytes): string {
                $calls++;
                $receivedBytes = strlen($request);

                return '{"data":{}}';
            },
        );

        $overhead = strlen(json_encode(['value' => ''], JSON_THROW_ON_ERROR));
        $parameters = ['value' => str_repeat('x', 8192 - $overhead + $offset)];

        $result = $bridge->call('NativeCalendar.CreateEvent', $parameters);

        self::assertSame($accepted ? 1 : 0, $calls);

        if ($accepted) {
            self::assertIsObject($result);
            self::assertSame(8192, $receivedBytes);
        } else {
            self::assertNull($result);
        }
    }

    #[DataProvider('boundaryOffsets')]
    public function test_response_byte_limit(int $offset, bool $accepted): void
    {
        $base = ['data' => (object) ['safe' => true], 'padding' => ''];
        $overhead = strlen(json_encode($base, JSON_THROW_ON_ERROR));
        $base['padding'] = str_repeat('x', 16384 - $overhead + $offset);
        $response = json_encode($base, JSON_THROW_ON_ERROR);

        self::assertSame(16384 + $offset, strlen($response));

        $bridge = new NativePhpBridge(static fn (): string => $response);
        $result = $bridge->call('NativeCalendar.GetStatus');

        if ($accepted) {
            self::assertTrue($result->safe);
        } else {
            self::assertNull($result);
        }
    }

    public static function invalidResponses(): iterable
    {
        foreach ([
            null, false, 123, '', ' ', '{', 'null', '[]',
            '{"data":null}', '{"data":[]}', '{"data":"demo"}',
            '{"data":42}', '{"data":true}',
        ] as $index => $response) {
            yield 'response '.$index => [$response];
        }
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_invalid_response_envelopes(mixed $response): void
    {
        $bridge = new NativePhpBridge(static fn (): mixed => $response);

        self::assertNull($bridge->call('NativeCalendar.GetStatus'));
    }

    public function test_invalid_utf8_request_does_not_reach_native(): void
    {
        $calls = 0;
        $bridge = new NativePhpBridge(
            static function () use (&$calls): string {
                $calls++;

                return '{"data":{}}';
            },
        );

        self::assertNull($bridge->call('NativeCalendar.CreateEvent', [
            'title' => "\xC3\x28",
        ]));
        self::assertSame(0, $calls);
    }

    public function test_excessive_request_nesting_does_not_reach_native(): void
    {
        $nested = 'demo';
        for ($index = 0; $index < 20; $index++) {
            $nested = ['child' => $nested];
        }

        $calls = 0;
        $bridge = new NativePhpBridge(
            static function () use (&$calls): string {
                $calls++;

                return '{"data":{}}';
            },
        );

        self::assertNull($bridge->call('NativeCalendar.CreateEvent', ['value' => $nested]));
        self::assertSame(0, $calls);
    }

    public function test_excessive_response_nesting_is_rejected(): void
    {
        $nested = (object) ['safe' => true];
        for ($index = 0; $index < 20; $index++) {
            $nested = (object) ['child' => $nested];
        }

        $response = json_encode(['data' => $nested], JSON_THROW_ON_ERROR);
        $bridge = new NativePhpBridge(static fn (): string => $response);

        self::assertNull($bridge->call('NativeCalendar.GetStatus'));
    }

    public function test_native_exception_is_caught_without_printing_its_contents(): void
    {
        $bridge = new NativePhpBridge(
            static function (): never {
                throw new RuntimeException('Synthetic private event details');
            },
        );

        ob_start();
        try {
            $result = $bridge->call('NativeCalendar.CreateEvent');
        } finally {
            $output = ob_get_clean();
        }

        self::assertNull($result);
        self::assertSame('', $output);
    }
}
