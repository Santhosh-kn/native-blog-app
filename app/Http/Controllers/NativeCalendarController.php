<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Bbs\NativeCalendar\NativeCalendar;
use Bbs\NativeCalendar\Support\NativeCalendarErrorCode as ErrorCode;
use Bbs\NativeCalendar\Support\NativeCalendarRequestValidator as Validator;
use Bbs\NativeCalendar\Support\NativeCalendarResult;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

final class NativeCalendarController extends Controller
{
    private const SESSION_KEY = 'native_calendar_diagnostics_request_ids';

    private const MAX_SESSION_REQUESTS = 32;

    public function __construct(
        private readonly NativeCalendar $calendar,
    ) {}

    public function index(Request $request): Response
    {
        return response()
            ->view('native-calendar', [
                'requestIds' => array_reverse($this->ownedIds($request)),
            ])
            ->header('Cache-Control', 'no-store');
    }

    public function availability(): JsonResponse
    {
        try {
            return $this->json((array) $this->calendar->isAvailable());
        } catch (Throwable) {
            return $this->json([
                'available' => false,
                'platform' => 'android',
                'apiLevel' => null,
                'minimumApiLevel' => 33,
                'capabilities' => (object) array_fill_keys([
                    'createEvent', 'openDate', 'openEvent',
                ], false),
                'errorCode' => ErrorCode::UNKNOWN_ERROR,
                'errorMessage' => ErrorCode::message(ErrorCode::UNKNOWN_ERROR),
            ], 500);
        }
    }

    public function createEvent(Request $request): JsonResponse
    {
        $preset = $request->input('preset');

        if (! in_array($preset, ['timed', 'all-day', 'recurring'], true)) {
            return $this->invalid(ErrorCode::INVALID_OPTIONS, 'create_event', 'editor');
        }

        $allDay = $preset === 'all-day';

        $start = new DateTimeImmutable(
            $allDay ? 'tomorrow 00:00:00' : 'tomorrow 10:00:00',
            new DateTimeZone($allDay ? 'UTC' : 'Asia/Kolkata'),
        );

        $startMs = $start->getTimestamp() * 1000;

        // Demo inputs are fixed. Request body fields cannot replace event details.
        $options = [
            'title' => 'Native Calendar Demo',
            'description' => 'This is a sample event. Review it before saving.',
            'location' => 'Demo location',
            'startTimeMs' => $startMs,
            'endTimeMs' => $startMs + ($allDay ? 86_400_000 : 3_600_000),
            'allDay' => $allDay,
            'timeZone' => $allDay ? 'UTC' : 'Asia/Kolkata',
        ];

        if ($preset === 'recurring') {
            $options['recurrence'] = 'FREQ=WEEKLY;COUNT=3';
        }

        return $this->start($request, 'create_event', 'editor', $options);
    }

    public function openDate(Request $request): JsonResponse
    {
        $tomorrow = new DateTimeImmutable(
            'tomorrow 00:00:00',
            new DateTimeZone('UTC'),
        );

        return $this->start($request, 'open', 'date', [
            'dateMs' => $tomorrow->getTimestamp() * 1000,
        ]);
    }

    public function openEvent(Request $request): JsonResponse
    {
        $input = $request->input('eventId');

        // Accept the browser's decimal string without float conversion.
        if (
            ! is_string($input) ||
            preg_match('/\A[1-9][0-9]{0,15}\z/D', $input) !== 1
        ) {
            return $this->invalid(ErrorCode::INVALID_EVENT_ID, 'open', 'event');
        }

        $eventId = (int) $input;

        if (! Validator::isEventId($eventId)) {
            return $this->invalid(ErrorCode::INVALID_EVENT_ID, 'open', 'event');
        }

        // Use the ID for this launch only. Never put it in session or responses.
        return $this->start($request, 'open', 'event', [
            'eventId' => $eventId,
        ]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        abort_unless(in_array($id, $this->ownedIds($request), true), 404);

        try {
            return $this->json([
                'result' => $this->calendar->getStatus($id)->toMetadata(),
            ]);
        } catch (Throwable) {
            return $this->json([
                'result' => NativeCalendarResult::failure(
                    $id,
                    ErrorCode::UNKNOWN_ERROR,
                )->toMetadata(),
            ], 500);
        }
    }

    public function diagnostics(Request $request): JsonResponse
    {
        $id = (string) Str::uuid();
        $this->rememberId($request, $id);

        try {
            $result = $this->calendar->getStatus($id);

            $passed = hash_equals($id, $result->id) &&
                $result->operation === null &&
                $result->target === null &&
                $result->status === 'not_found' &&
                ! $result->accepted &&
                ! $result->success &&
                $result->errorCode === ErrorCode::RESULT_NOT_FOUND &&
                $result->createdAtMs === null &&
                $result->completedAtMs === null;

            return $this->json([
                'passed' => $passed,
                'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                'result' => $result->toMetadata(),
            ]);
        } catch (Throwable) {
            return $this->json([
                'passed' => false,
                'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                'result' => NativeCalendarResult::failure(
                    $id,
                    ErrorCode::UNKNOWN_ERROR,
                )->toMetadata(),
            ], 500);
        }
    }

    /** @param array<string, mixed> $options */
    private function start(
        Request $request,
        string $operation,
        string $target,
        array $options,
    ): JsonResponse {
        $id = (string) Str::uuid();
        $this->rememberId($request, $id);
        $options['id'] = $id;

        try {
            $result = $operation === 'create_event'
                ? $this->calendar->createEvent($options)
                : $this->calendar->open($options);

            return $this->json(['result' => $result->toMetadata()]);
        } catch (Throwable) {
            return $this->json([
                'result' => NativeCalendarResult::failure(
                    $id,
                    ErrorCode::UNKNOWN_ERROR,
                    $operation,
                    $target,
                )->toMetadata(),
            ], 500);
        }
    }

    private function invalid(
        string $code,
        string $operation,
        string $target,
    ): JsonResponse {
        return $this->json([
            'result' => NativeCalendarResult::failure(
                (string) Str::uuid(),
                $code,
                $operation,
                $target,
            )->toMetadata(),
        ], 422);
    }

    /** @return list<string> */
    private function ownedIds(Request $request): array
    {
        $ids = $request->session()->get(self::SESSION_KEY, []);

        if (! is_array($ids)) {
            return [];
        }

        $ids = array_filter(
            $ids,
            static fn (mixed $id): bool => Validator::isRequestId($id),
        );

        return array_slice(
            array_values(array_unique($ids)),
            -self::MAX_SESSION_REQUESTS,
        );
    }

    private function rememberId(Request $request, string $id): void
    {
        $ids = $this->ownedIds($request);
        $ids[] = $id;

        $request->session()->put(
            self::SESSION_KEY,
            array_slice(array_values(array_unique($ids)), -self::MAX_SESSION_REQUESTS),
        );
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }
}
