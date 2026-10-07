<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Tests;

use Bbs\NativeMediaOptimizer\Events\NativeMediaOptimizerCompleted;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeMediaOptimizerCompletedTest extends TestCase
{
    private const ID = '3f1d6c28-78e0-4f1b-a48e-f7350dcb601a';

    #[DataProvider('validEvents')]
    public function test_terminal_events_have_metadata_only(string $operation, string $status, ?string $code): void
    {
        $event = new NativeMediaOptimizerCompleted(self::ID, $operation, $status, $code, '/private/secret.jpg');
        self::assertSame(['id', 'operation', 'status', 'errorCode', 'errorMessage'], array_keys($event->toMetadata()));
        self::assertStringNotContainsString('secret.jpg', json_encode($event, JSON_THROW_ON_ERROR));
        self::assertSame($code === null ? null : ErrorCode::message($code), $event->errorMessage);
    }

    public static function validEvents(): iterable
    {
        foreach (['InspectMedia', 'OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'] as $operation) {
            yield "$operation succeeded" => [$operation, 'succeeded', null];
            yield "$operation cancelled" => [$operation, 'cancelled', null];
            yield "$operation failed" => [$operation, 'failed', ErrorCode::DECODE_FAILED];
            yield "$operation interrupted" => [$operation, 'interrupted', ErrorCode::PROCESS_INTERRUPTED];
        }
    }

    #[DataProvider('invalidEvents')]
    public function test_invalid_events_are_rejected(string $id, string $operation, string $status, ?string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NativeMediaOptimizerCompleted($id, $operation, $status, $code);
    }

    public static function invalidEvents(): iterable
    {
        yield 'bad id' => ['bad', 'OptimizeImage', 'succeeded', null];
        yield 'bad operation' => [self::ID, 'Unknown', 'succeeded', null];
        yield 'pending' => [self::ID, 'OptimizeImage', 'pending', null];
        yield 'running' => [self::ID, 'OptimizeImage', 'running', null];
        yield 'failure missing error' => [self::ID, 'OptimizeImage', 'failed', null];
        yield 'unknown error' => [self::ID, 'OptimizeImage', 'failed', 'private-data'];
        yield 'success has error' => [self::ID, 'OptimizeImage', 'succeeded', ErrorCode::BUSY];
        yield 'cancelled has error' => [self::ID, 'OptimizeImage', 'cancelled', ErrorCode::BUSY];
        yield 'wrong interrupted error' => [self::ID, 'OptimizeImage', 'interrupted', ErrorCode::BUSY];
    }
}
