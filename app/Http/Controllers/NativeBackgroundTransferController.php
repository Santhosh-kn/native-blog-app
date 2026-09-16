<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\NativeDocumentPickerState;
use Bbs\NativeBackgroundTransfer\Facades\NativeBackgroundTransfer;
use Bbs\NativeBackgroundTransfer\Support\NativeBackgroundTransferResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class NativeBackgroundTransferController extends Controller
{
    private const SESSION_KEY =
        'native_background_transfer_demo_ids';

    private const TEST_DOWNLOAD_URL =
        'https://science.nasa.gov/wp-content/uploads/2023/10/Color_The_Universe_hires.pdf';

    private const TEST_FILE_NAME =
        'NASA Color the Universe.pdf';

    private const TEST_MAX_SIZE =
        10 * 1024 * 1024;

    private const TEST_UPLOAD_URL =
        'https://httpbin.org/post';

    private const TEST_UPLOAD_METHOD =
        'POST';

    private const TEST_UPLOAD_MAX_SIZE =
        1 * 1024 * 1024;

    private const MAX_SESSION_TRANSFERS = 10;

    private const TERMINAL_STATUSES = [
        'succeeded',
        'failed',
        'cancelled',
    ];

    public function index(
        Request $request,
        NativeDocumentPickerState $pickerState,
    ): View {
        return view('native-background-transfer', [
            'testFileName' => self::TEST_FILE_NAME,
            'testMaxSize' => self::TEST_MAX_SIZE,
            'testUploadMaxSize' => self::TEST_UPLOAD_MAX_SIZE,
            'selectedDocument' =>
                $pickerState->selection(
                    $request->session(),
                ),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $transferId = (string) Str::uuid();

        try {
            $result = NativeBackgroundTransfer::startDownload([
                'id' => $transferId,
                'url' => self::TEST_DOWNLOAD_URL,
                'mime_types' => [
                    'application/pdf',
                ],
                'max_size' => self::TEST_MAX_SIZE,
            ]);
        } catch (Throwable $exception) {
            $this->logFailure(
                'Background download could not be started',
                $transferId,
                $exception,
            );

            return response()->json([
                'accepted' => false,
                'transfer_id' => $transferId,
                'status' => 'failed',
                'error_code' => 'UNKNOWN_ERROR',
                'error_message' =>
                    'The background download could not be started.',
            ], 500);
        }

        $accepted =
            ($result->accepted ?? false) === true;

        $nativeId =
            is_string($result->id ?? null)
                ? $result->id
                : null;

        if (
            $nativeId === null ||
            ! hash_equals($transferId, $nativeId)
        ) {
            return response()->json([
                'accepted' => false,
                'transfer_id' => $transferId,
                'status' => 'failed',
                'error_code' => 'INVALID_NATIVE_RESULT',
                'error_message' =>
                    'The native transfer returned an invalid result.',
            ], 500);
        }

        $payload = [
            'accepted' => $accepted,
            'transfer_id' => $transferId,
            'type' => 'download',
            'status' =>
                is_string($result->status ?? null)
                    ? $result->status
                    : ($accepted ? 'queued' : 'failed'),
            'error_code' =>
                $this->nullableString(
                    $result->errorCode ?? null,
                ),
            'error_message' =>
                $this->nullableString(
                    $result->errorMessage ?? null,
                ),
        ];

        if (! $accepted) {
            return response()->json(
                $payload,
                422,
            );
        }

        $this->rememberTransfer(
            $request,
            $transferId,
        );

        return response()->json(
            $payload,
            202,
        );
    }

    public function startUpload(
        Request $request,
        NativeDocumentPickerState $pickerState,
    ): JsonResponse {
        $selection =
            $pickerState->selection(
                $request->session(),
            );

        if ($selection === null) {
            return response()->json([
                'accepted' => false,
                'transfer_id' => null,
                'type' => 'upload',
                'status' => 'failed',
                'error_code' =>
                    'SOURCE_DOCUMENT_UNAVAILABLE',
                'error_message' =>
                    'Select a document with the Document Picker first.',
            ], 422);
        }

        if (
            $selection['size'] >
            self::TEST_UPLOAD_MAX_SIZE
        ) {
            return response()->json([
                'accepted' => false,
                'transfer_id' => null,
                'type' => 'upload',
                'status' => 'failed',
                'error_code' => 'FILE_TOO_LARGE',
                'error_message' =>
                    'For this upload test, select a file no larger than 1 MB.',
            ], 422);
        }

        $transferId =
            (string) Str::uuid();

        try {
            $result =
                NativeBackgroundTransfer::startUpload([
                    'id' => $transferId,
                    'source_document_id' =>
                        $selection['request_id'],
                    'url' =>
                        self::TEST_UPLOAD_URL,
                    'method' =>
                        self::TEST_UPLOAD_METHOD,
                    'max_size' =>
                        self::TEST_UPLOAD_MAX_SIZE,
                ]);
        } catch (Throwable $exception) {
            $this->logFailure(
                'Background upload could not be started',
                $transferId,
                $exception,
            );

            return response()->json([
                'accepted' => false,
                'transfer_id' => $transferId,
                'type' => 'upload',
                'status' => 'failed',
                'error_code' => 'UNKNOWN_ERROR',
                'error_message' =>
                    'The background upload could not be started.',
            ], 500);
        }

        $accepted =
            ($result->accepted ?? false) === true;

        $nativeId =
            is_string($result->id ?? null)
                ? $result->id
                : null;

        $nativeType =
            is_string($result->type ?? null)
                ? $result->type
                : null;

        if (
            $nativeId === null ||
            ! hash_equals(
                $transferId,
                $nativeId,
            ) ||
            $nativeType !== 'upload'
        ) {
            return response()->json([
                'accepted' => false,
                'transfer_id' => $transferId,
                'type' => 'upload',
                'status' => 'failed',
                'error_code' =>
                    'INVALID_NATIVE_RESULT',
                'error_message' =>
                    'The native upload returned an invalid result.',
            ], 500);
        }

        $payload = [
            'accepted' => $accepted,
            'transfer_id' => $transferId,
            'type' => 'upload',
            'status' =>
                is_string($result->status ?? null)
                    ? $result->status
                    : ($accepted ? 'queued' : 'failed'),
            'error_code' =>
                $this->nullableString(
                    $result->errorCode ?? null,
                ),
            'error_message' =>
                $this->nullableString(
                    $result->errorMessage ?? null,
                ),
        ];

        if (! $accepted) {
            return response()->json(
                $payload,
                422,
            );
        }

        $this->rememberTransfer(
            $request,
            $transferId,
        );

        return response()->json(
            $payload,
            202,
        );
    }
    public function status(
        Request $request,
        string $transferId,
    ): JsonResponse {
        if (! $this->ownsTransfer($request, $transferId)) {
            return $this->forbidden();
        }

        try {
            $result =
                NativeBackgroundTransfer::getStatus(
                    $transferId,
                );
        } catch (Throwable $exception) {
            $this->logFailure(
                'Background transfer status could not be read',
                $transferId,
                $exception,
            );

            return response()->json([
                'transfer_id' => $transferId,
                'status' => 'unavailable',
                'terminal' => false,
                'error_code' => 'STATUS_UNAVAILABLE',
                'error_message' =>
                    'The native transfer status is temporarily unavailable.',
            ], 503);
        }

        return $this->resultResponse(
            $transferId,
            $result,
        );
    }

    public function transfers(
        Request $request,
    ): JsonResponse {
        $transfers = [];

        foreach (
            $this->ownedTransferIds($request)
            as $transferId
        ) {
            try {
                $result =
                    NativeBackgroundTransfer::getStatus(
                        $transferId,
                    );
            } catch (Throwable $exception) {
                $this->logFailure(
                    'Background transfer list status could not be read',
                    $transferId,
                    $exception,
                );

                continue;
            }

            if (
                ! $result instanceof NativeBackgroundTransferResult ||
                ! hash_equals($transferId, $result->id)
            ) {
                continue;
            }

            $transfers[] =
                $this->safeResult($result);
        }

        return response()->json([
            'transfers' => $transfers,
        ]);
    }

    public function cancel(
        Request $request,
        string $transferId,
    ): JsonResponse {
        if (! $this->ownsTransfer($request, $transferId)) {
            return $this->forbidden();
        }

        try {
            $result =
                NativeBackgroundTransfer::cancel(
                    $transferId,
                );
        } catch (Throwable $exception) {
            $this->logFailure(
                'Background transfer could not be cancelled',
                $transferId,
                $exception,
            );

            return response()->json([
                'transfer_id' => $transferId,
                'status' => 'unavailable',
                'terminal' => false,
                'error_code' => 'CANCEL_UNAVAILABLE',
                'error_message' =>
                    'The native cancellation request is temporarily unavailable.',
            ], 503);
        }

        return $this->resultResponse(
            $transferId,
            $result,
        );
    }

    public function consume(
        Request $request,
        string $transferId,
    ): JsonResponse {
        if (! $this->ownsTransfer($request, $transferId)) {
            return $this->forbidden();
        }

        try {
            $result =
                NativeBackgroundTransfer::consumeResult(
                    $transferId,
                );
        } catch (Throwable $exception) {
            $this->logFailure(
                'Background transfer result could not be consumed',
                $transferId,
                $exception,
            );

            return response()->json([
                'transfer_id' => $transferId,
                'status' => 'unavailable',
                'terminal' => false,
                'error_code' => 'RESULT_UNAVAILABLE',
                'error_message' =>
                    'The native result is temporarily unavailable.',
            ], 503);
        }

        return $this->resultResponse(
            $transferId,
            $result,
        );
    }

    private function resultResponse(
        string $expectedId,
        mixed $result,
    ): JsonResponse {
        if (
            ! $result instanceof NativeBackgroundTransferResult ||
            ! hash_equals($expectedId, $result->id)
        ) {
            return response()->json([
                'transfer_id' => $expectedId,
                'status' => 'failed',
                'terminal' => true,
                'error_code' => 'INVALID_NATIVE_RESULT',
                'error_message' =>
                    'An invalid native transfer result was received.',
            ], 500);
        }

        return response()->json(
            $this->safeResult($result),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function safeResult(
        NativeBackgroundTransferResult $result,
    ): array {
        return [
            ...$result->toArray(),
            'transfer_id' => $result->id,
            'terminal' => in_array(
                $result->status,
                self::TERMINAL_STATUSES,
                true,
            ),
        ];
    }

    private function rememberTransfer(
        Request $request,
        string $transferId,
    ): void {
        $ids = $this->ownedTransferIds(
            $request,
        );

        $ids = array_values(
            array_filter(
                $ids,
                static fn (string $id): bool =>
                    ! hash_equals(
                        $id,
                        $transferId,
                    ),
            ),
        );

        $ids[] = $transferId;

        $ids = array_slice(
            $ids,
            -self::MAX_SESSION_TRANSFERS,
        );

        $request->session()->put(
            self::SESSION_KEY,
            $ids,
        );
    }

    private function ownsTransfer(
        Request $request,
        string $transferId,
    ): bool {
        foreach (
            $this->ownedTransferIds($request)
            as $ownedId
        ) {
            if (hash_equals($ownedId, $transferId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function ownedTransferIds(
        Request $request,
    ): array {
        $ids = $request->session()->get(
            self::SESSION_KEY,
            [],
        );

        if (! is_array($ids)) {
            $request->session()->forget(
                self::SESSION_KEY,
            );

            return [];
        }

        return array_values(
            array_filter(
                $ids,
                static fn (mixed $id): bool =>
                    is_string($id) &&
                    Str::isUuid($id),
            ),
        );
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'status' => 'forbidden',
            'terminal' => true,
            'error_code' => 'TRANSFER_NOT_OWNED',
            'error_message' =>
                'This background transfer is not valid for the current session.',
        ], 403);
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : Str::limit(
                $value,
                500,
                '',
            );
    }

    private function logFailure(
        string $message,
        string $transferId,
        Throwable $exception,
    ): void {
        Log::warning($message, [
            'transfer_id' => $transferId,
            'exception_type' => $exception::class,
        ]);
    }
}