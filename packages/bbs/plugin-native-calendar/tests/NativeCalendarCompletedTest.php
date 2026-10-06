<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Tests;

use Bbs\NativeCalendar\Events\NativeCalendarCompleted;
use Bbs\NativeCalendar\Support\NativeCalendarErrorCode as ErrorCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeCalendarCompletedTest extends TestCase
{
    private static function launched(): array
    {
        return [
            'id' => 'a1111111-b111-4111-8111-c11111111111',
            'operation' => 'create_event', 'target' => 'editor',
            'status' => 'launched', 'accepted' => true, 'success' => true,
            'errorCode' => null,
            'errorMessage' => 'Synthetic private event details',
            'createdAtMs' => 1000, 'completedAtMs' => 2000,
        ];
    }

    public static function validMetadata(): iterable
    {
        yield 'editor launched' => [self::launched()];
        yield 'date launched' => [array_replace(self::launched(), [
            'operation' => 'open', 'target' => 'date',
        ])];
        yield 'event launched' => [array_replace(self::launched(), [
            'operation' => 'open', 'target' => 'event',
        ])];
        yield 'launch failed' => [array_replace(self::launched(), [
            'status' => 'failed', 'success' => false,
            'errorCode' => ErrorCode::LAUNCH_FAILED,
        ])];
        yield 'interrupted' => [array_replace(self::launched(), [
            'status' => 'unknown', 'success' => false,
            'errorCode' => ErrorCode::INTERRUPTED,
        ])];
    }

    #[DataProvider('validMetadata')]
    public function test_valid_completion_contains_only_controlled_metadata(array $metadata): void
    {
        $event = new NativeCalendarCompleted(...$metadata);
        $data = $event->toMetadata();

        self::assertSame($metadata['id'], $event->id);
        self::assertSame($metadata['status'], $event->status);
        self::assertSame($metadata['operation'], $event->operation);
        self::assertSame($metadata['target'], $event->target);
        self::assertSame(
            $metadata['errorCode'] === null
                ? null
                : ErrorCode::message($metadata['errorCode']),
            $event->errorMessage,
        );
        self::assertCount(10, $data);

        $json = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Synthetic private event details', $json);
        self::assertSame($data, json_decode($json, true, 16, JSON_THROW_ON_ERROR));

        foreach ([
            'title', 'description', 'location', 'dateMs', 'eventId',
            'startTimeMs', 'endTimeMs', 'timeZone', 'recurrence',
        ] as $key) {
            self::assertArrayNotHasKey($key, $data);
        }
    }

    public static function invalidMetadata(): iterable
    {
        $patches = [
            'invalid ID' => ['id' => 'Synthetic private event details'],
            'unaccepted request' => ['accepted' => false],
            'missing operation' => ['operation' => null],
            'unsupported operation' => ['operation' => 'delete_event'],
            'wrong target' => ['target' => 'event'],
            'missing target' => ['target' => null],
            'pending is not completion' => ['status' => 'pending', 'success' => false],
            'saved is not observed' => ['status' => 'saved'],
            'cancelled is not observed' => ['status' => 'cancelled'],
            'missing success' => ['success' => false],
            'launched with error' => ['errorCode' => ErrorCode::LAUNCH_FAILED],
            'unknown error code' => ['errorCode' => 'Synthetic private event details'],
            'missing creation' => ['createdAtMs' => null],
            'missing completion' => ['completedAtMs' => null],
            'reversed timestamps' => ['completedAtMs' => 999],
            'failed without error' => ['status' => 'failed', 'success' => false],
            'unknown without interruption' => ['status' => 'unknown', 'success' => false],
        ];

        foreach ($patches as $name => $patch) {
            yield $name => [array_replace(self::launched(), $patch)];
        }
    }

    #[DataProvider('invalidMetadata')]
    public function test_rejects_invalid_completion_with_a_controlled_exception(array $metadata): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The native Calendar completion metadata is invalid.');

        new NativeCalendarCompleted(...$metadata);
    }
}
