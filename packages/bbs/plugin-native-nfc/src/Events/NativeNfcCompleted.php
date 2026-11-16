<?php

declare(strict_types=1);

namespace Bbs\NativeNfc\Events;

use Bbs\NativeNfc\Support\NativeNfcErrorCode as ErrorCode;
use InvalidArgumentException;
use JsonSerializable;

final readonly class NativeNfcCompleted implements JsonSerializable
{

    private const OPERATIONS = ['start_scan', 'start_write'];

    private const FAILURE_ERROR_CODES = [
        ErrorCode::NFC_UNAVAILABLE,
        ErrorCode::NFC_DISABLED,
        ErrorCode::ACTIVITY_UNAVAILABLE,
        ErrorCode::UNSUPPORTED_TAG,
        ErrorCode::TAG_NOT_NDEF,
        ErrorCode::TAG_READ_ONLY,
        ErrorCode::TAG_TOO_SMALL,
        ErrorCode::TEXT_TOO_LARGE,
        ErrorCode::TAG_LOST,
        ErrorCode::IO_ERROR,
        ErrorCode::UNKNOWN_ERROR,
    ];

    public function __construct(
        public string $id,
        public string $operation,
        public string $status,
        public bool $consumed,
        public int $createdAtMs,
        public int $completedAtMs,
        public ?string $errorCode = null,
    ) {
        $validId = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
            $id,
        ) === 1;

        $valid = $validId
            && in_array($operation, self::OPERATIONS, true)
            && $createdAtMs > 0
            && $completedAtMs >= $createdAtMs
            && (
                !$consumed || $status === 'read'
            );

        $valid = $valid && match ($status) {
            'read' => $operation === 'start_scan'
                && $errorCode === null,

            'written' => $operation === 'start_write'
                && $errorCode === null,

            'cancelled' => $errorCode === null
                && !$consumed,

            'failed' => in_array(
                $errorCode,
                self::FAILURE_ERROR_CODES,
                true,
            ) && !$consumed,

            'unknown' => in_array(
                $errorCode,
                [
                    ErrorCode::OPERATION_INTERRUPTED,
                    ErrorCode::UNKNOWN_ERROR,
                ],
                true,
            ) && !$consumed,

            default => false,
        };

        if (!$valid) {
            throw new InvalidArgumentException(
                'Invalid NFC completion metadata.',
            );
        }
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toMetadata(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'status' => $this->status,
            'consumed' => $this->consumed,
            'createdAtMs' => $this->createdAtMs,
            'completedAtMs' => $this->completedAtMs,
            'errorCode' => $this->errorCode,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toMetadata();
    }
}