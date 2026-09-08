<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker\Support;

use JsonSerializable;

final readonly class NativeDocumentPickerResult implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $status,
        public bool $success,
        public bool $cancelled,
        public ?string $path = null,
        public ?string $originalName = null,
        public ?string $mimeType = null,
        public ?int $size = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     success: bool,
     *     cancelled: bool,
     *     path: ?string,
     *     originalName: ?string,
     *     mimeType: ?string,
     *     size: ?int,
     *     errorCode: ?string,
     *     errorMessage: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'success' => $this->success,
            'cancelled' => $this->cancelled,
            'path' => $this->path,
            'originalName' => $this->originalName,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     success: bool,
     *     cancelled: bool,
     *     path: ?string,
     *     originalName: ?string,
     *     mimeType: ?string,
     *     size: ?int,
     *     errorCode: ?string,
     *     errorMessage: ?string
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
