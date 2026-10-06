<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Tests;

use Bbs\NativeContacts\Events\NativeContactsCompleted;
use Bbs\NativeContacts\Support\NativeContactsErrorCode as ErrorCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeContactsCompletedTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    #[DataProvider('completions')]
    public function test_accepts_native_named_metadata(array $changes): void
    {
        $payload = $this->payload($changes);

        // NativePHP constructs event classes using named payload keys.
        $event = new NativeContactsCompleted(...$payload);

        self::assertSame(self::ID, $event->id);
        self::assertSame($payload['status'], $event->status);
        self::assertSame($payload['operation'], $event->operation);

        $metadata = json_decode(
            json_encode($event, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame($event->toMetadata(), $metadata);

        foreach ([
            'selection',
            'displayName',
            'contactUri',
            'phoneNumber',
            'emailAddress',
        ] as $privateField) {
            self::assertArrayNotHasKey($privateField, $metadata);
        }
    }

    public static function completions(): iterable
    {
        yield 'selected contact' => [['mode' => 'contact']];
        yield 'selected phone' => [['mode' => 'phone']];
        yield 'selected email' => [['mode' => 'email']];

        yield 'create editor launched' => [[
            'operation' => 'create',
            'mode' => null,
            'status' => 'launched',
        ]];

        yield 'contact viewer launched' => [[
            'operation' => 'open',
            'mode' => null,
            'status' => 'launched',
        ]];

        yield 'picker cancelled' => [[
            'status' => 'cancelled',
            'success' => false,
            'cancelled' => true,
        ]];

        yield 'persisted failure' => [[
            'status' => 'failed',
            'success' => false,
            'errorCode' => ErrorCode::CONTACT_READ_FAILED,
        ]];

        yield 'interrupted operation' => [[
            'status' => 'unknown',
            'success' => false,
            'errorCode' => ErrorCode::OPERATION_INTERRUPTED,
        ]];
    }

    #[DataProvider('invalidCompletions')]
    public function test_rejects_invalid_completion_metadata(
        array $changes,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid contacts completion metadata.',
        );

        new NativeContactsCompleted(...$this->payload($changes));
    }

    public static function invalidCompletions(): iterable
    {
        yield 'invalid request ID' => [['id' => 'private-event-detail']];
        yield 'pending notification' => [['status' => 'pending']];
        yield 'lookup result notification' => [['status' => 'not_found']];
        yield 'invalid picker mode' => [['mode' => 'all']];

        yield 'selected create operation' => [[
            'operation' => 'create',
            'mode' => null,
        ]];

        yield 'selected result without success' => [['success' => false]];
        yield 'selected result claiming cancellation' => [['cancelled' => true]];

        yield 'consumed failure' => [[
            'status' => 'failed',
            'success' => false,
            'consumed' => true,
            'errorCode' => ErrorCode::RESULT_PERSISTENCE_FAILED,
        ]];

        yield 'unknown native error' => [[
            'status' => 'failed',
            'success' => false,
            'errorCode' => 'private-event-detail',
        ]];

        yield 'missing creation timestamp' => [['createdAtMs' => null]];
        yield 'missing completion timestamp' => [['completedAtMs' => null]];
        yield 'reversed timestamps' => [['completedAtMs' => 999]];
    }

    public function test_native_error_details_are_not_retained(): void
    {
        $event = new NativeContactsCompleted(...$this->payload([
            'status' => 'failed',
            'success' => false,
            'errorCode' => ErrorCode::RESULT_PERSISTENCE_FAILED,
            'errorMessage' => 'private-event-detail',
        ]));

        self::assertSame(
            ErrorCode::message(ErrorCode::RESULT_PERSISTENCE_FAILED),
            $event->errorMessage,
        );

        self::assertStringNotContainsString(
            'private-event-detail',
            json_encode($event, JSON_THROW_ON_ERROR),
        );

        self::assertStringNotContainsString(
            'private-event-detail',
            serialize($event),
        );
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'id' => self::ID,
            'operation' => 'pick',
            'mode' => 'phone',
            'status' => 'selected',
            'success' => true,
            'cancelled' => false,
            'consumed' => false,
            'createdAtMs' => 1000,
            'completedAtMs' => 2000,
        ], $changes);
    }
}