<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Bbs\NativeContacts\NativeContacts;
use Bbs\NativeContacts\Support\NativeContactsErrorCode as ErrorCode;
use Bbs\NativeContacts\Support\NativeContactsRequestValidator as Validator;
use Bbs\NativeContacts\Support\NativeContactsResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

final class NativeContactsController extends Controller
{
    private const SESSION_KEY = 'native_contacts_diagnostics_request_ids';

    private const MAX_SESSION_REQUESTS = 32;

    public function __construct(
        private readonly NativeContacts $contacts,
    ) {}

    public function index(Request $request): Response
    {
        return response()
            ->view('native-contacts', [
                'requestIds' => array_reverse($this->ownedIds($request)),
            ])
            ->header('Cache-Control', 'no-store');
    }

    public function availability(): JsonResponse
    {
        try {
            $result = $this->contacts->isAvailable();

            return $this->json([
                'available' => $result->available,
                'platform' => $result->platform,
                'apiLevel' => $result->apiLevel,
                'minimumApiLevel' => $result->minimumApiLevel,
                'capabilities' => $result->capabilities,
                'errorCode' => $result->errorCode,
                'errorMessage' => $result->errorMessage,
            ]);
        } catch (Throwable) {
            return $this->json([
                'available' => false,
                'platform' => 'android',
                'apiLevel' => null,
                'minimumApiLevel' => 33,
                'capabilities' => array_fill_keys([
                    'pickContact', 'pickPhone', 'pickEmail', 'create', 'open',
                ], false),
                'errorCode' => ErrorCode::UNKNOWN_ERROR,
                'errorMessage' => ErrorCode::message(ErrorCode::UNKNOWN_ERROR),
            ], 500);
        }
    }

