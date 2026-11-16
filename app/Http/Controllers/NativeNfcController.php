<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Bbs\NativeNfc\NativeNfc;
use Bbs\NativeNfc\NativeNfcResult;
use Bbs\NativeNfc\Support\NativeNfcErrorCode as ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

final class NativeNfcController extends Controller
{
    private const SESSION_KEY = 'native_nfc_diagnostics_request_ids';

    private const MAX_SESSION_REQUESTS = 32;

    public function __construct(
        private readonly NativeNfc $nfc,
    ) {}

    public function index(Request $request): Response
    {
        return response()
            ->view('native-nfc', [
                'requestIds' => array_reverse($this->ownedIds($request)),
            ])
            ->header('Cache-Control', 'no-store');
    }

    public function availability(): JsonResponse
    {
        try {
            $result = $this->nfc->isAvailable();

            return $this->json([
                'supported' => $result->supported,
                'enabled' => $result->enabled,
                'availability' => $result->availability,
                'platform' => 'android',
            ]);
        } catch (NativeNfcException $exception) {
            return $this->error($exception);
        } catch (Throwable) {
            return $this->unknownError();
        }
    }

    public function diagnostics(): JsonResponse
    {
        $id = (string) Str::uuid();

        try {
            $status = $this->nfc->getStatus($id);

            $operations = [
                'getStatus' => [
                    'method' => 'NativeNfc.GetStatus',
                    'idMatches' => hash_equals($id, $status->id),
                    'result' => $this->metadata($status),
                ],
            ];

            if (! $this->isMissing($status, $id)) {
                return $this->json([
                    'passed' => false,
                    'requestId' => $id,
                    'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                    'operations' => $operations,
                ]);
            }

            try {
                $this->nfc->consumeResult($id);

                return $this->json([
                    'passed' => false,
                    'requestId' => $id,
                    'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                    'operations' => $operations,
                ]);
            } catch (NativeNfcException $exception) {
                $passed = $exception->errorCode === ErrorCode::RESULT_NOT_FOUND;

                $operations['consumeResult'] = [
                    'method' => 'NativeNfc.ConsumeResult',
                    'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                    'actualErrorCode' => $exception->errorCode,
                    'passed' => $passed,
                ];
            }

            return $this->json([
                'passed' => $operations['consumeResult']['passed'],
                'requestId' => $id,
                'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                'operations' => $operations,
            ]);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Native NFC diagnostics failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->json([
                'passed' => false,
                'requestId' => $id,
                'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                'errorCode' => ErrorCode::UNKNOWN_ERROR,
                'errorMessage' => ErrorCode::message(ErrorCode::UNKNOWN_ERROR),
                'operations' => [],
            ], 500);
        }
    }

    public function scan(Request $request): JsonResponse
    {
        $id = (string) Str::uuid();
        $this->rememberId($request, $id);

        try {
            $result = $this->nfc->startScan($id);

            return $this->json([
                'result' => $this->metadata($result),
            ]);
        } catch (NativeNfcException $exception) {
            return $this->error($exception, $id);
        } catch (Throwable) {
            return $this->unknownError($id);
        }
    }

    public function write(Request $request): JsonResponse
    {
        $text = $request->input('text');

        if (! is_string($text)) {
            return $this->json([
                'errorCode' => ErrorCode::INVALID_PARAMETERS,
                'errorMessage' => ErrorCode::message(ErrorCode::INVALID_PARAMETERS),
            ], 422);
        }

        $id = (string) Str::uuid();
        $this->rememberId($request, $id);

        try {
            $result = $this->nfc->startWrite($text, $id);

            return $this->json([
                'result' => $this->metadata($result),
            ]);
        } catch (NativeNfcException $exception) {
            return $this->error($exception, $id);
        } catch (Throwable) {
            return $this->unknownError($id);
        }
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $this->requireOwnedId($request, $id);

        try {
            return $this->json([
                'result' => $this->metadata($this->nfc->getStatus($id)),
            ]);
        } catch (NativeNfcException $exception) {
            return $this->error($exception, $id);
        } catch (Throwable) {
            return $this->unknownError($id);
        }
    }

    public function stop(Request $request, string $id): JsonResponse
    {
        $this->requireOwnedId($request, $id);

        try {
            return $this->json([
                'result' => $this->metadata($this->nfc->stopSession($id)),
            ]);
        } catch (NativeNfcException $exception) {
            return $this->error($exception, $id);
        } catch (Throwable) {
            return $this->unknownError($id);
        }
    }

