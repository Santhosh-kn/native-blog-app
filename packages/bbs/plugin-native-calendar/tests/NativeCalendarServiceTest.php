<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Tests;

use Bbs\NativeCalendar\Contracts\NativeBridge;
use Bbs\NativeCalendar\NativeCalendar;
use Bbs\NativeCalendar\NativeCalendarServiceProvider;
use Bbs\NativeCalendar\Support\NativeCalendarErrorCode as ErrorCode;
use Bbs\NativeCalendar\Support\NativeCalendarRequestValidator as Validator;
use Bbs\NativeCalendar\Support\NativePhpBridge;
use Closure;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CalendarRecordingBridge implements NativeBridge
{
    public array $calls = [];

    public function __construct(private readonly Closure $reply) {}

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = [$method, $parameters];

        return ($this->reply)($method, $parameters);
    }
}

final class NativeCalendarServiceTest extends TestCase
{
    private const ID = 'a1111111-b111-4111-8111-c11111111111';

    private static function event(): array
    {
        return [
            'title' => 'Synthetic private event title',
            'startTimeMs' => 1000,
            'endTimeMs' => 2000,
        ];
    }

    private static function pending(string $id, string $operation, string $target): object
    {
        return (object) [
            'id' => $id, 'operation' => $operation, 'target' => $target,
            'status' => 'pending', 'accepted' => true, 'success' => false,
            'errorCode' => null, 'errorMessage' => null,
            'createdAtMs' => 1000, 'completedAtMs' => null,
        ];
    }

    private static function readiness(): array
    {
        return [
            'available' => true, 'platform' => 'android',
            'apiLevel' => 36, 'minimumApiLevel' => 33,
            'capabilities' => (object) [
                'createEvent' => true, 'openDate' => true, 'openEvent' => true,
            ],
            'errorCode' => null,
            'errorMessage' => 'Synthetic private event title',
        ];
    }

    private function service(Closure $reply): array
    {
        $bridge = new CalendarRecordingBridge($reply);

        return [new NativeCalendar($bridge), $bridge];
    }

