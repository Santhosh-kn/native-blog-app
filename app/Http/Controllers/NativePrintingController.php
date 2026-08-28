<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\NativePrintingSamplePdf;
use Bbs\NativePrinting\Facades\NativePrinting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class NativePrintingController extends Controller
{
    private const SESSION_KEY = 'native_printing_requests';

    private const CACHE_PREFIX = 'native_printing_result:';

    private const KNOWN_STATUSES = [
        'accepted',
        'presented',
        'closed',
        'submitted',
        'blocked',
        'cancelled',
        'completed',
        'failed',
    ];

    private const TERMINAL_STATUSES = [
        'closed',
        'cancelled',
        'completed',
        'failed',
    ];

    public function __construct(
        private readonly NativePrintingSamplePdf $samplePdf,
    ) {
    }

    public function index(): View
    {
        return view('native-printing', [
            'sampleFileName' => NativePrintingSamplePdf::FILE_NAME,
        ]);
    }

    public function availability(): JsonResponse
    {
        try {
            $result = NativePrinting::isAvailable();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'available' => false,
                'preview_supported' => false,
                'printing_supported' => false,
                'error_code' => 'PRINTING_UNAVAILABLE',
                'error_message' =>
                    'Native PDF preview and printing are unavailable.',
            ], 500);
        }

        return response()->json([
            'available' => ($result->available ?? false) === true,
            'preview_supported' =>
                ($result->preview_supported ?? false) === true,
            'printing_supported' =>
                ($result->printing_supported ?? false) === true,
            'error_code' => $this->nullableString(
                $result->error_code ?? null,
            ),
            'error_message' => $this->nullableString(
                $result->error_message ?? null,
            ),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        return $this->startAction($request, 'preview');
    }

    public function print(Request $request): JsonResponse
    {
        return $this->startAction($request, 'print');
    }

    public function status(
        Request $request,
        string $requestId,
    ): JsonResponse {
        $requests = $request->session()->get(
            self::SESSION_KEY,
            [],
        );

        $expectedAction = is_array($requests)
            ? ($requests[$requestId] ?? null)
            : null;

        if (! is_string($expectedAction)) {
            return response()->json([
                'status' => 'forbidden',
                'message' =>
                    'This native printing request is not valid.',
            ], 403);
        }

        $result = Cache::pull(
            self::CACHE_PREFIX.$requestId,
        );

        if ($result === null) {
            return response()->json([
                'request_id' => $requestId,
                'action' => $expectedAction,
                'status' => 'pending',
                'terminal' => false,
            ]);
        }

        if (! is_array($result)) {
            $this->forgetRequest($request, $requestId);

            return response()->json([
                'status' => 'failed',
                'terminal' => true,
                'message' =>
                    'An invalid native printing result was received.',
            ], 500);
        }

        $resultRequestId = $result['request_id'] ?? null;
        $resultAction = $result['action'] ?? null;
        $resultStatus = $result['status'] ?? null;

        if (
            ! is_string($resultRequestId) ||
            ! hash_equals($requestId, $resultRequestId) ||
            $resultAction !== $expectedAction ||
            ! is_string($resultStatus) ||
            ! in_array(
                $resultStatus,
                self::KNOWN_STATUSES,
                true,
            )
        ) {
            $this->forgetRequest($request, $requestId);

            return response()->json([
                'status' => 'failed',
                'terminal' => true,
                'message' =>
                    'An invalid native printing result was received.',
            ], 500);
        }

        $terminal = in_array(
            $resultStatus,
            self::TERMINAL_STATUSES,
            true,
        );

        if ($terminal) {
            $this->forgetRequest($request, $requestId);
        }

        return response()->json([
            'request_id' => $requestId,
            'action' => $expectedAction,
            'status' => $resultStatus,
            'terminal' => $terminal,
            'job_id' => $this->nullableString(
                $result['job_id'] ?? null,
            ),
            'error_code' => $this->nullableString(
                $result['error_code'] ?? null,
            ),
            'error_message' => $this->nullableString(
                $result['error_message'] ?? null,
            ),
        ]);
    }

    private function startAction(
        Request $request,
        string $action,
    ): JsonResponse {
        $requestId = (string) Str::uuid();

        Cache::forget(self::CACHE_PREFIX.$requestId);

        try {
            $path = $this->samplePdf->ensure();

            $result = $action === 'preview'
                ? NativePrinting::preview(
                    $path,
                    'Native Printing Sample',
                    $requestId,
                )
                : NativePrinting::print(
                    $path,
                    'Native Printing Sample',
                    $requestId,
                );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'accepted' => false,
                'request_id' => $requestId,
                'action' => $action,
                'status' => 'failed',
                'error_code' => 'UNKNOWN_ERROR',
                'error_message' =>
                    'The native PDF action could not be started.',
            ], 500);
        }

        $accepted = ($result->accepted ?? false) === true;

        $payload = [
            'accepted' => $accepted,
            'request_id' => $requestId,
            'action' => $action,
            'status' => is_string($result->status ?? null)
                ? $result->status
                : ($accepted ? 'accepted' : 'failed'),
            'job_id' => $this->nullableString(
                $result->job_id ?? null,
            ),
            'error_code' => $this->nullableString(
                $result->error_code ?? null,
            ),
            'error_message' => $this->nullableString(
                $result->error_message ?? null,
            ),
        ];

        if (! $accepted) {
            return response()->json($payload, 422);
        }

        $this->rememberRequest(
            $request,
            $requestId,
            $action,
        );

        return response()->json($payload, 202);
    }

    private function rememberRequest(
        Request $request,
        string $requestId,
        string $action,
    ): void {
        $requests = $request->session()->get(
            self::SESSION_KEY,
            [],
        );

        if (! is_array($requests)) {
            $requests = [];
        }

        $requests = array_filter(
            $requests,
            static fn (mixed $value): bool =>
                is_string($value),
        );

        $requests = array_slice(
            $requests,
            -9,
            null,
            true,
        );

        $requests[$requestId] = $action;

        $request->session()->put(
            self::SESSION_KEY,
            $requests,
        );
    }

    private function forgetRequest(
        Request $request,
        string $requestId,
    ): void {
        $requests = $request->session()->get(
            self::SESSION_KEY,
            [],
        );

        if (! is_array($requests)) {
            $request->session()->forget(self::SESSION_KEY);

            return;
        }

        unset($requests[$requestId]);

        if ($requests === []) {
            $request->session()->forget(self::SESSION_KEY);

            return;
        }

        $request->session()->put(
            self::SESSION_KEY,
            $requests,
        );
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
}
