<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Support;

use JsonSerializable;

final readonly class NativeContactsResult implements JsonSerializable
{
    public ?string $errorMessage;

    /**
     * @param array<string, string|null>|null $selection
     */
    private function __construct(
        public string $id,
        public ?string $operation,
        public ?string $mode,
        public string $status,
        public bool $accepted,
        public bool $success,
        public bool $cancelled,
        public bool $consumed,
        public ?string $errorCode,
        public ?int $createdAtMs,
        public ?int $completedAtMs,
        private ?array $selection = null,
    ) {
        $this->errorMessage = $errorCode === null
            ? null
            : NativeContactsErrorCode::message($errorCode);
    }

    public static function failure(
        string $id,
        string $errorCode,
        ?string $operation = null,
        ?string $mode = null,
    ): self {
        return new self(
            id: $id,
            operation: $operation,
            mode: $mode,
            status: 'failed',
            accepted: false,
            success: false,
            cancelled: false,
            consumed: false,
            errorCode: NativeContactsErrorCode::isKnown($errorCode)
                ? $errorCode
                : NativeContactsErrorCode::UNKNOWN_ERROR,
            createdAtMs: null,
            completedAtMs: null,
        );
    }

    public static function fromNative(
        object $response,
        string $expectedId,
        string $purpose,
        ?string $expectedOperation = null,
        ?string $expectedMode = null,
    ): self {
        $invalid = static fn (): self => self::failure(
            $expectedId,
            NativeContactsErrorCode::INVALID_NATIVE_RESPONSE,
            $expectedOperation,
            $expectedMode,
        );

        if (! in_array($purpose, ['start', 'status', 'consume'], true)) {
            return $invalid();
        }

        $data = get_object_vars($response);
        $allowed = [
            'id', 'operation', 'mode', 'status', 'accepted',
            'success', 'cancelled', 'consumed',
            'errorCode', 'errorMessage',
            'createdAtMs', 'completedAtMs', 'selection',
        ];

        if (
            array_diff(array_keys($data), $allowed) !== [] ||
            ! NativeContactsRequestValidator::isRequestId($data['id'] ?? null) ||
            ! hash_equals($expectedId, $data['id'])
        ) {
            return $invalid();
        }

        $status = $data['status'] ?? null;
        $operation = $data['operation'] ?? null;
        $mode = $data['mode'] ?? null;
        $errorCode = $data['errorCode'] ?? null;

        if (! in_array(
            $status,
            ['pending', 'selected', 'launched', 'cancelled', 'failed', 'unknown', 'not_found'],
            true,
        )) {
            return $invalid();
        }

        if ($status === 'not_found') {
            if (
                $operation !== null ||
                $mode !== null ||
                $errorCode !== NativeContactsErrorCode::RESULT_NOT_FOUND
            ) {
                return $invalid();
            }
        } elseif (
            $operation === null &&
            $status === 'failed' &&
            in_array($purpose, ['status', 'consume'], true) &&
            $errorCode === NativeContactsErrorCode::RESULT_PERSISTENCE_FAILED
        ) {
            // Storage failure can prevent us from learning the operation.
            if (
                ($data['accepted'] ?? false) !== false ||
                ($data['createdAtMs'] ?? null) !== null ||
                ($data['completedAtMs'] ?? null) !== null
            ) {
                return $invalid();
            }
        } elseif (! in_array($operation, ['pick', 'create', 'open'], true)) {
            return $invalid();
        }

        if (
            ($expectedOperation !== null && $operation !== $expectedOperation) ||
            ($expectedMode !== null && $mode !== $expectedMode) ||
            ($operation === 'pick' && ! in_array($mode, ['contact', 'phone', 'email'], true)) ||
            ($operation !== 'pick' && $mode !== null) ||
            (in_array($status, ['selected', 'cancelled'], true) && $operation !== 'pick') ||
            ($status === 'launched' && $operation === 'pick')
        ) {
            return $invalid();
        }

        foreach (['success', 'cancelled', 'consumed'] as $field) {
            if (! array_key_exists($field, $data) || ! is_bool($data[$field])) {
                return $invalid();
            }
        }

        $accepted = $data['accepted'] ?? false;

        if (
            ! is_bool($accepted) ||
            ($purpose === 'start' && ! array_key_exists('accepted', $data)) ||
            ($purpose === 'start' && $status === 'pending' && ! $accepted) ||
            $data['success'] !== in_array($status, ['selected', 'launched'], true) ||
            $data['cancelled'] !== ($status === 'cancelled') ||
            ($data['consumed'] && $status !== 'selected') ||
            ($errorCode !== null && ! NativeContactsErrorCode::isKnown($errorCode))
        ) {
            return $invalid();
        }

        $validError = match ($status) {
            'pending' => $errorCode === null ||
                ($purpose === 'consume' && $errorCode === NativeContactsErrorCode::RESULT_NOT_READY),
            'selected' => $errorCode === null ||
                ($purpose === 'consume' && $data['consumed'] &&
                    $errorCode === NativeContactsErrorCode::RESULT_ALREADY_CONSUMED),
            'launched', 'cancelled' => $errorCode === null,
            'unknown' => $errorCode === NativeContactsErrorCode::OPERATION_INTERRUPTED,
            'not_found' => $errorCode === NativeContactsErrorCode::RESULT_NOT_FOUND,
            'failed' => $errorCode !== null,
        };

        if (! $validError) {
            return $invalid();
        }

        foreach (['createdAtMs', 'completedAtMs'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && (! is_int($value) || $value <= 0)) {
                return $invalid();
            }
        }

        $selection = null;
        $rawSelection = $data['selection'] ?? null;
        $mayDeliver = $purpose === 'consume' &&
            $status === 'selected' &&
            $data['consumed'] &&
            $errorCode === null;

        if ($rawSelection !== null) {
            if (! $mayDeliver || ! is_object($rawSelection)) {
                return $invalid();
            }

            $selection = self::validateSelection($rawSelection, $mode);
            if ($selection === null) {
                return $invalid();
            }
        } elseif ($mayDeliver) {
            return $invalid();
        }

        return new self(
            id: $expectedId,
            operation: $operation,
            mode: $mode,
            status: $status,
            accepted: $accepted,
            success: $data['success'],
            cancelled: $data['cancelled'],
            consumed: $data['consumed'],
            errorCode: $errorCode,
            createdAtMs: $data['createdAtMs'] ?? null,
            completedAtMs: $data['completedAtMs'] ?? null,
            selection: $selection,
        );
    }

    /**
     * Available only on the first successful ConsumeResult response.
     *
     * @return array<string, string|null>|null
     */
    public function selectedData(): ?array
    {
        return $this->selection;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toMetadata(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'mode' => $this->mode,
            'status' => $this->status,
            'accepted' => $this->accepted,
            'success' => $this->success,
            'cancelled' => $this->cancelled,
            'consumed' => $this->consumed,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
            'createdAtMs' => $this->createdAtMs,
            'completedAtMs' => $this->completedAtMs,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toMetadata();
    }

    /**
     * @return array<string, string|null>|null
     */
    private static function validateSelection(object $selection, string $mode): ?array
    {
        $data = get_object_vars($selection);

        $allowed = match ($mode) {
            'contact' => ['displayName', 'contactUri'],
            'phone' => ['displayName', 'contactUri', 'phoneNumber'],
            'email' => ['displayName', 'contactUri', 'emailAddress'],
        };

        if (array_diff(array_keys($data), $allowed) !== []) {
            return null;
        }

        $name = $data['displayName'] ?? null;
        $uri = $data['contactUri'] ?? null;

        if (
            ($name !== null && ! NativeContactsRequestValidator::isName($name)) ||
            ($uri !== null && ! NativeContactsRequestValidator::isContactUri($uri)) ||
            ($mode === 'contact' && $uri === null)
        ) {
            return null;
        }

        if (
            ($mode === 'phone' && ! NativeContactsRequestValidator::isPhone($data['phoneNumber'] ?? null)) ||
            ($mode === 'email' && ! NativeContactsRequestValidator::isEmail($data['emailAddress'] ?? null))
        ) {
            return null;
        }

        $safe = ['displayName' => $name, 'contactUri' => $uri];

        if ($mode === 'phone') {
            $safe['phoneNumber'] = $data['phoneNumber'];
        } elseif ($mode === 'email') {
            $safe['emailAddress'] = $data['emailAddress'];
        }

        return $safe;
    }
}