    public function consume(Request $request, string $id): JsonResponse
    {
        $this->requireOwnedId($request, $id);

        try {
            $status = $this->nfc->getStatus($id);

            if ($status->operation !== 'start_scan' || $status->status !== 'read') {
                return $this->json([
                    'errorCode' => $status->status === 'not_found'
                        ? ErrorCode::RESULT_NOT_FOUND
                        : ErrorCode::RESULT_NOT_READY,
                    'errorMessage' => $status->status === 'not_found'
                        ? ErrorCode::message(ErrorCode::RESULT_NOT_FOUND)
                        : ErrorCode::message(ErrorCode::RESULT_NOT_READY),
                    'result' => $this->metadata($status),
                ], 409);
            }

            if ($status->consumed) {
                return $this->json([
                    'errorCode' => ErrorCode::RESULT_ALREADY_CONSUMED,
                    'errorMessage' => ErrorCode::message(ErrorCode::RESULT_ALREADY_CONSUMED),
                    'result' => $this->metadata($status),
                ], 409);
            }

            // The payload is returned only by this explicit one-time operation.
            $text = $this->nfc->consumeResult($id);

            $metadata = $this->metadata($status);
            $metadata['consumed'] = true;

            return $this->json([
                'result' => $metadata,
                'text' => $text,
            ]);
        } catch (NativeNfcException $exception) {
            return $this->error($exception, $id);
        } catch (Throwable) {
            return $this->unknownError($id);
        }
    }

    /** @return list<string> */
    private function ownedIds(Request $request): array
    {
        $ids = $request->session()->get(self::SESSION_KEY, []);

        if (! is_array($ids)) {
            return [];
        }

        $ids = array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id)
                && preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                    $id
                ) === 1,
        );

        return array_slice(
            array_values(array_unique($ids)),
            -self::MAX_SESSION_REQUESTS,
        );
    }

    private function rememberId(Request $request, string $id): void
    {
        $ids = $this->ownedIds($request);
        $ids[] = $id;

        $request->session()->put(
            self::SESSION_KEY,
            array_slice(
                array_values(array_unique($ids)),
                -self::MAX_SESSION_REQUESTS,
            ),
        );
    }

    private function requireOwnedId(Request $request, string $id): void
    {
        abort_unless(in_array($id, $this->ownedIds($request), true), 404);
    }

    private function isMissing(NativeNfcResult $result, string $id): bool
    {
        return hash_equals($id, $result->id)
            && $result->operation === ''
            && $result->status === 'not_found'
            && $result->errorCode === null;
    }

    /** @return array<string, mixed> */
    private function metadata(NativeNfcResult $result): array
    {
        return [
            'id' => $result->id,
            'operation' => $result->operation,
            'status' => $result->status,
            'consumed' => $result->consumed,
            'createdAtMs' => $result->createdAtMs,
            'completedAtMs' => $result->completedAtMs,
            'errorCode' => $result->errorCode,
        ];
    }

    private function error(
        NativeNfcException $exception,
        ?string $id = null,
    ): JsonResponse {
        $status = match ($exception->errorCode) {
            ErrorCode::INVALID_PARAMETERS,
            ErrorCode::INVALID_REQUEST_ID,
            ErrorCode::EMPTY_TEXT,
            ErrorCode::TEXT_TOO_LARGE,
            ErrorCode::REQUEST_TOO_LARGE => 422,

            ErrorCode::OPERATION_BUSY,
            ErrorCode::RESULT_NOT_READY,
            ErrorCode::RESULT_ALREADY_CONSUMED,
            ErrorCode::REQUEST_ID_REUSED => 409,

            ErrorCode::RESULT_NOT_FOUND,
            ErrorCode::RESULT_EXPIRED => 404,

            ErrorCode::NFC_UNAVAILABLE,
            ErrorCode::NFC_DISABLED,
            ErrorCode::BRIDGE_UNAVAILABLE,
            ErrorCode::ACTIVITY_UNAVAILABLE => 503,

            default => 500,
        };

        $payload = [
            'errorCode' => $exception->errorCode,
            'errorMessage' => $exception->getMessage(),
        ];

        if ($id !== null) {
            $payload['requestId'] = $id;
        }

        return $this->json($payload, $status);
    }

    private function unknownError(?string $id = null): JsonResponse
    {
        return $this->error(
            new NativeNfcException(
                ErrorCode::UNKNOWN_ERROR,
                ErrorCode::message(ErrorCode::UNKNOWN_ERROR),
            ),
            $id,
        );
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }
}