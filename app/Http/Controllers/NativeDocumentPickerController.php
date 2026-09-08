<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\NativeDocumentPickerFileGuard;
use App\Support\NativeDocumentPickerState;
use Bbs\NativeDocumentPicker\Facades\NativeDocumentPicker;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

final class NativeDocumentPickerController extends Controller
{
    private const CACHE_PREFIX = 'native_document_picker_result:';

    public function __construct(
        private readonly NativeDocumentPickerState $state,
        private readonly NativeDocumentPickerFileGuard $fileGuard,
    ) {}

    public function index(Request $request): View
    {
        return view('native-document-picker', [
            'allowedMimeTypes' => NativeDocumentPickerState::ALLOWED_MIME_TYPES,
            'defaultMaxSize' => NativeDocumentPickerState::DEFAULT_MAX_SIZE,
            'maximumMaxSize' => NativeDocumentPickerState::MAX_DEMO_SIZE,
            'selection' => $this->state->selection(
                $request->session(),
            ),
        ]);
    }

    public function pick(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mime_types' => [
                'required',
                'array',
                'min:1',
                'max:'.count(
                    NativeDocumentPickerState::ALLOWED_MIME_TYPES,
                ),
            ],
            'mime_types.*' => [
                'required',
                'string',
                Rule::in(
                    NativeDocumentPickerState::ALLOWED_MIME_TYPES,
                ),
            ],
            'max_size' => [
                'required',
                'integer',
                'min:1',
                'max:'.NativeDocumentPickerState::MAX_DEMO_SIZE,
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        $mimeTypes = array_values(array_unique(
            $validated['mime_types'],
        ));
        $maxSize = (int) $validated['max_size'];
        $requestId = (string) Str::uuid();

        Cache::forget(self::CACHE_PREFIX.$requestId);

        try {
            $result = NativeDocumentPicker::pick([
                'id' => $requestId,
                'mime_types' => $mimeTypes,
                'max_size' => $maxSize,
            ]);
        } catch (Throwable $exception) {
            $this->logNativeFailure(
                'Native document picker could not be started',
                $requestId,
                $exception,
            );

            return response()->json([
                'accepted' => false,
                'request_id' => $requestId,
                'status' => 'failed',
                'error_code' => 'ACTIVITY_UNAVAILABLE',
                'error_message' => 'The native document picker could not be started.',
            ], 500);
        }

        $accepted = ($result->accepted ?? false) === true;

        $payload = [
            'accepted' => $accepted,
            'request_id' => $requestId,
            'status' => is_string($result->status ?? null)
                ? $result->status
                : ($accepted ? 'pending' : 'failed'),
            'error_code' => $this->nullableString(
                $result->errorCode ?? null,
            ),
            'error_message' => $this->nullableString(
                $result->errorMessage ?? null,
            ),
        ];

        if (! $accepted) {
            return response()->json($payload, 422);
        }

        $this->state->forgetSelection($request->session());

        $this->state->rememberRequest(
            $request->session(),
            $requestId,
            $mimeTypes,
            $maxSize,
        );

        return response()->json($payload, 202);
    }

