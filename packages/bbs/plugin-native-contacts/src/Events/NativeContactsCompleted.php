<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Events;

use Bbs\NativeContacts\Support\NativeContactsResult;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class NativeContactsCompleted implements JsonSerializable
{
    use Dispatchable;

    public ?string $errorMessage;

    public function __construct(
        public string $id,
        public string $operation,
        public string $status,
        public bool $success,
        public bool $cancelled,
        public bool $consumed = false,
        public ?string $mode = null,
        public ?string $errorCode = null,
        ?string $errorMessage = null,
        public ?int $createdAtMs = null,
        public ?int $completedAtMs = null,
    ) {
        if (
            ! in_array($status, [
                'selected', 'launched', 'cancelled', 'failed', 'unknown',
            ], true) ||
            $createdAtMs === null ||
            $completedAtMs === null ||
            $createdAtMs <= 0 ||
            $completedAtMs < $createdAtMs
        ) {
            throw new InvalidArgumentException(
                'Invalid contacts completion metadata.',
            );
        }

        // The incoming error message is never retained.
        $result = NativeContactsResult::fromNative(
            response: (object) get_object_vars($this),
            expectedId: $id,
            purpose: 'status',
            expectedOperation: $operation,
            expectedMode: $mode,
        );

        // Invalid parser results have no timestamps.
        if (
            $result->createdAtMs !== $createdAtMs ||
            $result->completedAtMs !== $completedAtMs
        ) {
            throw new InvalidArgumentException(
                'Invalid contacts completion metadata.',
            );
        }

        $this->errorMessage = $result->errorMessage;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toMetadata(): array
    {
        return get_object_vars($this);
    }

    public function jsonSerialize(): array
    {
        return $this->toMetadata();
    }
}