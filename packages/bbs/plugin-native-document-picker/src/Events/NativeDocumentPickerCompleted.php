<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker\Events;

use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerErrorCode;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NativeDocumentPickerCompleted
{
    use Dispatchable;
    use SerializesModels;

    public string $id;

    public bool $success;

    public bool $cancelled;

    public ?string $path;

    public ?string $originalName;

    public ?string $mimeType;

    public ?int $size;

    public ?string $errorCode;

    public ?string $errorMessage;

    public function __construct(
        string $id,
        bool $success,
        bool $cancelled,
        ?string $path = null,
        ?string $originalName = null,
        ?string $mimeType = null,
        ?int $size = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ) {
        $this->id = $id;
        $this->success = false;
        $this->cancelled = false;
        $this->path = null;
        $this->originalName = null;
        $this->mimeType = null;
        $this->size = null;

        if (
            $success &&
            ! $cancelled &&
            is_string($path) &&
            $path !== '' &&
            is_string($originalName) &&
            $originalName !== '' &&
            is_string($mimeType) &&
            $mimeType !== '' &&
            is_int($size) &&
            $size >= 0
        ) {
            $this->success = true;
            $this->path = $path;
            $this->originalName = $originalName;
            $this->mimeType = $mimeType;
            $this->size = $size;
            $this->errorCode = null;
            $this->errorMessage = null;

            return;
        }

        if ($cancelled && ! $success) {
            $this->cancelled = true;
            $this->errorCode = null;
            $this->errorMessage = null;

            return;
        }

        $resolvedErrorCode = is_string($errorCode) &&
            NativeDocumentPickerErrorCode::isKnown($errorCode)
                ? $errorCode
                : NativeDocumentPickerErrorCode::UNKNOWN_ERROR;

        $this->errorCode = $resolvedErrorCode;
        $this->errorMessage = NativeDocumentPickerErrorCode::message(
            $resolvedErrorCode,
        );
    }

    public function result(): NativeDocumentPickerResult
    {
        $status = $this->success
            ? 'succeeded'
            : ($this->cancelled ? 'cancelled' : 'failed');

        return new NativeDocumentPickerResult(
            id: $this->id,
            status: $status,
            success: $this->success,
            cancelled: $this->cancelled,
            path: $this->path,
            originalName: $this->originalName,
            mimeType: $this->mimeType,
            size: $this->size,
            errorCode: $this->errorCode,
            errorMessage: $this->errorMessage,
        );
    }
}