    public function status(
        Request $request,
        string $requestId,
    ): JsonResponse {
        $session = $request->session();

        $requestOptions = $this->state->ownedRequest(
            $session,
            $requestId,
        );

        if ($requestOptions === null) {
            return response()->json([
                'status' => 'forbidden',
                'terminal' => true,
                'message' => 'This native document-picker request is not valid.',
            ], 403);
        }

        $completion = Cache::pull(
            self::CACHE_PREFIX.$requestId,
        );

        if (
            $completion !== null &&
            ! $this->isValidCompletion($completion, $requestId)
        ) {
            $this->state->forgetRequest($session, $requestId);

            return $this->invalidResultResponse();
        }

        if (is_array($completion) && in_array($completion['status'], ['cancelled', 'failed'], true)) {
            $this->state->forgetSelection($session);
            $this->state->forgetRequest($session, $requestId);

            return response()->json([
                'request_id' => $requestId,
                'status' => $completion['status'],
                'terminal' => true,
                'success' => false,
                'cancelled' => $completion['cancelled'],
                'error_code' => $this->nullableString(
                    $completion['error_code'] ?? null,
                ),
                'error_message' => $this->nullableString(
                    $completion['error_message'] ?? null,
                ),
            ]);
        }

        try {
            $result = NativeDocumentPicker::getStatus($requestId);
        } catch (Throwable $exception) {
            $this->logNativeFailure(
                'Native document-picker status could not be read',
                $requestId,
                $exception,
            );

            return response()->json([
                'request_id' => $requestId,
                'status' => 'pending',
                'terminal' => false,
                'message' => 'The native result is temporarily unavailable.',
            ], 503);
        }

        if (
            ! $result instanceof NativeDocumentPickerResult ||
            ! hash_equals($requestId, $result->id)
        ) {
            $this->state->forgetRequest($session, $requestId);
            Log::info('Native document-picker status observed', [
                'request_id' => $requestId,
                'completion_present' => $completion !== null,
                'completion_status' => is_array($completion)
                    ? ($completion['status'] ?? null)
                    : null,
                'status' => $result->status,
                'success' => $result->success,
                'cancelled' => $result->cancelled,
                'has_path' => is_string($result->path),
                'has_original_name' => is_string($result->originalName),
                'mime_type' => $result->mimeType,
                'size' => $result->size,
                'has_error_code' => $result->errorCode !== null,
                'has_error_message' => $result->errorMessage !== null,
            ]);

            return $this->invalidResultResponse();
        }

        /*
         * Android can dispatch the completion event just before the
         * persisted native status becomes visible. Therefore a pending
         * native result must remain retryable.
         */
        if ($result->status === 'pending') {
            return response()->json([
                'request_id' => $requestId,
                'status' => 'pending',
                'terminal' => false,
            ]);
        }

        if ($result->status === 'succeeded') {
            if (
                is_array($completion) &&
                $completion['status'] !== 'succeeded'
            ) {
                $this->state->forgetRequest($session, $requestId);

                return $this->invalidResultResponse();
            }

            $selection = $this->fileGuard->verifiedSelection(
                $result,
                $requestId,
                $requestOptions,
            );

            if ($selection === null) {
                Log::warning('Native document-picker selection rejected', [
                    'request_id' => $requestId,
                    'status' => $result->status,
                    'success' => $result->success,
                    'cancelled' => $result->cancelled,
                    'has_path' => is_string($result->path),
                    'has_original_name' => is_string($result->originalName),
                    'mime_type' => $result->mimeType,
                    'size' => $result->size,
                    'has_error_code' => $result->errorCode !== null,
                    'has_error_message' => $result->errorMessage !== null,
                ]);

                $this->state->forgetRequest($session, $requestId);

                return $this->invalidResultResponse();
            }

            $this->state->rememberSelection(
                $session,
                $selection,
            );

            $this->state->forgetRequest(
                $session,
                $requestId,
            );

            return response()->json([
                'request_id' => $requestId,
                'status' => 'succeeded',
                'terminal' => true,
                'success' => true,
                'cancelled' => false,
                'document' => $selection,
                'error_code' => null,
                'error_message' => null,
            ]);
        }

        if ($completion !== null) {
            $this->state->forgetRequest($session, $requestId);

            return $this->invalidResultResponse();
        }

        if ($result->status === 'cancelled') {
            if ($result->success || ! $result->cancelled) {
                $this->state->forgetRequest($session, $requestId);

                return $this->invalidResultResponse();
            }

            $this->state->forgetSelection($session);
            $this->state->forgetRequest($session, $requestId);

            return response()->json([
                'request_id' => $requestId,
                'status' => 'cancelled',
                'request_id' => $requestId,
                'status' => 'cancelled',
                'terminal' => true,
                'success' => false,
                'cancelled' => true,
                'error_code' => null,
                'error_message' => null,
            ]);
        }

        if (in_array($result->status, ['failed', 'not_found'], true)) {
            if ($result->success || $result->cancelled) {
                $this->state->forgetRequest($session, $requestId);

                return $this->invalidResultResponse();
            }

            $this->state->forgetSelection($session);
            $this->state->forgetRequest($session, $requestId);

            return response()->json([
                'request_id' => $requestId,
                'status' => $result->status,
                'terminal' => true,
                'success' => false,
                'cancelled' => false,
                'error_code' => $this->nullableString(
                    $result->errorCode,
                ),
                'error_message' => $this->nullableString(
                    $result->errorMessage,
                ),
            ]);
        }

        $this->state->forgetRequest($session, $requestId);

        return $this->invalidResultResponse();
    }

    private function isValidCompletion(
        mixed $completion,
        string $requestId,
    ): bool {
        if (! is_array($completion)) {
            return false;
        }

        $id = $completion['id'] ?? null;
        $status = $completion['status'] ?? null;
        $success = $completion['success'] ?? null;
        $cancelled = $completion['cancelled'] ?? null;

        if (
            ! is_string($id) ||
            ! hash_equals($requestId, $id) ||
            ! is_string($status) ||
            ! is_bool($success) ||
            ! is_bool($cancelled)
        ) {
            return false;
        }

        return match ($status) {
            'succeeded' => $success && ! $cancelled,
            'cancelled' => ! $success && $cancelled,
            'failed' => ! $success && ! $cancelled,
            default => false,
        };
    }

    private function invalidResultResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'terminal' => true,
            'success' => false,
            'cancelled' => false,
            'error_code' => 'INVALID_NATIVE_RESULT',
            'error_message' => 'An invalid native document-picker result was received.',
        ], 500);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return Str::limit($value, 500, '');
    }

    private function logNativeFailure(
        string $message,
        string $requestId,
        Throwable $exception,
    ): void {
        Log::warning($message, [
            'request_id' => $requestId,
            'exception_type' => $exception::class,
        ]);
    }
}
