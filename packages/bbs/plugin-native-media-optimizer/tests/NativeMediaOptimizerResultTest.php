<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Tests;

use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerResult as Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeMediaOptimizerResultTest extends TestCase
{
    private const ID = '3f1d6c28-78e0-4f1b-a48e-f7350dcb601a';
    private const SOURCE = 'a72645ed-85bb-4f51-9d9e-8c71815646af';
    private const ROOT = '/private/storage/app/native-media-optimizer';

    #[DataProvider('validResults')]
    public function test_valid_lifecycle_results(array $payload): void
    {
        $result = Result::fromNative((object) $payload, self::ID, self::ROOT, $payload['operation'], $payload['sourceDocumentId']);
        self::assertSame($payload['status'], $result->status);
        self::assertSame($payload['accepted'], $result->accepted);
        self::assertSame($payload['accepted'] && $payload['status'] === 'succeeded', $result->success);
    }

    public static function validResults(): iterable
    {
        foreach (['InspectMedia', 'OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'] as $operation) {
            yield "$operation pending" => [self::pending(['operation' => $operation])];
        }
        foreach (['inspecting', 'decoding', 'encoding', 'finalizing'] as $phase) {
            yield "running $phase" => [self::pending(['status' => 'running', 'phase' => $phase, 'progress' => null])];
        }
        yield 'known progress' => [self::pending(['status' => 'running', 'phase' => 'encoding', 'progress' => 37])];
        yield 'cancelling' => [self::pending(['status' => 'cancelling', 'phase' => 'cancelling', 'progress' => 37])];
        yield 'cancelled' => [self::pending(['status' => 'cancelled', 'phase' => 'cancelled', 'progress' => 37])];
        yield 'accepted failure' => [self::pending(['status' => 'failed', 'phase' => 'failed', 'progress' => null, 'errorCode' => ErrorCode::DECODE_FAILED])];
        yield 'rejected failure' => [self::pending(['accepted' => false, 'status' => 'failed', 'phase' => 'failed', 'progress' => null, 'sourceDocumentId' => null, 'errorCode' => ErrorCode::BUSY])];
        yield 'interrupted' => [self::pending(['status' => 'interrupted', 'phase' => 'interrupted', 'progress' => null, 'errorCode' => ErrorCode::PROCESS_INTERRUPTED])];
        yield 'not found' => [self::pending(['accepted' => false, 'operation' => null, 'sourceDocumentId' => null, 'status' => 'not_found', 'phase' => 'failed', 'progress' => null, 'errorCode' => ErrorCode::RESULT_NOT_FOUND])];
        yield 'inspection completed' => [self::pending(['operation' => 'InspectMedia', 'status' => 'succeeded', 'phase' => 'completed', 'progress' => 100, 'input' => self::image()])];
        yield 'image completed' => [self::completed()];
        yield 'image output deleted' => [self::completed(['outputAvailable' => false])];
        yield 'thumbnail completed' => [self::completed(['operation' => 'GenerateThumbnail', 'input' => self::video()])];
        yield 'video completed' => [self::completed(['operation' => 'OptimizeVideo', 'input' => self::video(), 'output' => (object) ['id' => self::ID, 'path' => self::ROOT.'/'.self::ID.'.mp4', ...get_object_vars(self::video())]])];
    }

    #[DataProvider('invalidResults')]
    public function test_malformed_native_results_are_contained(array $payload): void
    {
        $result = Result::fromNative((object) $payload, self::ID, self::ROOT);
        self::assertFalse($result->accepted);
        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertNull($result->output);
    }

    public static function invalidResults(): iterable
    {
        foreach (array_keys(self::pending()) as $key) {
            $payload = self::pending();
            unset($payload[$key]);
            yield "missing $key" => [$payload];
        }
        yield 'unknown top level field' => [self::pending(['private_filename' => 'secret.jpg'])];
        yield 'wrong id' => [self::pending(['id' => self::SOURCE])];
        yield 'unsupported operation' => [self::pending(['operation' => 'RunCommand'])];
        yield 'missing accepted operation' => [self::pending(['operation' => null])];
        yield 'missing accepted source' => [self::pending(['sourceDocumentId' => null])];
        yield 'bad source' => [self::pending(['sourceDocumentId' => '../file'])];
        yield 'integer accepted' => [self::pending(['accepted' => 1])];
        yield 'unknown status' => [self::pending(['status' => 'complete'])];
        yield 'boolean status' => [self::pending(['status' => true])];
        yield 'wrong phase' => [self::pending(['phase' => 'encoding'])];
        yield 'unknown phase' => [self::pending(['phase' => 'private filename'])];
        foreach ([-1, 101, '0', true, 0.0, []] as $index => $progress) {
            yield "bad progress $index" => [self::pending(['progress' => $progress])];
        }
        yield 'null pending progress' => [self::pending(['progress' => null])];
        yield 'running cannot finish progress' => [self::pending(['status' => 'running', 'phase' => 'encoding', 'progress' => 100])];
        yield 'rejected pending' => [self::pending(['accepted' => false])];
        yield 'accepted not found' => [self::pending(['status' => 'not_found', 'phase' => 'failed', 'progress' => null, 'errorCode' => ErrorCode::RESULT_NOT_FOUND])];
        yield 'error on pending' => [self::pending(['errorCode' => ErrorCode::BUSY])];
        yield 'failure without error' => [self::pending(['status' => 'failed', 'phase' => 'failed', 'progress' => null])];
        yield 'unknown failure code' => [self::pending(['status' => 'failed', 'phase' => 'failed', 'progress' => null, 'errorCode' => 'private-data'])];
        yield 'wrong interruption code' => [self::pending(['status' => 'interrupted', 'phase' => 'interrupted', 'progress' => null, 'errorCode' => ErrorCode::BUSY])];
        yield 'wrong not found code' => [self::pending(['accepted' => false, 'status' => 'not_found', 'phase' => 'failed', 'progress' => null, 'errorCode' => ErrorCode::BUSY])];
        yield 'output before completion' => [self::pending(['output' => self::imageOutput(), 'outputAvailable' => true])];
        yield 'available without output' => [self::pending(['outputAvailable' => true])];
        yield 'non boolean availability' => [self::completed(['outputAvailable' => 1])];
        yield 'completion missing input' => [self::completed(['input' => null])];
        yield 'completion missing output' => [self::completed(['output' => null])];
        yield 'inspection with output' => [self::completed(['operation' => 'InspectMedia'])];
        yield 'image from video input' => [self::completed(['input' => self::video()])];
        yield 'video from image input' => [self::completed(['operation' => 'OptimizeVideo'])];
        yield 'completion with partial progress' => [self::completed(['progress' => 99])];
        foreach (['mime_type' => 'image/gif', 'size' => 0, 'width' => 0, 'height' => 100001, 'duration_ms' => 1000, 'rotation_degrees' => 45, 'has_audio' => true, 'extra' => 'secret'] as $key => $value) {
            yield "bad image input $key" => [self::completed(['input' => self::image([$key => $value])])];
        }
        yield 'image input too large' => [self::completed(['input' => self::image(['size' => 104857601])])];
        yield 'source pixel limit' => [self::completed(['input' => self::image(['width' => 10001, 'height' => 10000])])];
        foreach (['id' => self::SOURCE, 'path' => '/private/elsewhere.jpg', 'width' => 4097, 'size' => '100', 'extra' => 'secret'] as $key => $value) {
            yield "bad output $key" => [self::completed(['output' => self::imageOutput([$key => $value])])];
        }
        yield 'traversal output' => [self::completed(['output' => self::imageOutput(['path' => self::ROOT.'/../'.self::ID.'.jpg'])])];
        yield 'wrong extension' => [self::completed(['output' => self::imageOutput(['path' => self::ROOT.'/'.self::ID.'.png'])])];
        yield 'filename prefix trick' => [self::completed(['output' => self::imageOutput(['path' => self::ROOT.'/'.self::ID.'.jpg.extra'])])];
        yield 'thumbnail output exceeds its own bound' => [self::completed(['operation' => 'GenerateThumbnail', 'input' => self::video(), 'output' => self::imageOutput(['width' => 2049])])];
        yield 'thumbnail needs video source' => [self::completed(['operation' => 'GenerateThumbnail'])];
        yield 'video duration unavailable' => [self::completed(['operation' => 'GenerateThumbnail', 'input' => self::video(['duration_ms' => null])])];
        yield 'video duration too long' => [self::completed(['operation' => 'GenerateThumbnail', 'input' => self::video(['duration_ms' => 3600001])])];
    }

    public function test_start_response_must_bind_operation_and_source(): void
    {
        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, Result::fromNative((object) self::pending(), self::ID, self::ROOT, 'OptimizeVideo', self::SOURCE)->errorCode);
        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, Result::fromNative((object) self::pending(), self::ID, self::ROOT, 'OptimizeImage', self::ID)->errorCode);
    }

    #[DataProvider('canonicalOutputStates')]
    public function test_canonical_outputs_bind_to_the_trusted_configured_parent(bool $directoryExists, bool $available): void
    {
        $this->withAliasedRoot($directoryExists, function (string $configured, string $canonical) use ($available): void {
            $path = $canonical.'/'.self::ID.'.jpg';
            $result = Result::fromNative((object) self::completed(['output' => self::imageOutput(['path' => $path]), 'outputAvailable' => $available]), self::ID, $configured, 'OptimizeImage', self::SOURCE);
            self::assertTrue($result->accepted);
            self::assertTrue($result->success);
            self::assertSame($available, $result->outputAvailable);
            self::assertSame($path, $result->output['path']);
            self::assertArrayNotHasKey('path', $result->toMetadata()['output']);
        });
    }

    public static function canonicalOutputStates(): iterable
    {
        yield 'output available' => [true, true];
        yield 'output deleted' => [true, false];
        yield 'output directory removed' => [false, false];
    }

    #[DataProvider('foreignCanonicalOutputs')]
    public function test_canonical_binding_still_rejects_foreign_names_and_traversal(string $suffix): void
    {
        $this->withAliasedRoot(true, function (string $configured, string $canonical) use ($suffix): void {
            $result = Result::fromNative((object) self::completed(['output' => self::imageOutput(['path' => dirname($canonical).'/'.$suffix])]), self::ID, $configured);
            self::assertFalse($result->accepted);
            self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
            self::assertNull($result->output);
        });
    }

    public static function foreignCanonicalOutputs(): iterable
    {
        yield 'sibling directory' => ['foreign/'.self::ID.'.jpg'];
        yield 'directory prefix trick' => ['native-media-optimizer-old/'.self::ID.'.jpg'];
        yield 'other job filename' => ['native-media-optimizer/'.self::SOURCE.'.jpg'];
        yield 'other extension' => ['native-media-optimizer/'.self::ID.'.png'];
        yield 'normalized traversal' => ['native-media-optimizer/../native-media-optimizer/'.self::ID.'.jpg'];
    }

    private function withAliasedRoot(bool $directoryExists, callable $check): void
    {
        $temporary = sys_get_temp_dir().'/native-media-result-'.bin2hex(random_bytes(12));
        mkdir($temporary.'/app', 0700, true);
        $configured = $temporary.'/app/./native-media-optimizer';
        $canonical = str_replace('\\', '/', realpath($temporary.'/app')).'/native-media-optimizer';
        if ($directoryExists) { mkdir($configured, 0700); }
        try {
            $check($configured, $canonical);
        } finally {
            if ($directoryExists) { rmdir($configured); }
            rmdir($temporary.'/app');
            rmdir($temporary);
        }
    }

    public function test_serializers_omit_private_paths_and_native_error_text(): void
    {
        $result = Result::fromNative((object) self::completed(), self::ID, self::ROOT);
        self::assertSame(self::ROOT.'/'.self::ID.'.jpg', $result->output['path']);
        self::assertArrayNotHasKey('path', $result->toMetadata()['output']);
        self::assertStringNotContainsString(self::ROOT, json_encode($result, JSON_THROW_ON_ERROR));
        $failure = Result::fromNative((object) self::pending(['status' => 'failed', 'phase' => 'failed', 'progress' => null, 'errorCode' => ErrorCode::DECODE_FAILED, 'errorMessage' => '/private/secret.jpg']), self::ID, self::ROOT);
        self::assertSame(ErrorCode::message(ErrorCode::DECODE_FAILED), $failure->errorMessage);
        self::assertStringNotContainsString('secret.jpg', json_encode($failure, JSON_THROW_ON_ERROR));
    }

    private static function pending(array $overrides = []): array
    {
        return array_replace(['id' => self::ID, 'operation' => 'OptimizeImage', 'accepted' => true, 'status' => 'pending', 'sourceDocumentId' => self::SOURCE, 'progress' => 0, 'phase' => 'queued', 'input' => null, 'output' => null, 'outputAvailable' => false, 'errorCode' => null], $overrides);
    }

    private static function completed(array $overrides = []): array
    {
        return self::pending(array_replace(['status' => 'succeeded', 'progress' => 100, 'phase' => 'completed', 'input' => self::image(), 'output' => self::imageOutput(), 'outputAvailable' => true], $overrides));
    }

    private static function image(array $overrides = []): object
    {
        return (object) array_replace(['mime_type' => 'image/jpeg', 'size' => 100000, 'width' => 1920, 'height' => 1080, 'duration_ms' => null, 'rotation_degrees' => 0, 'has_audio' => false], $overrides);
    }

    private static function video(array $overrides = []): object
    {
        return self::image(array_replace(['mime_type' => 'video/mp4', 'duration_ms' => 5000, 'has_audio' => true], $overrides));
    }

    private static function imageOutput(array $overrides = []): object
    {
        return (object) array_replace(['id' => self::ID, 'path' => self::ROOT.'/'.self::ID.'.jpg', ...get_object_vars(self::image())], $overrides);
    }
}