    public function diagnostics(): JsonResponse
    {
        $id = (string) Str::uuid();

        try {
            $status = $this->contacts->getStatus($id);

            $operations = [
                'getStatus' => [
                    'method' => 'NativeContacts.GetStatus',
                    'idMatches' => hash_equals($id, $status->id),
                    'result' => $status->toMetadata(),
                ],
            ];

            // Consume only after the fresh ID is confirmed absent.
            if (! $this->isMissing($status, $id)) {
                return $this->json([
                    'passed' => false,
                    'requestId' => $id,
                    'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                    'operations' => $operations,
                ]);
            }

            $consume = $this->contacts->consumeResult($id);

            $operations['consumeResult'] = [
                'method' => 'NativeContacts.ConsumeResult',
                'idMatches' => hash_equals($id, $consume->id),
                'result' => $consume->toMetadata(),
            ];

            return $this->json([
                'passed' => $this->isMissing($consume, $id),
                'requestId' => $id,
                'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
                'operations' => $operations,
            ]);
        } catch (Throwable) {
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

    public function pick(Request $request): JsonResponse
    {
        $mode = $request->input('mode');

        if (! in_array($mode, ['contact', 'phone', 'email'], true)) {
            return $this->json([
                'result' => NativeContactsResult::failure(
                    (string) Str::uuid(),
                    ErrorCode::INVALID_MODE,
                    'pick',
                )->toMetadata(),
            ], 422);
        }

        return $this->start($request, 'pick', $mode);
    }

    public function create(Request $request): JsonResponse
    {
        return $this->start($request, 'create');
    }

    public function status(Request $request, string $id): JsonResponse
    {
        return $this->lookup($request, $id, false);
    }

    public function consume(Request $request, string $id): JsonResponse
    {
        return $this->lookup($request, $id, true);
    }

    public function openSelection(Request $request, string $id): JsonResponse
    {
        $this->requireOwnedId($request, $id);

        $failureId = $id;
        $failureOperation = null;

        try {
            $status = $this->contacts->getStatus($id);

            if (
                $status->operation !== 'pick' ||
                $status->mode !== 'contact' ||
                $status->status !== 'selected' ||
                $status->consumed ||
                $status->errorCode !== null
            ) {
                return $this->json([
                    'result' => $status->toMetadata(),
                    'openAttempted' => false,
                    'message' => 'Choose an unconsumed contact selection first.',
                ], 409);
            }

            $consumed = $this->contacts->consumeResult($id);
            $selection = $consumed->selectedData();
            $uri = $selection['contactUri'] ?? null;

            if (
                $consumed->operation !== 'pick' ||
                $consumed->mode !== 'contact' ||
                $consumed->status !== 'selected' ||
                ! $consumed->consumed ||
                $consumed->errorCode !== null ||
                ! Validator::isContactUri($uri)
            ) {
                return $this->json([
                    'result' => $consumed->toMetadata(),
                    'openAttempted' => false,
                    'message' => 'The selected contact could not be opened.',
                ], 409);
            }

            $openId = (string) Str::uuid();
            $this->rememberId($request, $openId);
            $failureId = $openId;
            $failureOperation = 'open';

            // Use the URI only for this native call; never store or return it.
            $opened = $this->contacts->open($uri, ['id' => $openId]);

            return $this->json([
                'selectionResult' => $consumed->toMetadata(),
                'result' => $opened->toMetadata(),
                'openAttempted' => true,
            ]);
        } catch (Throwable) {
            return $this->json([
                'result' => NativeContactsResult::failure(
                    $failureId,
                    ErrorCode::UNKNOWN_ERROR,
                    $failureOperation,
                )->toMetadata(),
            ], 500);
        }
    }

    private function start(
        Request $request,
        string $operation,
        ?string $mode = null,
    ): JsonResponse {
        $id = (string) Str::uuid();
        $this->rememberId($request, $id);

        try {
            $result = $operation === 'pick'
                ? $this->contacts->pick(['id' => $id, 'mode' => $mode])
                : $this->contacts->create([
                    'id' => $id,
                    'name' => 'Native Contacts Demo',
                    'phone' => '+1 202-555-0100',
                    'email' => 'native.contacts@example.invalid',
                ]);

            return $this->json(['result' => $result->toMetadata()]);
        } catch (Throwable) {
            return $this->json([
                'result' => NativeContactsResult::failure(
                    $id,
                    ErrorCode::UNKNOWN_ERROR,
                    $operation,
                    $mode,
                )->toMetadata(),
            ], 500);
        }
    }

    private function lookup(
        Request $request,
        string $id,
        bool $consume,
    ): JsonResponse {
        $this->requireOwnedId($request, $id);

        try {
            $result = $consume
                ? $this->contacts->consumeResult($id)
                : $this->contacts->getStatus($id);

            $payload = ['result' => $result->toMetadata()];

            if ($consume) {
                // Confirm delivery without exposing names, numbers or URIs.
                $payload['selectionReceived'] = $result->selectedData() !== null;
            }

            return $this->json($payload);
        } catch (Throwable) {
            return $this->json([
                'result' => NativeContactsResult::failure(
                    $id,
                    ErrorCode::UNKNOWN_ERROR,
                )->toMetadata(),
            ], 500);
        }
    }

    private function isMissing(NativeContactsResult $result, string $id): bool
    {
        return hash_equals($id, $result->id) &&
            $result->operation === null &&
            $result->mode === null &&
            $result->status === 'not_found' &&
            ! $result->accepted &&
            ! $result->success &&
            ! $result->cancelled &&
            ! $result->consumed &&
            $result->errorCode === ErrorCode::RESULT_NOT_FOUND &&
            $result->selectedData() === null;
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
            static fn (mixed $id): bool => Validator::isRequestId($id),
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
            array_slice(array_values(array_unique($ids)), -self::MAX_SESSION_REQUESTS),
        );
    }

    private function requireOwnedId(Request $request, string $id): void
    {
        abort_unless(in_array($id, $this->ownedIds($request), true), 404);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }
}
