<?php

declare(strict_types=1);

namespace Bbs\NativeBackgroundTransfer\Support;

use JsonSerializable;

final readonly class NativeBackgroundTransferResult implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public int $transferredBytes = 0,
        public ?int $totalBytes = null,
        public ?int $progress = null,
        public ?string $fileId = null,
        public ?string $displayName = null,
        public ?string $mimeType = null,
        public ?int $size = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $consumed = false,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     status: string,
     *     transferred_bytes: int,
     *     total_bytes: ?int,
     *     progress: ?int,
     *     file_id: ?string,
     *     display_name: ?string,
     *     mime_type: ?string,
     *     size: ?int,
     *     error_code: ?string,
     *     error_message: ?string,
     *     consumed: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'transferred_bytes' => $this->transferredBytes,
            'total_bytes' => $this->totalBytes,
            'progress' => $this->progress,
            'file_id' => $this->fileId,
            'display_name' => $this->displayName,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'consumed' => $this->consumed,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}