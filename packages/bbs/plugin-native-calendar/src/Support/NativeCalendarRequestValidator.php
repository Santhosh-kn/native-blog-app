<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Support;

use DateTimeZone;
use Throwable;

final class NativeCalendarRequestValidator
{
    public const MAX_REQUEST_BYTES = 8192;
    public const MAX_TITLE_CODE_POINTS = 120;
    public const MAX_DESCRIPTION_CODE_POINTS = 2048;
    public const MAX_LOCATION_CODE_POINTS = 240;
    public const MAX_TIME_ZONE_BYTES = 64;
    public const MAX_RECURRENCE_BYTES = 128;
    public const MAX_EPOCH_MS = 253402300799999;
    public const MAX_EVENT_ID = 9007199254740991;
    public const DAY_MS = 86400000;

    /** @var array<string, bool>|null */
    private static ?array $timeZones = null;

    /** @param array<string, mixed> $options */
    public static function validateCreate(array $options): ?string
    {
        if (! self::hasOnlyKeys($options, [
            'id', 'title', 'startTimeMs', 'endTimeMs',
            'allDay', 'description', 'location', 'timeZone', 'recurrence',
        ])) {
            return NativeCalendarErrorCode::INVALID_OPTIONS;
        }

        if (! self::isRequestId($options['id'] ?? null)) {
            return NativeCalendarErrorCode::INVALID_REQUEST_ID;
        }

        if (! self::isText(
            $options['title'] ?? null,
            self::MAX_TITLE_CODE_POINTS,
            false,
            false,
        )) {
            return NativeCalendarErrorCode::INVALID_TITLE;
        }

        $start = $options['startTimeMs'] ?? null;
        $end = $options['endTimeMs'] ?? null;

        if (! self::isTimeRange($start, $end)) {
            return NativeCalendarErrorCode::INVALID_TIME_RANGE;
        }

        if (
            array_key_exists('allDay', $options) &&
            ! is_bool($options['allDay'])
        ) {
            return NativeCalendarErrorCode::INVALID_ALL_DAY;
        }

        $allDay = $options['allDay'] ?? false;

        // All-day dates use UTC midnight boundaries and an exclusive end.
        if (
            $allDay &&
            ($start % self::DAY_MS !== 0 || $end % self::DAY_MS !== 0)
        ) {
            return NativeCalendarErrorCode::INVALID_ALL_DAY;
        }

        if (
            array_key_exists('description', $options) &&
            ! self::isText(
                $options['description'],
                self::MAX_DESCRIPTION_CODE_POINTS,
                true,
                true,
            )
        ) {
            return NativeCalendarErrorCode::INVALID_DESCRIPTION;
        }

        if (
            array_key_exists('location', $options) &&
            ! self::isText(
                $options['location'],
                self::MAX_LOCATION_CODE_POINTS,
                true,
                false,
            )
        ) {
            return NativeCalendarErrorCode::INVALID_LOCATION;
        }

        if (array_key_exists('timeZone', $options)) {
            if (
                ! self::isTimeZone($options['timeZone']) ||
                ($allDay && $options['timeZone'] !== 'UTC')
            ) {
                return NativeCalendarErrorCode::INVALID_TIME_ZONE;
            }
        }

        if (
            array_key_exists('recurrence', $options) &&
            ! self::isRecurrence($options['recurrence'])
        ) {
            return NativeCalendarErrorCode::INVALID_RECURRENCE;
        }

        return self::sizeError($options);
    }

