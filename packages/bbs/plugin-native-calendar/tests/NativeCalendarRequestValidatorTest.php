<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Tests;

use Bbs\NativeCalendar\Support\NativeCalendarErrorCode as ErrorCode;
use Bbs\NativeCalendar\Support\NativeCalendarRequestValidator as Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeCalendarRequestValidatorTest extends TestCase
{
    private const ID = 'a1111111-b111-4111-8111-c11111111111';

    private static function createOptions(): array
    {
        return [
            'id' => self::ID,
            'title' => 'Demo Calendar Event',
            'startTimeMs' => 1000,
            'endTimeMs' => 2000,
            'allDay' => false,
        ];
    }

    public static function validCreateOptions(): iterable
    {
        yield 'minimal' => [self::createOptions()];
        yield 'unicode title boundary' => [array_replace(self::createOptions(), [
            'title' => str_repeat("\u{1F600}", 120),
        ])];
        yield 'text boundaries' => [array_replace(self::createOptions(), [
            'title' => str_repeat('x', 120),
            'description' => str_repeat('x', 2048),
            'location' => str_repeat('x', 240),
        ])];
        yield 'multiline description' => [array_replace(self::createOptions(), [
            'description' => "Line one\r\nLine two\tDemo",
            'location' => '',
            'timeZone' => 'Asia/Kolkata',
            'recurrence' => 'FREQ=WEEKLY;INTERVAL=2;COUNT=10',
        ])];
        yield 'all day UTC' => [array_replace(self::createOptions(), [
            'startTimeMs' => 0,
            'endTimeMs' => Validator::DAY_MS,
            'allDay' => true,
            'timeZone' => 'UTC',
        ])];
        yield 'all day without explicit zone' => [array_replace(self::createOptions(), [
            'startTimeMs' => 0,
            'endTimeMs' => Validator::DAY_MS * 2,
            'allDay' => true,
        ])];
        yield 'maximum timestamp' => [array_replace(self::createOptions(), [
            'startTimeMs' => Validator::MAX_EPOCH_MS - 1,
            'endTimeMs' => Validator::MAX_EPOCH_MS,
        ])];
    }

    #[DataProvider('validCreateOptions')]
    public function test_accepts_valid_create_options(array $options): void
    {
        self::assertNull(Validator::validateCreate($options));
    }

    public static function invalidCreateOptions(): iterable
    {
        $cases = [
            'unknown key' => [['calendarId' => 1], ErrorCode::INVALID_OPTIONS],
            'null ID' => [['id' => null], ErrorCode::INVALID_REQUEST_ID],
            'uppercase ID' => [['id' => strtoupper(self::ID)], ErrorCode::INVALID_REQUEST_ID],
            'wrong UUID version' => [['id' => 'a1111111-b111-5111-8111-c11111111111'], ErrorCode::INVALID_REQUEST_ID],
            'empty title' => [['title' => ''], ErrorCode::INVALID_TITLE],
            'blank title' => [['title' => " \t"], ErrorCode::INVALID_TITLE],
            'unicode blank title' => [['title' => "\u{00A0}"], ErrorCode::INVALID_TITLE],
            'title wrong type' => [['title' => []], ErrorCode::INVALID_TITLE],
            'title newline' => [['title' => "Demo\nEvent"], ErrorCode::INVALID_TITLE],
            'title too long' => [['title' => str_repeat("\u{1F600}", 121)], ErrorCode::INVALID_TITLE],
            'title invalid UTF8' => [['title' => "\xC3\x28"], ErrorCode::INVALID_TITLE],
            'start string' => [['startTimeMs' => '1000'], ErrorCode::INVALID_TIME_RANGE],
            'start float' => [['startTimeMs' => 1000.0], ErrorCode::INVALID_TIME_RANGE],
            'start boolean' => [['startTimeMs' => true], ErrorCode::INVALID_TIME_RANGE],
            'negative start' => [['startTimeMs' => -1], ErrorCode::INVALID_TIME_RANGE],
            'equal times' => [['endTimeMs' => 1000], ErrorCode::INVALID_TIME_RANGE],
            'reversed times' => [['endTimeMs' => 999], ErrorCode::INVALID_TIME_RANGE],
            'end beyond maximum' => [['endTimeMs' => Validator::MAX_EPOCH_MS + 1], ErrorCode::INVALID_TIME_RANGE],
            'all day string' => [['allDay' => 'true'], ErrorCode::INVALID_ALL_DAY],
            'all day null' => [['allDay' => null], ErrorCode::INVALID_ALL_DAY],
            'all day non-midnight start' => [['allDay' => true], ErrorCode::INVALID_ALL_DAY],
            'all day non-midnight end' => [[
                'allDay' => true, 'startTimeMs' => 0, 'endTimeMs' => Validator::DAY_MS + 1,
            ], ErrorCode::INVALID_ALL_DAY],
            'all day non-UTC zone' => [[
                'allDay' => true, 'startTimeMs' => 0,
                'endTimeMs' => Validator::DAY_MS, 'timeZone' => 'Asia/Kolkata',
            ], ErrorCode::INVALID_TIME_ZONE],
            'description wrong type' => [['description' => []], ErrorCode::INVALID_DESCRIPTION],
            'description too long' => [['description' => str_repeat('x', 2049)], ErrorCode::INVALID_DESCRIPTION],
            'description control' => [['description' => "Demo\x00Event"], ErrorCode::INVALID_DESCRIPTION],
            'description invalid UTF8' => [['description' => "\xC3\x28"], ErrorCode::INVALID_DESCRIPTION],
            'location newline' => [['location' => "Demo\nPlace"], ErrorCode::INVALID_LOCATION],
            'location too long' => [['location' => str_repeat('x', 241)], ErrorCode::INVALID_LOCATION],
            'location null' => [['location' => null], ErrorCode::INVALID_LOCATION],
            'unknown zone' => [['timeZone' => 'Invalid/Demo'], ErrorCode::INVALID_TIME_ZONE],
            'zone offset' => [['timeZone' => '+05:30'], ErrorCode::INVALID_TIME_ZONE],
            'zone wrong type' => [['timeZone' => []], ErrorCode::INVALID_TIME_ZONE],
            'zone empty' => [['timeZone' => ''], ErrorCode::INVALID_TIME_ZONE],
            'recurrence null' => [['recurrence' => null], ErrorCode::INVALID_RECURRENCE],
            'request byte overflow' => [['description' => str_repeat("\u{0C95}", 1500)], ErrorCode::REQUEST_TOO_LARGE],
        ];

        foreach ($cases as $name => [$patch, $error]) {
            yield $name => [array_replace(self::createOptions(), $patch), $error];
        }

        foreach ([
            'id' => ErrorCode::INVALID_REQUEST_ID,
            'title' => ErrorCode::INVALID_TITLE,
            'startTimeMs' => ErrorCode::INVALID_TIME_RANGE,
            'endTimeMs' => ErrorCode::INVALID_TIME_RANGE,
        ] as $key => $error) {
            $options = self::createOptions();
            unset($options[$key]);
            yield 'missing '.$key => [$options, $error];
        }
    }

    #[DataProvider('invalidCreateOptions')]
    public function test_rejects_invalid_create_options(array $options, string $error): void
    {
        self::assertSame($error, Validator::validateCreate($options));
    }

    public static function recurrenceRules(): iterable
    {
        foreach ([
            'FREQ=DAILY',
            'FREQ=WEEKLY;COUNT=10',
            'FREQ=MONTHLY;INTERVAL=2',
            'FREQ=YEARLY;INTERVAL=365;COUNT=1000',
        ] as $index => $rule) {
            yield 'valid '.$index => [$rule, true];
        }

        foreach ([
            '', 'freq=DAILY', 'FREQ=HOURLY', 'FREQ=MINUTELY',
            'FREQ=DAILY;INTERVAL=0', 'FREQ=DAILY;INTERVAL=01',
            'FREQ=DAILY;INTERVAL=366', 'FREQ=DAILY;COUNT=0',
            'FREQ=DAILY;COUNT=1001', 'FREQ=DAILY;COUNT=01',
            'FREQ=DAILY;COUNT=10;INTERVAL=2', 'FREQ=DAILY;FREQ=WEEKLY',
            'FREQ=WEEKLY;BYDAY=MO', 'FREQ=DAILY;UNTIL=20270101T000000Z',
            "FREQ=DAILY\n", false, [], null,
        ] as $index => $rule) {
            yield 'invalid '.$index => [$rule, false];
        }
    }

    #[DataProvider('recurrenceRules')]
    public function test_recurrence_subset(mixed $rule, bool $valid): void
    {
        self::assertSame($valid, Validator::isRecurrence($rule));
    }

    public static function viewerOptions(): iterable
    {
        $base = ['id' => self::ID];

        yield 'epoch date' => [$base + ['dateMs' => 0], null];
        yield 'maximum date' => [$base + ['dateMs' => Validator::MAX_EPOCH_MS], null];
        yield 'event' => [$base + ['eventId' => 1], null];
        yield 'maximum event ID' => [$base + ['eventId' => Validator::MAX_EVENT_ID], null];
        yield 'event occurrence' => [$base + [
            'eventId' => 1, 'startTimeMs' => 1000, 'endTimeMs' => 2000,
        ], null];
        yield 'no target' => [$base, ErrorCode::INVALID_TARGET];
        yield 'conflicting targets' => [$base + ['dateMs' => 0, 'eventId' => 1], ErrorCode::INVALID_TARGET];
        yield 'date with occurrence' => [$base + ['dateMs' => 0, 'startTimeMs' => 1000], ErrorCode::INVALID_TARGET];
        yield 'unexpected URI' => [$base + ['dateMs' => 0, 'uri' => 'content://demo'], ErrorCode::INVALID_OPTIONS];
        yield 'invalid ID' => [['id' => 'demo', 'dateMs' => 0], ErrorCode::INVALID_REQUEST_ID];

        foreach ([null, -1, '1000', 1000.0, true, Validator::MAX_EPOCH_MS + 1] as $index => $date) {
            yield 'invalid date '.$index => [$base + ['dateMs' => $date], ErrorCode::INVALID_TARGET];
        }

        foreach ([null, 0, -1, '1', 1.0, true, Validator::MAX_EVENT_ID + 1] as $index => $id) {
            yield 'invalid event '.$index => [$base + ['eventId' => $id], ErrorCode::INVALID_EVENT_ID];
        }

        yield 'missing occurrence end' => [$base + [
            'eventId' => 1, 'startTimeMs' => 1000,
        ], ErrorCode::INVALID_TIME_RANGE];
        yield 'missing occurrence start' => [$base + [
            'eventId' => 1, 'endTimeMs' => 2000,
        ], ErrorCode::INVALID_TIME_RANGE];
        yield 'reversed occurrence' => [$base + [
            'eventId' => 1, 'startTimeMs' => 2000, 'endTimeMs' => 1000,
        ], ErrorCode::INVALID_TIME_RANGE];
    }

    #[DataProvider('viewerOptions')]
    public function test_calendar_viewer_validation(array $options, ?string $error): void
    {
        self::assertSame($error, Validator::validateOpen($options));
    }

    public static function requestSizeOffsets(): iterable
    {
        yield 'exact limit' => [0, null];
        yield 'above limit' => [1, ErrorCode::REQUEST_TOO_LARGE];
    }

    #[DataProvider('requestSizeOffsets')]
    public function test_complete_request_byte_boundary(int $offset, ?string $error): void
    {
        $options = self::createOptions();
        $options['description'] = str_repeat("\u{0C95}", 1300);
        $length = strlen(json_encode(
            $options,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        self::assertLessThan(8192, $length);
        $options['description'] .= str_repeat('x', 8192 - $length + $offset);

        self::assertSame(8192 + $offset, strlen(json_encode(
            $options,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )));
        self::assertSame($error, Validator::validateCreate($options));
    }
}
