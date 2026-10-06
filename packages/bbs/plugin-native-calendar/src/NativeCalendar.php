<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar;

use Bbs\NativeCalendar\Contracts\NativeBridge;
use Bbs\NativeCalendar\Support\NativeCalendarErrorCode as ErrorCode;
use Bbs\NativeCalendar\Support\NativeCalendarRequestValidator as Validator;
use Bbs\NativeCalendar\Support\NativeCalendarResult;
use Illuminate\Support\Str;
use Throwable;

final class NativeCalendar
{
    private const CAPABILITIES = ['createEvent', 'openDate', 'openEvent'];

    private const AVAILABILITY_KEYS = [
        'available', 'platform', 'apiLevel', 'minimumApiLevel',
        'capabilities', 'errorCode', 'errorMessage',
    ];

    public function __construct(
        private readonly NativeBridge $bridge,
    ) {}

    public function isAvailable(): object
    {
        $native = $this->call('NativeCalendar.IsAvailable');

        if ($native === null) {
            return $this->unavailable(ErrorCode::BRIDGE_UNAVAILABLE);
        }

        try {
            $encoded = json_encode(
                $native,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                16,
            );

            if (strlen($encoded) > 16384) {
                return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
            }

            $data = get_object_vars($native);

            if (
                count($data) !== count(self::AVAILABILITY_KEYS) ||
                array_diff(array_keys($data), self::AVAILABILITY_KEYS) !== [] ||
                ! is_bool($data['available']) ||
                $data['platform'] !== 'android' ||
                $data['minimumApiLevel'] !== 33 ||
                ! is_object($data['capabilities']) ||
                (
                    $data['apiLevel'] !== null &&
                    (
                        ! is_int($data['apiLevel']) ||
                        $data['apiLevel'] < 1 ||
                        $data['apiLevel'] > 1000
                    )
                ) ||
                (
                    $data['errorCode'] !== null &&
                    ! ErrorCode::isKnown($data['errorCode'])
                )
            ) {
                return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
            }

            $capabilities = get_object_vars($data['capabilities']);

            if (
                count($capabilities) !== count(self::CAPABILITIES) ||
                array_diff(array_keys($capabilities), self::CAPABILITIES) !== []
            ) {
                return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
            }

            foreach ($capabilities as $value) {
                if (! is_bool($value)) {
                    return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
                }
            }

            $anyAvailable = in_array(true, $capabilities, true);

            if (
                $data['available'] !== $anyAvailable ||
                ($anyAvailable && $data['errorCode'] !== null) ||
                (! $anyAvailable && $data['errorCode'] === null) ||
                ($anyAvailable && ($data['apiLevel'] === null || $data['apiLevel'] < 33)) ||
                (
                    $data['apiLevel'] !== null &&
                    $data['apiLevel'] < 33 &&
                    $data['errorCode'] !== ErrorCode::UNSUPPORTED_ANDROID_VERSION
                )
            ) {
                return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
            }

            return (object) [
                'available' => $anyAvailable,
                'platform' => 'android',
                'apiLevel' => $data['apiLevel'],
                'minimumApiLevel' => 33,
                'capabilities' => (object) $capabilities,
                'errorCode' => $data['errorCode'],
                'errorMessage' => $data['errorCode'] === null
                    ? null
                    : ErrorCode::message($data['errorCode']),
            ];
        } catch (Throwable) {
            return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
        }
    }

    /** @param array<string, mixed> $options */
    public function createEvent(array $options = []): NativeCalendarResult
    {
        $id = array_key_exists('id', $options)
            ? $options['id']
            : (string) Str::uuid();

        $options['id'] = $id;

        if (! array_key_exists('allDay', $options)) {
            $options['allDay'] = false;
        }

        if ($options['allDay'] === true && ! array_key_exists('timeZone', $options)) {
            $options['timeZone'] = 'UTC';
        }

        $error = Validator::validateCreate($options);

        if ($error !== null) {
            return NativeCalendarResult::failure($id, $error, 'create_event', 'editor');
        }

        return NativeCalendarResult::fromNative(
            $this->call('NativeCalendar.CreateEvent', $options),
            $id,
            'create_event',
            'editor',
        );
    }

    /** @param array<string, mixed> $options */
    public function open(array $options = []): NativeCalendarResult
    {
        $id = array_key_exists('id', $options)
            ? $options['id']
            : (string) Str::uuid();

        $options['id'] = $id;

        $hasDate = array_key_exists('dateMs', $options);
        $hasEvent = array_key_exists('eventId', $options);
        $target = $hasDate !== $hasEvent
            ? ($hasDate ? 'date' : 'event')
            : null;

        $error = Validator::validateOpen($options);

        if ($error !== null) {
            return NativeCalendarResult::failure($id, $error, 'open', $target);
        }

        return NativeCalendarResult::fromNative(
            $this->call('NativeCalendar.Open', $options),
            $id,
            'open',
            $target,
        );
    }

    public function getStatus(mixed $id): NativeCalendarResult
    {
        if (! Validator::isRequestId($id)) {
            return NativeCalendarResult::failure($id, ErrorCode::INVALID_REQUEST_ID);
        }

        return NativeCalendarResult::fromNative(
            $this->call('NativeCalendar.GetStatus', ['id' => $id]),
            $id,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function call(string $method, array $parameters = []): ?object
    {
        try {
            return $this->bridge->call($method, $parameters);
        } catch (Throwable) {
            return null;
        }
    }

    private function unavailable(string $code): object
    {
        return (object) [
            'available' => false,
            'platform' => 'android',
            'apiLevel' => null,
            'minimumApiLevel' => 33,
            'capabilities' => (object) array_fill_keys(self::CAPABILITIES, false),
            'errorCode' => ErrorCode::canonical($code),
            'errorMessage' => ErrorCode::message($code),
        ];
    }
}