    /** @param array<string, mixed> $options */
    public static function validateOpen(array $options): ?string
    {
        if (! self::hasOnlyKeys($options, [
            'id', 'dateMs', 'eventId', 'startTimeMs', 'endTimeMs',
        ])) {
            return NativeCalendarErrorCode::INVALID_OPTIONS;
        }

        if (! self::isRequestId($options['id'] ?? null)) {
            return NativeCalendarErrorCode::INVALID_REQUEST_ID;
        }

        $hasDate = array_key_exists('dateMs', $options);
        $hasEvent = array_key_exists('eventId', $options);

        if ($hasDate === $hasEvent) {
            return NativeCalendarErrorCode::INVALID_TARGET;
        }

        $hasStart = array_key_exists('startTimeMs', $options);
        $hasEnd = array_key_exists('endTimeMs', $options);

        if ($hasDate) {
            if (
                ! self::isEpochMilliseconds($options['dateMs']) ||
                $hasStart ||
                $hasEnd
            ) {
                return NativeCalendarErrorCode::INVALID_TARGET;
            }
        } else {
            if (! self::isEventId($options['eventId'])) {
                return NativeCalendarErrorCode::INVALID_EVENT_ID;
            }

            if (
                $hasStart !== $hasEnd ||
                ($hasStart && ! self::isTimeRange(
                    $options['startTimeMs'],
                    $options['endTimeMs'],
                ))
            ) {
                return NativeCalendarErrorCode::INVALID_TIME_RANGE;
            }
        }

        return self::sizeError($options);
    }

    public static function isRequestId(mixed $value): bool
    {
        return is_string($value) &&
            strlen($value) === 36 &&
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value,
            ) === 1;
    }

    public static function isEpochMilliseconds(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_EPOCH_MS;
    }

    public static function isEventId(mixed $value): bool
    {
        return is_int($value) && $value > 0 && $value <= self::MAX_EVENT_ID;
    }

    public static function isTimeRange(mixed $start, mixed $end): bool
    {
        return self::isEpochMilliseconds($start) &&
            self::isEpochMilliseconds($end) &&
            $end > $start;
    }

    public static function isTimeZone(mixed $value): bool
    {
        if (
            ! is_string($value) ||
            strlen($value) > self::MAX_TIME_ZONE_BYTES ||
            preg_match('/\A[A-Za-z0-9_+\/-]+\z/', $value) !== 1
        ) {
            return false;
        }

        self::$timeZones ??= array_fill_keys(
            DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
            true,
        );

        return $value === 'UTC' || isset(self::$timeZones[$value]);
    }

    public static function isRecurrence(mixed $value): bool
    {
        if (
            ! is_string($value) ||
            strlen($value) > self::MAX_RECURRENCE_BYTES ||
            preg_match(
                '/\AFREQ=(DAILY|WEEKLY|MONTHLY|YEARLY)(?:;INTERVAL=([1-9][0-9]{0,2}))?(?:;COUNT=([1-9][0-9]{0,3}))?\z/',
                $value,
                $matches,
            ) !== 1
        ) {
            return false;
        }

        $interval = isset($matches[2]) && $matches[2] !== ''
            ? (int) $matches[2]
            : 1;
        $count = isset($matches[3]) && $matches[3] !== ''
            ? (int) $matches[3]
            : null;

        return $interval <= 365 && ($count === null || $count <= 1000);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $allowed
     */
    private static function hasOnlyKeys(array $options, array $allowed): bool
    {
        foreach (array_keys($options) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isText(
        mixed $value,
        int $maxCodePoints,
        bool $allowEmpty,
        bool $multiline,
    ): bool {
        if (
            ! is_string($value) ||
            strlen($value) > $maxCodePoints * 4 ||
            preg_match('//u', $value) !== 1
        ) {
            return false;
        }

        if (! $allowEmpty && preg_match('/[^\p{Z}\s]/u', $value) !== 1) {
            return false;
        }

        $controls = $multiline
            ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x{007F}-\x{009F}]/u'
            : '/[\x00-\x1F\x{007F}-\x{009F}]/u';

        if (preg_match($controls, $value) !== 0) {
            return false;
        }

        $length = preg_match_all('/./us', $value);

        return $length !== false && $length <= $maxCodePoints;
    }

    /** @param array<string, mixed> $options */
    private static function sizeError(array $options): ?string
    {
        try {
            $json = json_encode(
                $options,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                16,
            );

            return strlen($json) > self::MAX_REQUEST_BYTES
                ? NativeCalendarErrorCode::REQUEST_TOO_LARGE
                : null;
        } catch (Throwable) {
            return NativeCalendarErrorCode::INVALID_OPTIONS;
        }
    }
}
