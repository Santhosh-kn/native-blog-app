<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Tests;

use Bbs\NativeCalendar\Support\NativeCalendarErrorCode as ErrorCode;
use Bbs\NativeCalendar\Support\NativeCalendarResult as Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeCalendarResultTest extends TestCase
{
    private const ID = 'a1111111-b111-4111-8111-c11111111111';

    private static function pending(): array
    {
        return [
            'id' => self::ID,
            'operation' => 'create_event',
            'target' => 'editor',
            'status' => 'pending',
            'accepted' => true,
            'success' => false,
            'errorCode' => null,
            'errorMessage' => 'Synthetic private event details',
            'createdAtMs' => 1000,
            'completedAtMs' => null,
        ];
    }

    public static function validResults(): iterable
    {
        yield 'pending editor' => [self::pending(), 'create_event', 'editor'];
        yield 'launched editor' => [array_replace(self::pending(), [
            'status' => 'launched', 'success' => true, 'completedAtMs' => 2000,
        ]), 'create_event', 'editor'];
        yield 'failed before acceptance' => [array_replace(self::pending(), [
            'status' => 'failed', 'accepted' => false,
            'errorCode' => ErrorCode::NO_CALENDAR_APP, 'createdAtMs' => null,
        ]), 'create_event', 'editor'];
        yield 'failed after acceptance' => [array_replace(self::pending(), [
            'status' => 'failed', 'errorCode' => ErrorCode::LAUNCH_FAILED,
            'completedAtMs' => 2000,
        ]), 'create_event', 'editor'];
        yield 'interrupted request' => [array_replace(self::pending(), [
            'status' => 'unknown', 'errorCode' => ErrorCode::INTERRUPTED,
            'completedAtMs' => 2000,
        ]), 'create_event', 'editor'];
        yield 'same-clock terminal' => [array_replace(self::pending(), [
            'status' => 'launched', 'success' => true, 'completedAtMs' => 1000,
        ]), 'create_event', 'editor'];

        foreach (['date', 'event'] as $target) {
            yield 'pending '.$target => [array_replace(self::pending(), [
                'operation' => 'open', 'target' => $target,
            ]), 'open', $target];
        }

        yield 'lookup existing request' => [self::pending(), null, null];
        yield 'missing lookup' => [array_replace(self::pending(), [
            'operation' => null, 'target' => null, 'status' => 'not_found',
            'accepted' => false, 'errorCode' => ErrorCode::RESULT_NOT_FOUND,
            'createdAtMs' => null,
        ]), null, null];
        yield 'lookup persistence failure' => [array_replace(self::pending(), [
            'operation' => null, 'target' => null, 'status' => 'failed',
            'accepted' => false, 'errorCode' => ErrorCode::PERSIST_FAILED,
            'createdAtMs' => null,
        ]), null, null];
    }

    #[DataProvider('validResults')]
    public function test_validates_results_and_uses_controlled_metadata(
        array $data,
        ?string $operation,
        ?string $target,
    ): void {
        $result = Result::fromNative((object) $data, self::ID, $operation, $target);

        self::assertSame($data['status'], $result->status);
        self::assertSame($data['operation'], $result->operation);
        self::assertSame($data['target'], $result->target);
        self::assertSame($data['accepted'], $result->accepted);
        self::assertSame($data['success'], $result->success);
        self::assertSame($data['errorCode'], $result->errorCode);
        self::assertSame(
            $data['errorCode'] === null ? null : ErrorCode::message($data['errorCode']),
            $result->errorMessage,
        );

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Synthetic private event details', $json);
        self::assertSame($result->toMetadata(), json_decode($json, true, 16, JSON_THROW_ON_ERROR));

        foreach (['title', 'description', 'location', 'dateMs', 'eventId', 'recurrence'] as $key) {
            self::assertArrayNotHasKey($key, $result->toMetadata());
        }
    }

    public static function invalidResults(): iterable
    {
        $patches = [
            'different ID' => ['id' => 'b1111111-b111-4111-8111-c11111111111'],
            'invalid ID' => ['id' => 'Synthetic private event details'],
            'wrong operation' => ['operation' => 'open', 'target' => 'date'],
            'operation wrong type' => ['operation' => []],
            'wrong target' => ['target' => 'event'],
            'target wrong type' => ['target' => []],
            'pending not accepted' => ['accepted' => false],
            'accepted wrong type' => ['accepted' => 1],
            'success wrong type' => ['success' => 'false'],
            'pending success' => ['success' => true],
            'pending with error' => ['errorCode' => ErrorCode::LAUNCH_FAILED],
            'pending completed' => ['completedAtMs' => 2000],
            'missing creation time' => ['createdAtMs' => null],
            'creation string' => ['createdAtMs' => '1000'],
            'creation float' => ['createdAtMs' => 1000.0],
            'negative creation' => ['createdAtMs' => -1],
            'oversized creation' => ['createdAtMs' => 253402300800000],
            'saved is not a launch state' => ['status' => 'saved'],
            'selected is not supported' => ['status' => 'selected'],
            'cancelled is not observed' => ['status' => 'cancelled'],
            'status wrong type' => ['status' => []],
            'unknown error code' => ['errorCode' => 'Synthetic private event details'],
            'launched missing success' => ['status' => 'launched', 'completedAtMs' => 2000],
            'launched missing completion' => ['status' => 'launched', 'success' => true],
            'launched reversed times' => ['status' => 'launched', 'success' => true, 'completedAtMs' => 999],
            'launched with error' => [
                'status' => 'launched', 'success' => true, 'completedAtMs' => 2000,
                'errorCode' => ErrorCode::LAUNCH_FAILED,
            ],
            'unknown without interruption' => ['status' => 'unknown', 'completedAtMs' => 2000],
            'unknown without completion' => ['status' => 'unknown', 'errorCode' => ErrorCode::INTERRUPTED],
            'failed without error' => ['status' => 'failed', 'completedAtMs' => 2000],
            'failed missing completion' => ['status' => 'failed', 'errorCode' => ErrorCode::LAUNCH_FAILED],
            'unaccepted failure with stored times' => [
                'status' => 'failed', 'accepted' => false,
                'errorCode' => ErrorCode::LAUNCH_FAILED, 'completedAtMs' => 2000,
            ],
            'failed with not-found code' => [
                'status' => 'failed', 'errorCode' => ErrorCode::RESULT_NOT_FOUND,
                'completedAtMs' => 2000,
            ],
            'unexpected event field' => ['title' => 'Synthetic private event details'],
            'oversized message' => ['errorMessage' => str_repeat('x', 17000)],
            'invalid UTF8 message' => ['errorMessage' => "\xC3\x28"],
        ];

        foreach ($patches as $name => $patch) {
            yield $name => [array_replace(self::pending(), $patch)];
        }

        foreach (array_keys(self::pending()) as $key) {
            $data = self::pending();
            unset($data[$key]);
            yield 'missing '.$key => [$data];
        }
    }

    #[DataProvider('invalidResults')]
    public function test_rejects_invalid_results_without_exposing_contents(array $data): void
    {
        $result = Result::fromNative((object) $data, self::ID, 'create_event', 'editor');

        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertSame('failed', $result->status);
        self::assertFalse($result->accepted);
        self::assertFalse($result->success);
        self::assertSame(self::ID, $result->id);
        self::assertStringNotContainsString(
            'Synthetic private event details',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_missing_bridge_response_is_controlled(): void
    {
        $result = Result::fromNative(null, self::ID, 'open', 'date');

        self::assertSame(ErrorCode::BRIDGE_UNAVAILABLE, $result->errorCode);
        self::assertSame('open', $result->operation);
        self::assertSame('date', $result->target);
        self::assertFalse($result->accepted);
    }

    public function test_lookup_rejects_unknown_operation(): void
    {
        $data = array_replace(self::pending(), [
            'operation' => 'delete_event', 'target' => 'editor',
        ]);

        self::assertSame(
            ErrorCode::INVALID_NATIVE_RESPONSE,
            Result::fromNative((object) $data, self::ID)->errorCode,
        );
    }

    public function test_not_found_is_rejected_as_a_create_acknowledgement(): void
    {
        $data = array_replace(self::pending(), [
            'operation' => null, 'target' => null, 'status' => 'not_found',
            'accepted' => false, 'errorCode' => ErrorCode::RESULT_NOT_FOUND,
            'createdAtMs' => null,
        ]);

        self::assertSame(
            ErrorCode::INVALID_NATIVE_RESPONSE,
            Result::fromNative((object) $data, self::ID, 'create_event', 'editor')->errorCode,
        );
    }

    public function test_failure_factory_discards_untrusted_identifiers_and_error_text(): void
    {
        $result = Result::failure(
            'Synthetic private event details',
            'Synthetic private event details',
        );

        self::assertSame('00000000-0000-4000-8000-000000000000', $result->id);
        self::assertSame(ErrorCode::UNKNOWN_ERROR, $result->errorCode);
        self::assertSame(ErrorCode::message(ErrorCode::UNKNOWN_ERROR), $result->errorMessage);
        self::assertStringNotContainsString(
            'Synthetic private event details',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }
}
