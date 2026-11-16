<?php
declare(strict_types=1);

namespace Bbs\NativeNfc;

use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Bbs\NativeNfc\Support\NativeNfcErrorCode;

final readonly class NativeNfcResult
{
    public function __construct(
        public string $id,
        public string $operation,
        public string $status,
        public bool $consumed = false,
        public ?int $createdAtMs = null,
        public ?int $completedAtMs = null,
        public ?string $errorCode = null,
    ) {}

    public static function fromNative(object $data): self
    {
        $id = $data->id ?? null;
        $operation = $data->operation ?? null;
        $status = $data->status ?? null;
        $consumed = $data->consumed ?? false;
        $created = $data->created_at_ms ?? null;
        $completed = $data->completed_at_ms ?? null;
        $error = $data->error_code ?? null;

        $valid = is_string($id)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) === 1
            && is_string($operation)
            && is_string($status)
            && in_array($status, ['pending', 'read', 'written', 'cancelled', 'failed', 'unknown', 'not_found'], true)
            && (
                ($status === 'not_found' && $operation === '')
                || ($status !== 'not_found' && in_array($operation, ['start_scan', 'start_write'], true))
            )
            && is_bool($consumed)
            && ($created === null || is_int($created))
            && ($completed === null || is_int($completed))
            && ($error === null || (is_string($error) && NativeNfcErrorCode::isKnown($error)));

        if (!$valid) {
            throw new NativeNfcException(
                NativeNfcErrorCode::INVALID_NATIVE_RESPONSE,
                NativeNfcErrorCode::message(NativeNfcErrorCode::INVALID_NATIVE_RESPONSE)
            );
        }

        return new self($id, $operation, $status, $consumed, $created, $completed, $error);
    }
}
