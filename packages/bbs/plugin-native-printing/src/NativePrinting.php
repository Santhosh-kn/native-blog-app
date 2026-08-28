<?php

declare(strict_types=1);

namespace Bbs\NativePrinting;

use Bbs\NativePrinting\Contracts\NativeBridge;
use Bbs\NativePrinting\Support\LocalPdfValidator;
use Bbs\NativePrinting\Support\NativePrintingErrorCode;
use Illuminate\Support\Str;

final class NativePrinting
{
    public function __construct(private readonly LocalPdfValidator $validator, private readonly NativeBridge $bridge) {}

    public function isAvailable(): object
    {
        return $this->bridge->call('NativePrinting.IsAvailable') ?? (object) [
            'available' => false,
            'preview_supported' => false,
            'printing_supported' => false,
            'error_code' => NativePrintingErrorCode::PRINTING_UNAVAILABLE,
            'error_message' => 'Native PDF preview and printing are unavailable.',
        ];
    }

    public function preview(string $path, string $title = 'PDF Preview', ?string $requestId = null): object
    {
        return $this->startPdfAction(
            action: 'preview',
            bridgeMethod: 'NativePrinting.Preview',
            path: $path,
            labelKey: 'title',
            label: $this->normalizeLabel($title, 'PDF Preview'),
            requestId: $requestId,
        );
    }

    public function print(string $path, string $jobName = 'Document', ?string $requestId = null): object
    {
        return $this->startPdfAction(
            action: 'print',
            bridgeMethod: 'NativePrinting.Print',
            path: $path,
            labelKey: 'job_name',
            label: $this->normalizeLabel($jobName, 'Document'),
            requestId: $requestId
        );
    }

    private function startPdfAction(string $action, string $bridgeMethod, string $path, string $labelKey, string $label, ?string $requestId): object
    {
        $resolvedRequestId = $this->resolveRequestId($requestId);

        if ($resolvedRequestId === null) {
            return $this->rejected(
                requestId: $this->safeRejectedRequestId($requestId),
                action: $action,
                errorCode: NativePrintingErrorCode::INVALID_REQUEST_ID,
                errorMessage: 'The request ID must be a valid UUID.'
            );
        }

        $validation = $this->validator->validate($path);

        if (! $validation->valid) {
            return $this->rejected(
                requestId: $resolvedRequestId,
                action: $action,
                errorCode: $validation->errorCode ?? NativePrintingErrorCode::UNKNOWN_ERROR,
                errorMessage: $validation->errorMessage ?? 'The PDF could not be validated.'
            );
        }

        $response = $this->bridge->call($bridgeMethod, ['path' => $validation->canonicalPath, $labelKey => $label, 'request_id' => $resolvedRequestId]);

        if ($response === null) {
            return $this->rejected(
                requestId: $resolvedRequestId,
                action: $action,
                errorCode: NativePrintingErrorCode::ACTIVITY_UNAVAILABLE,
                errorMessage: 'The native PDF action could not be started.'
            );
        }

        return (object) array_merge(
            [
                'accepted' => true,
                'request_id' => $resolvedRequestId,
                'action' => $action,
                'status' => 'accepted',
            ],
            get_object_vars($response),
        );
    }

    private function resolveRequestId(?string $requestId): ?string
    {
        if ($requestId === null || trim($requestId) === '') {
            return (string) Str::uuid();
        }

        $requestId = trim($requestId);

        if (! Str::isUuid($requestId)) {
            return null;
        }

        return strtolower($requestId);
    }

    private function safeRejectedRequestId(?string $requestId): string
    {
        $requestId = trim((string) $requestId);

        if ($requestId === '') {
            return (string) Str::uuid();
        }

        return substr($requestId, 0, 128);
    }

    private function normalizeLabel(string $label, string $default): string
    {
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label);

        if (! is_string($normalized)) {
            return $default;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? '');

        if ($normalized === '') {
            return $default;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($normalized, 0, 120);
        }

        return substr($normalized, 0, 120);
    }

    private function rejected(string $requestId, string $action, string $errorCode, string $errorMessage): object
    {
        return (object) [
            'accepted' => false,
            'request_id' => $requestId,
            'action' => $action,
            'status' => 'failed',
            'job_id' => null,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }
}
