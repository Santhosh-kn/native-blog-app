<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Support;

use JsonSerializable;
use Throwable;

final readonly class NativeCalendarResult implements JsonSerializable
{
    private const MAX_RESPONSE_BYTES = 16384;

    private const KEYS = [
        'id', 'operation', 'target', 'status', 'accepted', 'success',
        'errorCode', 'errorMessage', 'createdAtMs', 'completedAtMs',
    ];

    public ?string $errorMessage;

    private function __construct(
        public string $id,
        public ?string $operation,
        public ?string $target,
        public string $status,
        public bool $accepted,
        public bool $success,
        public ?string $errorCode,
        public ?int $createdAtMs,
        public ?int $completedAtMs,
    ) {
        $this->errorMessage = $errorCode === null
            ? null
            : NativeCalendarErrorCode::message($errorCode);
    }

    public static function failure(
        mixed $id,
        mixed $errorCode,
        ?string $operation = null,
        ?string $target = null,
    ): self {
        $safeId = NativeCalendarRequestValidator::isRequestId($id)
            ? $id
            : '00000000-0000-4000-8000-000000000000';

        $code = NativeCalendarErrorCode::canonical($errorCode);

        if ($code === NativeCalendarErrorCode::RESULT_NOT_FOUND) {
            return new self(
                $safeId, null, null, 'not_found', false, false,
                $code, null, null,
            );
        }

        $safeOperation = in_array($operation, ['create_event', 'open'], true)
            ? $operation
            : null;

        $safeTarget = match ($safeOperation) {
            'create_event' => 'editor',
            'open' => in_array($target, ['date', 'event'], true) ? $target : null,
            default => null,
        };

        return new self(
            $safeId, $safeOperation, $safeTarget, 'failed', false, false,
            $code, null, null,
        );
    }

    public static function fromNative(
        ?object $native,
        string $expectedId,
        ?string $expectedOperation = null,
        ?string $expectedTarget = null,
    ): self {
        if ($native === null) {
            return self::failure(
                $expectedId,
                NativeCalendarErrorCode::BRIDGE_UNAVAILABLE,
                $expectedOperation,
                $expectedTarget,
            );
        }

        $invalid = static fn (): self => self::failure(
            $expectedId,
            NativeCalendarErrorCode::INVALID_NATIVE_RESPONSE,
            $expectedOperation,
            $expectedTarget,
        );

        try {
            $encoded = json_encode(
                $native,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                16,
            );

            if (strlen($encoded) > self::MAX_RESPONSE_BYTES) {
                return $invalid();
            }

            $data = get_object_vars($native);

            if (
                count($data) !== count(self::KEYS) ||
                array_diff(array_keys($data), self::KEYS) !== []
            ) {
                return $invalid();
            }

            if (
                ! NativeCalendarRequestValidator::isRequestId($data['id']) ||
                ! hash_equals($expectedId, $data['id']) ||
                ! is_string($data['status']) ||
                ! is_bool($data['accepted']) ||
                ! is_bool($data['success']) ||
                (
                    $data['errorCode'] !== null &&
                    ! NativeCalendarErrorCode::isKnown($data['errorCode'])
                )
            ) {
                return $invalid();
            }

            $operation = $data['operation'];
            $target = $data['target'];
            $status = $data['status'];
            $accepted = $data['accepted'];
            $success = $data['success'];
            $code = $data['errorCode'];
            $created = $data['createdAtMs'];
            $completed = $data['completedAtMs'];
            $lookup = $expectedOperation === null;

            if (
                ($expectedOperation !== null && $operation !== $expectedOperation) ||
                ($expectedTarget !== null && $target !== $expectedTarget)
            ) {
                return $invalid();
            }

            $validBinding =
                ($operation === 'create_event' && $target === 'editor') ||
                ($operation === 'open' && in_array($target, ['date', 'event'], true)) ||
                (
                    $lookup &&
                    $operation === null &&
                    $target === null &&
                    in_array($status, ['not_found', 'failed'], true) &&
                    ! $accepted
                );

            if (! $validBinding) {
                return $invalid();
            }

            $hasCreated = NativeCalendarRequestValidator::isEpochMilliseconds($created);
            $hasCompleted = NativeCalendarRequestValidator::isEpochMilliseconds($completed);
            $timedTerminal = $hasCreated && $hasCompleted && $completed >= $created;
            $untimed = $created === null && $completed === null;

            $validState = match ($status) {
                'pending' => $accepted && ! $success && $code === null &&
                    $hasCreated && $completed === null,
                'launched' => $accepted && $success && $code === null &&
                    $timedTerminal,
                'unknown' => $accepted && ! $success &&
                    $code === NativeCalendarErrorCode::INTERRUPTED &&
                    $timedTerminal,
                'failed' => ! $success && $code !== null &&
                    $code !== NativeCalendarErrorCode::RESULT_NOT_FOUND &&
                    ($accepted ? $timedTerminal : $untimed),
                'not_found' => $lookup && ! $accepted && ! $success &&
                    $operation === null && $target === null &&
                    $code === NativeCalendarErrorCode::RESULT_NOT_FOUND &&
                    $untimed,
                default => false,
            };

            if (! $validState) {
                return $invalid();
            }

            return new self(
                $data['id'],
                $operation,
                $target,
                $status,
                $accepted,
                $success,
                $code,
                $created,
                $completed,
            );
        } catch (Throwable) {
            return $invalid();
        }
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'target' => $this->target,
            'status' => $this->status,
            'accepted' => $this->accepted,
            'success' => $this->success,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
            'createdAtMs' => $this->createdAtMs,
            'completedAtMs' => $this->completedAtMs,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toMetadata();
    }
}
