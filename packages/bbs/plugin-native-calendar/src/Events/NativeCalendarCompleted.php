<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Events;

use Bbs\NativeCalendar\Support\NativeCalendarResult;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class NativeCalendarCompleted implements JsonSerializable
{
    use Dispatchable;

    public ?string $errorMessage;

    public function __construct(
        public string $id,
        public ?string $operation,
        public ?string $target,
        public string $status,
        public bool $accepted,
        public bool $success,
        public ?string $errorCode = null,
        ?string $errorMessage = null,
        public ?int $createdAtMs = null,
        public ?int $completedAtMs = null,
    ) {
        if (
            ! $accepted ||
            ! in_array($status, ['launched', 'failed', 'unknown'], true) ||
            ! in_array($operation, ['create_event', 'open'], true)
        ) {
            throw new InvalidArgumentException(
                'The native Calendar completion metadata is invalid.',
            );
        }

        $checked = NativeCalendarResult::fromNative(
            (object) compact(
                'id', 'operation', 'target', 'status', 'accepted', 'success',
                'errorCode', 'errorMessage', 'createdAtMs', 'completedAtMs',
            ),
            $id,
            $operation,
            $target,
        );

        if (! $checked->accepted || $checked->status !== $status) {
            throw new InvalidArgumentException(
                'The native Calendar completion metadata is invalid.',
            );
        }

        // Use the controlled message; never retain incoming native error text.
        $this->errorMessage = $checked->errorMessage;
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
