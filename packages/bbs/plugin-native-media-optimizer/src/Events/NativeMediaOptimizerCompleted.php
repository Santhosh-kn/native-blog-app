<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Events;

use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerRequestValidator as Validator;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class NativeMediaOptimizerCompleted implements JsonSerializable
{
    use Dispatchable;

    public ?string $errorMessage;

    public function __construct(
        public string $id,
        public string $operation,
        public string $status,
        public ?string $errorCode = null,
        ?string $errorMessage = null,
    ) {
        if (! Validator::isRequestId($id) || ! in_array($operation, ['InspectMedia', 'OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'], true) || ! in_array($status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true) || (in_array($status, ['failed', 'interrupted'], true) && ! ErrorCode::isKnown($errorCode)) || (in_array($status, ['succeeded', 'cancelled'], true) && $errorCode !== null) || ($status === 'interrupted' && $errorCode !== ErrorCode::PROCESS_INTERRUPTED)) {
            throw new InvalidArgumentException('The native media completion metadata is invalid.');
        }
        $this->errorMessage = $errorCode === null ? null : ErrorCode::message($errorCode);
    }

    public function toMetadata(): array
    {
        return ['id' => $this->id, 'operation' => $this->operation, 'status' => $this->status, 'errorCode' => $this->errorCode, 'errorMessage' => $this->errorMessage];
    }

    public function jsonSerialize(): array
    {
        return $this->toMetadata();
    }
}