    public function test_create_generates_an_id_and_normalizes_default_all_day(): void
    {
        [$service, $bridge] = $this->service(
            static fn (string $method, array $parameters): object =>
                self::pending($parameters['id'], 'create_event', 'editor'),
        );

        $result = $service->createEvent(self::event());

        self::assertTrue($result->accepted);
        self::assertTrue(Validator::isRequestId($result->id));
        self::assertCount(1, $bridge->calls);
        self::assertSame('NativeCalendar.CreateEvent', $bridge->calls[0][0]);
        self::assertSame(
            self::event() + ['id' => $result->id, 'allDay' => false],
            $bridge->calls[0][1],
        );
        self::assertStringNotContainsString(
            self::event()['title'],
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_create_preserves_valid_prefills_and_explicit_id(): void
    {
        [$service, $bridge] = $this->service(
            static fn (string $method, array $parameters): object =>
                self::pending($parameters['id'], 'create_event', 'editor'),
        );

        $options = self::event() + [
            'id' => self::ID,
            'description' => 'Synthetic description',
            'location' => 'Synthetic location',
            'timeZone' => 'Asia/Kolkata',
            'recurrence' => 'FREQ=WEEKLY;COUNT=10',
        ];

        $result = $service->createEvent($options);

        self::assertSame(self::ID, $result->id);
        self::assertSame($options + ['allDay' => false], $bridge->calls[0][1]);
        self::assertTrue($result->accepted);

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['Synthetic description', 'Synthetic location', 'Asia/Kolkata', 'FREQ=WEEKLY'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }

    public function test_all_day_default_zone_is_normalized_before_the_native_call(): void
    {
        [$service, $bridge] = $this->service(
            static fn (string $method, array $parameters): object =>
                self::pending($parameters['id'], 'create_event', 'editor'),
        );

        $result = $service->createEvent([
            'title' => 'Demo', 'startTimeMs' => 0,
            'endTimeMs' => Validator::DAY_MS, 'allDay' => true,
        ]);

        self::assertTrue($result->accepted);
        self::assertSame('UTC', $bridge->calls[0][1]['timeZone']);
        self::assertTrue($bridge->calls[0][1]['allDay']);
    }

    public static function invalidCreates(): iterable
    {
        $cases = [
            'unknown option' => [['calendarId' => 1], ErrorCode::INVALID_OPTIONS],
            'explicit null ID' => [['id' => null], ErrorCode::INVALID_REQUEST_ID],
            'invalid ID' => [['id' => 'demo'], ErrorCode::INVALID_REQUEST_ID],
            'blank title' => [['title' => ''], ErrorCode::INVALID_TITLE],
            'string time' => [['startTimeMs' => '1000'], ErrorCode::INVALID_TIME_RANGE],
            'equal times' => [['endTimeMs' => 1000], ErrorCode::INVALID_TIME_RANGE],
            'all day boundary' => [['allDay' => true], ErrorCode::INVALID_ALL_DAY],
            'invalid zone' => [['timeZone' => 'Invalid/Demo'], ErrorCode::INVALID_TIME_ZONE],
            'unsupported recurrence' => [['recurrence' => 'FREQ=HOURLY'], ErrorCode::INVALID_RECURRENCE],
            'oversized request' => [['description' => str_repeat("\u{0C95}", 1500)], ErrorCode::REQUEST_TOO_LARGE],
        ];

        foreach ($cases as $name => [$patch, $error]) {
            yield $name => [array_replace(self::event(), $patch), $error];
        }
    }

    #[DataProvider('invalidCreates')]
    public function test_invalid_create_does_not_reach_native(array $options, string $error): void
    {
        [$service, $bridge] = $this->service(static fn (): ?object => null);

        self::assertSame($error, $service->createEvent($options)->errorCode);
        self::assertSame([], $bridge->calls);
    }

    public static function viewerTargets(): iterable
    {
        yield 'date' => [['dateMs' => 1000], 'date'];
        yield 'event' => [['eventId' => 1], 'event'];
        yield 'occurrence' => [[
            'eventId' => 1, 'startTimeMs' => 1000, 'endTimeMs' => 2000,
        ], 'event'];
    }

    #[DataProvider('viewerTargets')]
    public function test_open_forwards_valid_targets(array $options, string $target): void
    {
        [$service, $bridge] = $this->service(
            static fn (string $method, array $parameters): object =>
                self::pending($parameters['id'], 'open', $target),
        );

        $result = $service->open($options + ['id' => self::ID]);

        self::assertTrue($result->accepted);
        self::assertSame($target, $result->target);
        self::assertSame([
            ['NativeCalendar.Open', $options + ['id' => self::ID]],
        ], $bridge->calls);
    }

    public static function invalidOpens(): iterable
    {
        yield 'missing target' => [[], ErrorCode::INVALID_TARGET];
        yield 'both targets' => [['dateMs' => 0, 'eventId' => 1], ErrorCode::INVALID_TARGET];
        yield 'string event ID' => [['eventId' => '1'], ErrorCode::INVALID_EVENT_ID];
        yield 'float date' => [['dateMs' => 1000.0], ErrorCode::INVALID_TARGET];
        yield 'partial occurrence' => [['eventId' => 1, 'startTimeMs' => 1000], ErrorCode::INVALID_TIME_RANGE];
        yield 'arbitrary URI' => [['uri' => 'content://demo'], ErrorCode::INVALID_OPTIONS];
    }

    #[DataProvider('invalidOpens')]
    public function test_invalid_open_does_not_reach_native(array $options, string $error): void
    {
        [$service, $bridge] = $this->service(static fn (): ?object => null);

        self::assertSame($error, $service->open($options)->errorCode);
        self::assertSame([], $bridge->calls);
    }

    public function test_status_uses_only_the_owned_request_identifier(): void
    {
        [$service, $bridge] = $this->service(static fn (): object => (object) [
            'id' => self::ID, 'operation' => null, 'target' => null,
            'status' => 'not_found', 'accepted' => false, 'success' => false,
            'errorCode' => ErrorCode::RESULT_NOT_FOUND,
            'errorMessage' => 'Synthetic private event title',
            'createdAtMs' => null, 'completedAtMs' => null,
        ]);

        $result = $service->getStatus(self::ID);

        self::assertSame(ErrorCode::RESULT_NOT_FOUND, $result->errorCode);
        self::assertSame([['NativeCalendar.GetStatus', ['id' => self::ID]]], $bridge->calls);
        self::assertSame(ErrorCode::message(ErrorCode::RESULT_NOT_FOUND), $result->errorMessage);
    }

    public static function invalidStatusIds(): iterable
    {
        yield 'null' => [null];
        yield 'array' => [[]];
        yield 'malformed' => ['Synthetic private event title'];
        yield 'uppercase' => [strtoupper(self::ID)];
    }

    #[DataProvider('invalidStatusIds')]
    public function test_invalid_status_id_does_not_reach_native(mixed $id): void
    {
        [$service, $bridge] = $this->service(static fn (): ?object => null);

        self::assertSame(ErrorCode::INVALID_REQUEST_ID, $service->getStatus($id)->errorCode);
        self::assertSame([], $bridge->calls);
    }

    public function test_create_rejects_an_acknowledgement_for_another_request(): void
    {
        [$service] = $this->service(static fn (): object =>
            self::pending('b1111111-b111-4111-8111-c11111111111', 'create_event', 'editor'),
        );

        self::assertSame(
            ErrorCode::INVALID_NATIVE_RESPONSE,
            $service->createEvent(self::event() + ['id' => self::ID])->errorCode,
        );
    }

    public function test_open_rejects_an_acknowledgement_for_another_target(): void
    {
        [$service] = $this->service(static fn (): object =>
            self::pending(self::ID, 'open', 'event'),
        );

        self::assertSame(
            ErrorCode::INVALID_NATIVE_RESPONSE,
            $service->open(['id' => self::ID, 'dateMs' => 1000])->errorCode,
        );
    }

    public function test_availability_supports_partial_capabilities_and_controls_messages(): void
    {
        $data = self::readiness();
        $data['capabilities']->openEvent = false;
        [$service, $bridge] = $this->service(static fn (): object => (object) $data);

        $result = $service->isAvailable();

        self::assertTrue($result->available);
        self::assertFalse($result->capabilities->openEvent);
        self::assertNull($result->errorMessage);
        self::assertSame([['NativeCalendar.IsAvailable', []]], $bridge->calls);
    }

    public function test_unavailable_calendar_uses_a_controlled_error_message(): void
    {
        $data = self::readiness();
        $data['available'] = false;
        $data['capabilities'] = (object) [
            'createEvent' => false, 'openDate' => false, 'openEvent' => false,
        ];
        $data['errorCode'] = ErrorCode::NO_CALENDAR_APP;
        [$service] = $this->service(static fn (): object => (object) $data);

        $result = $service->isAvailable();

        self::assertFalse($result->available);
        self::assertSame(ErrorCode::message(ErrorCode::NO_CALENDAR_APP), $result->errorMessage);
    }

    public static function invalidAvailability(): iterable
    {
        $patches = [
            'wrong platform' => ['platform' => 'ios'],
            'wrong minimum SDK' => ['minimumApiLevel' => 32],
            'SDK string' => ['apiLevel' => '36'],
            'old SDK with capabilities' => ['apiLevel' => 32],
            'inconsistent available flag' => ['available' => false],
            'capabilities array' => ['capabilities' => []],
            'capability wrong type' => ['capabilities' => (object) [
                'createEvent' => 'true', 'openDate' => true, 'openEvent' => true,
            ]],
            'capability missing' => ['capabilities' => (object) ['createEvent' => true]],
            'unknown error' => ['errorCode' => 'Synthetic private event title'],
            'unexpected event detail' => ['title' => 'Synthetic private event title'],
            'oversized response' => ['errorMessage' => str_repeat('x', 17000)],
        ];

        foreach ($patches as $name => $patch) {
            yield $name => [array_replace(self::readiness(), $patch)];
        }
    }

    #[DataProvider('invalidAvailability')]
    public function test_invalid_availability_is_controlled(array $data): void
    {
        [$service] = $this->service(static fn (): object => (object) $data);
        $result = $service->isAvailable();

        self::assertFalse($result->available);
        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertStringNotContainsString(
            'Synthetic private event title',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public static function methodsWithExceptions(): iterable
    {
        foreach (['isAvailable', 'createEvent', 'open', 'getStatus'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('methodsWithExceptions')]
    public function test_native_exceptions_are_controlled_without_printing(string $method): void
    {
        [$service] = $this->service(static function (): never {
            throw new RuntimeException('Synthetic private event title');
        });

        ob_start();
        try {
            $result = match ($method) {
                'isAvailable' => $service->isAvailable(),
                'createEvent' => $service->createEvent(self::event()),
                'open' => $service->open(['dateMs' => 1000]),
                'getStatus' => $service->getStatus(self::ID),
            };
        } finally {
            $output = ob_get_clean();
        }

        self::assertSame('', $output);
        self::assertSame(ErrorCode::BRIDGE_UNAVAILABLE, $result->errorCode);
        self::assertStringNotContainsString(
            'Synthetic private event title',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_provider_resolves_default_bridge_and_singleton_service(): void
    {
        $app = new Container();
        (new NativeCalendarServiceProvider($app))->register();

        self::assertInstanceOf(NativePhpBridge::class, $app->make(NativeBridge::class));
        self::assertSame($app->make(NativeCalendar::class), $app->make(NativeCalendar::class));
    }

    public function test_provider_uses_an_injected_bridge(): void
    {
        $app = new Container();
        (new NativeCalendarServiceProvider($app))->register();

        $bridge = new CalendarRecordingBridge(static fn (): ?object => null);
        $app->instance(NativeBridge::class, $bridge);

        $result = $app->make(NativeCalendar::class)->getStatus(self::ID);

        self::assertSame(ErrorCode::BRIDGE_UNAVAILABLE, $result->errorCode);
        self::assertSame([['NativeCalendar.GetStatus', ['id' => self::ID]]], $bridge->calls);
    }
}
