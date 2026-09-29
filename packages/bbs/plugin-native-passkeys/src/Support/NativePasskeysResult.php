<?php

declare(strict_types=1);

namespace Bbs\NativePasskeys\Support;

use JsonSerializable;

final readonly class NativePasskeysResult implements JsonSerializable
{
    public function __construct(
        public string $id,
        public ?string $operation,
        public string $status,
        public bool $success,
        public bool $cancelled,
        public bool $consumed,
        public ?string $responseJson = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     operation: ?string,
     *     status: string,
     *     success: bool,
     *     cancelled: bool,
     *     consumed: bool,
     *     responseJson: ?string,
     *     errorCode: ?string,
     *     errorMessage: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'status' => $this->status,
            'success' => $this->success,
            'cancelled' => $this->cancelled,
            'consumed' => $this->consumed,
            'responseJson' => $this->responseJson,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     operation: ?string,
     *     status: string,
     *     success: bool,
     *     cancelled: bool,
     *     consumed: bool,
     *     responseJson: ?string,
     *     errorCode: ?string,
     *     errorMessage: ?string
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
