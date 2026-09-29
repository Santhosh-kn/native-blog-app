<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Bbs\NativePasskeys\Facades\NativePasskeys;
use Bbs\NativePasskeys\Support\NativePasskeysErrorCode;
use Bbs\NativePasskeys\Support\NativePasskeysResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class NativePasskeysController extends Controller
{
    public function index(): View
    {
        return view('native-passkeys');
    }

    public function availability(): JsonResponse
    {
        try {
            $result = NativePasskeys::isAvailable();
        } catch (Throwable $exception) {
            report($exception);

            return $this->json([
                'available' => false,
                'platform' => 'android',
                'api_level' => null,
                'minimum_api_level' => 28,
                'error_code' =>
                    NativePasskeysErrorCode::
                        CREDENTIAL_MANAGER_UNAVAILABLE,
                'error_message' =>
                    'Android Credential Manager is unavailable.',
            ], 500);
        }

        return $this->json([
            'available' => ($result->available ?? false) === true,
            'platform' => $this->nullableString(
                $result->platform ?? null,
            ) ?? 'android',
            'api_level' => $this->nullableInteger(
                $result->apiLevel ?? null,
            ),
            'minimum_api_level' => $this->nullableInteger(
                $result->minimumApiLevel ?? null,
            ) ?? 28,
            'error_code' => $this->nullableString(
                $result->errorCode ?? null,
            ),
            'error_message' => $this->nullableString(
                $result->errorMessage ?? null,
            ),
        ]);
    }

    public function diagnostics(): JsonResponse
    {
        $requestId = (string) Str::uuid();

        try {
            $status = NativePasskeys::getStatus($requestId);
            $cancel = NativePasskeys::cancel($requestId);
            $consume = NativePasskeys::consumeResult(
                $requestId,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->json([
                'passed' => false,
                'request_id' => $requestId,
                'expected_error_code' =>
                    NativePasskeysErrorCode::RESULT_NOT_FOUND,
                'error_code' =>
                    NativePasskeysErrorCode::UNKNOWN_ERROR,
                'error_message' =>
                    'The safe Passkeys diagnostics could not run.',
                'operations' => [],
            ], 500);
        }

        $operations = [
            'get_status' => $this->diagnosticResult(
                'NativePasskeys.GetStatus',
                $requestId,
                $status,
            ),
            'cancel' => $this->diagnosticResult(
                'NativePasskeys.Cancel',
                $requestId,
                $cancel,
            ),
            'consume_result' => $this->diagnosticResult(
                'NativePasskeys.ConsumeResult',
                $requestId,
                $consume,
            ),
        ];

        $passed = array_all(
            $operations,
            fn (array $operation): bool =>
                $this->operationPassed($operation),
        );

        return $this->json([
            'passed' => $passed,
            'request_id' => $requestId,
            'expected_error_code' =>
                NativePasskeysErrorCode::RESULT_NOT_FOUND,
            'operations' => $operations,
        ]);
    }

    /**
     * @return array{
     *     method: string,
     *     id_matches: bool,
     *     operation: ?string,
     *     status: string,
     *     success: bool,
     *     cancelled: bool,
     *     consumed: bool,
     *     error_code: ?string,
     *     error_message: ?string
     * }
     */
    private function diagnosticResult(
        string $method,
        string $expectedId,
        NativePasskeysResult $result,
    ): array {
        return [
            'method' => $method,
            'id_matches' => hash_equals(
                $expectedId,
                $result->id,
            ),
            'operation' => $result->operation,
            'status' => $result->status,
            'success' => $result->success,
            'cancelled' => $result->cancelled,
            'consumed' => $result->consumed,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ];
    }

    /**
     * @param array{
     *     id_matches: bool,
     *     status: string,
     *     success: bool,
     *     cancelled: bool,
     *     consumed: bool,
     *     error_code: ?string
     * } $operation
     */
    private function operationPassed(array $operation): bool
    {
        return $operation['id_matches'] === true &&
            $operation['status'] === 'not_found' &&
            $operation['success'] === false &&
            $operation['cancelled'] === false &&
            $operation['consumed'] === false &&
            $operation['error_code'] ===
                NativePasskeysErrorCode::RESULT_NOT_FOUND;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(
        array $payload,
        int $status = 200,
    ): JsonResponse {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0
            ? $value
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : Str::limit($value, 500, '');
    }
}