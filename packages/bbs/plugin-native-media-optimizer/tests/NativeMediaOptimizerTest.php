<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Tests;

use Bbs\NativeMediaOptimizer\Contracts\NativeBridge;
use Bbs\NativeMediaOptimizer\NativeMediaOptimizer;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerRequestValidator as Validator;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeMediaOptimizerTest extends TestCase
{
    private const ID = '3f1d6c28-78e0-4f1b-a48e-f7350dcb601a';
    private const SOURCE = 'a72645ed-85bb-4f51-9d9e-8c71815646af';
    private const ROOT = '/private/storage/app/native-media-optimizer';

    #[DataProvider('startMethods')]
    public function test_start_methods_bind_native_requests(string $method, string $operation): void
    {
        $bridge = $this->bridge(static function (string $nativeMethod, array $parameters) use ($operation): object {
            self::assertSame('NativeMediaOptimizer.'.$operation, $nativeMethod);
            self::assertTrue(Validator::isRequestId($parameters['id']));
            self::assertSame(self::SOURCE, $parameters['source_document_id']);
            return self::pending($parameters['id'], $operation);
        });
        $service = new NativeMediaOptimizer($bridge, self::ROOT);
        $result = $service->$method(self::SOURCE);
        self::assertTrue($result->accepted);
        self::assertSame('pending', $result->status);
        self::assertCount(1, $bridge->calls);
    }

    public static function startMethods(): iterable
    {
        yield ['inspectMedia', 'InspectMedia'];
        yield ['optimizeImage', 'OptimizeImage'];
        yield ['optimizeVideo', 'OptimizeVideo'];
        yield ['generateThumbnail', 'GenerateThumbnail'];
    }

    public function test_supplied_id_and_processing_settings_are_preserved(): void
    {
        $bridge = $this->bridge(static function (string $method, array $parameters): object {
            self::assertSame(['id' => self::ID, 'max_width' => 800, 'quality' => 60, 'source_document_id' => self::SOURCE], $parameters);
            return self::pending(self::ID, 'OptimizeImage');
        });
        self::assertTrue((new NativeMediaOptimizer($bridge, self::ROOT))->optimizeImage(self::SOURCE, ['id' => self::ID, 'max_width' => 800, 'quality' => 60])->accepted);
    }

    #[DataProvider('invalidStarts')]
    public function test_invalid_requests_never_reach_native(string $source, array $options, string $error): void
    {
        $bridge = $this->bridge(static fn (): object => (object) []);
        $result = (new NativeMediaOptimizer($bridge, self::ROOT))->optimizeImage($source, $options);
        self::assertSame($error, $result->errorCode);
        self::assertCount(0, $bridge->calls);
    }

    public static function invalidStarts(): iterable
    {
        yield 'bad source' => ['../file', [], ErrorCode::INVALID_SOURCE_ID];
        yield 'null id' => [self::SOURCE, ['id' => null], ErrorCode::INVALID_REQUEST_ID];
        yield 'bad id' => [self::SOURCE, ['id' => 'bad'], ErrorCode::INVALID_REQUEST_ID];
        yield 'source override' => [self::SOURCE, ['source_document_id' => self::ID], ErrorCode::INVALID_OPTIONS];
        yield 'arbitrary path' => [self::SOURCE, ['path' => '/private/file'], ErrorCode::INVALID_OPTIONS];
        yield 'invalid dimensions' => [self::SOURCE, ['max_width' => 0], ErrorCode::INVALID_DIMENSIONS];
    }

    #[DataProvider('queryMethods')]
    public function test_queries_bind_requested_id(string $method, string $operation): void
    {
        $bridge = $this->bridge(static function (string $nativeMethod, array $parameters) use ($operation): object {
            self::assertSame('NativeMediaOptimizer.'.$operation, $nativeMethod);
            self::assertSame(['id' => self::ID], $parameters);
            return self::pending(self::ID, 'OptimizeImage');
        });
        $service = new NativeMediaOptimizer($bridge, self::ROOT);
        self::assertTrue($service->$method(self::ID)->accepted);
        self::assertSame(ErrorCode::INVALID_REQUEST_ID, $service->$method(null)->errorCode);
        self::assertCount(1, $bridge->calls);
    }

    public static function queryMethods(): iterable
    {
        yield ['getStatus', 'GetStatus'];
        yield ['cancel', 'Cancel'];
        yield ['getResult', 'GetResult'];
    }

    public function test_unavailable_native_transport_is_reported(): void
    {
        $service = new NativeMediaOptimizer($this->bridge(static fn (): ?object => null), self::ROOT);
        self::assertSame(ErrorCode::NATIVE_UNAVAILABLE, $service->optimizeImage(self::SOURCE)->errorCode);
        self::assertSame(ErrorCode::NATIVE_UNAVAILABLE, $service->getStatus(self::ID)->errorCode);
        self::assertSame(ErrorCode::NATIVE_UNAVAILABLE, $service->deleteOutput(self::ID)->errorCode);
        self::assertFalse($service->isAvailable()->available);
    }

    public function test_start_response_with_wrong_binding_is_rejected(): void
    {
        $service = new NativeMediaOptimizer($this->bridge(static fn (): object => self::pending(self::SOURCE, 'OptimizeVideo')), self::ROOT);
        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $service->optimizeImage(self::SOURCE, ['id' => self::ID])->errorCode);
    }

    #[DataProvider('availabilityCases')]
    public function test_capability_response_contract(object $payload, bool $expected): void
    {
        $service = new NativeMediaOptimizer($this->bridge(static fn (): object => $payload), self::ROOT);
        self::assertSame($expected, $service->isAvailable()->available);
    }

    public static function availabilityCases(): iterable
    {
        $base = ['platform' => 'android', 'available' => true, 'images' => true, 'video' => true, 'thumbnails' => true];
        yield 'all capabilities' => [(object) $base, true];
        yield 'images only' => [(object) array_replace($base, ['video' => false, 'thumbnails' => false]), true];
        yield 'all unavailable' => [(object) array_replace($base, ['available' => false, 'images' => false, 'video' => false, 'thumbnails' => false]), false];
        yield 'wrong platform' => [(object) array_replace($base, ['platform' => 'ios']), false];
        yield 'non boolean flag' => [(object) array_replace($base, ['video' => 1]), false];
        yield 'contradictory availability' => [(object) array_replace($base, ['available' => false]), false];
        yield 'extra private field' => [(object) ($base + ['path' => '/private/file']), false];
        yield 'missing flags' => [(object) ['platform' => 'android'], false];
    }

    #[DataProvider('deletionCases')]
    public function test_output_deletion_contract(object $payload, bool $deleted, ?string $error): void
    {
        $service = new NativeMediaOptimizer($this->bridge(static fn (): object => $payload), self::ROOT);
        $result = $service->deleteOutput(self::ID);
        self::assertSame($deleted, $result->deleted);
        self::assertSame($error, $result->errorCode);
        self::assertNotSame('/private/file', $result->errorMessage);
    }

    public static function deletionCases(): iterable
    {
        yield 'deleted' => [(object) ['id' => self::ID, 'deleted' => true, 'errorCode' => null], true, null];
        yield 'busy' => [(object) ['id' => self::ID, 'deleted' => false, 'errorCode' => ErrorCode::OUTPUT_IN_USE, 'errorMessage' => '/private/file'], false, ErrorCode::OUTPUT_IN_USE];
        yield 'wrong id' => [(object) ['id' => self::SOURCE, 'deleted' => true, 'errorCode' => null], false, ErrorCode::INVALID_NATIVE_RESPONSE];
        yield 'unknown error' => [(object) ['id' => self::ID, 'deleted' => false, 'errorCode' => 'secret'], false, ErrorCode::INVALID_NATIVE_RESPONSE];
        yield 'contradictory success' => [(object) ['id' => self::ID, 'deleted' => true, 'errorCode' => ErrorCode::BUSY], false, ErrorCode::INVALID_NATIVE_RESPONSE];
        yield 'failure without error' => [(object) ['id' => self::ID, 'deleted' => false, 'errorCode' => null], false, ErrorCode::INVALID_NATIVE_RESPONSE];
        yield 'non boolean deleted' => [(object) ['id' => self::ID, 'deleted' => 1, 'errorCode' => null], false, ErrorCode::INVALID_NATIVE_RESPONSE];
        yield 'private extra field' => [(object) ['id' => self::ID, 'deleted' => true, 'errorCode' => null, 'path' => '/private/file'], false, ErrorCode::INVALID_NATIVE_RESPONSE];
    }

    public function test_bad_delete_id_never_reaches_native(): void
    {
        $bridge = $this->bridge(static fn (): object => (object) []);
        self::assertSame(ErrorCode::INVALID_REQUEST_ID, (new NativeMediaOptimizer($bridge, self::ROOT))->deleteOutput([])->errorCode);
        self::assertCount(0, $bridge->calls);
    }

    private static function pending(string $id, string $operation): object
    {
        return (object) ['id' => $id, 'operation' => $operation, 'accepted' => true, 'status' => 'pending', 'sourceDocumentId' => self::SOURCE, 'progress' => 0, 'phase' => 'queued', 'input' => null, 'output' => null, 'outputAvailable' => false, 'errorCode' => null];
    }

    private function bridge(Closure $response): NativeBridge
    {
        return new class($response) implements NativeBridge {
            public array $calls = [];
            public function __construct(private readonly Closure $response) {}
            public function call(string $method, array $parameters = []): ?object
            {
                $this->calls[] = compact('method', 'parameters');
                return ($this->response)($method, $parameters);
            }
        };
    }
}
