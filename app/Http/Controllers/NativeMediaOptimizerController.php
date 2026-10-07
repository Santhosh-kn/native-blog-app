<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\NativeMediaOptimizerDemoState as State;
use App\Support\NativeMediaOptimizerFileGuard as FileGuard;
use Bbs\NativeDocumentPicker\Facades\NativeDocumentPicker;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Bbs\NativeMediaOptimizer\Facades\NativeMediaOptimizer;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerRequestValidator as Validator;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerResult as Result;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Share;
use Throwable;

final class NativeMediaOptimizerController extends Controller
{
    public function __construct(private readonly State $state, private readonly FileGuard $files) {}

    public function index(Request $request): Response
    {
        return response()->view('native-media-optimizer', ['demoState' => $this->data($request)])
            ->withHeaders($this->headers());
    }

    public function availability(): JsonResponse
    {
        try { $capabilities = NativeMediaOptimizer::isAvailable(); }
        catch (Throwable) {
            $capabilities = (object) ['platform' => 'android', 'available' => false, 'images' => false, 'video' => false, 'thumbnails' => false,
                'errorCode' => ErrorCode::NATIVE_UNAVAILABLE, 'errorMessage' => ErrorCode::message(ErrorCode::NATIVE_UNAVAILABLE)];
        }
        return $this->json((array) $capabilities);
    }

    public function pick(Request $request): JsonResponse
    {
        $input = $this->input($request, ['kind']);
        $kind = $input['kind'] ?? null;
        if (! in_array($kind, ['image', 'video'], true)) { return $this->error(ErrorCode::INVALID_OPTIONS, 422); }
        $id = (string) Str::uuid();
        $this->state->rememberPick($request->session(), $this->owner($request), $id, $kind);
        // Save ownership before entering native code, including uncertain acknowledgements.
        $request->session()->save();
        try {
            $reply = NativeDocumentPicker::pick([
                'id' => $id,
                'mime_types' => $kind === 'image' ? State::IMAGE_MIMES : State::VIDEO_MIMES,
                'max_size' => $kind === 'image' ? Validator::MAX_IMAGE_INPUT_BYTES : Validator::MAX_INPUT_BYTES,
            ]);
        } catch (Throwable) {
            return $this->json(['id' => $id, 'accepted' => false, 'retryable' => true, 'message' => 'The selection acknowledgement is unavailable. Refresh its status.'], 503);
        }
        if (($reply->id ?? null) !== $id || ! is_bool($reply->accepted ?? null)) {
            return $this->json(['id' => $id, 'accepted' => false, 'retryable' => true, 'errorCode' => ErrorCode::INVALID_NATIVE_RESPONSE, 'message' => 'The selection acknowledgement is unavailable. Refresh its status.'], 503);
        }
        if (! $reply->accepted && in_array($reply->errorCode ?? null, ['ACTIVITY_UNAVAILABLE', 'UNKNOWN_ERROR'], true)) {
            return $this->json(['id' => $id, 'accepted' => false, 'retryable' => true, 'message' => 'The selection acknowledgement is unavailable. Refresh its status.'], 503);
        }
        if (! $reply->accepted) { $this->state->finishPick($request->session(), $this->owner($request), $id); }
        return $this->json(['id' => $id, 'accepted' => $reply->accepted,
            'message' => $reply->accepted ? 'Select a file in Android Files, then return here.' : 'Android could not start media selection.'], $reply->accepted ? 202 : 422);
    }

    public function pickStatus(Request $request, string $id): JsonResponse
    {
        $owned = $this->data($request)['picks'][$id] ?? null;
        if ($owned === null) { return $this->forbidden(); }
        try { $result = NativeDocumentPicker::getStatus($id); }
        catch (Throwable) { return $this->error(ErrorCode::NATIVE_UNAVAILABLE, 503); }
        if (! $result instanceof NativeDocumentPickerResult || $result->id !== $id) { return $this->error(ErrorCode::INVALID_NATIVE_RESPONSE, 502); }
        if ($result->status === 'pending') { return $this->json(['id' => $id, 'status' => 'pending', 'terminal' => false]); }
        $selection = null;
        if ($result->status === 'succeeded') {
            $selection = $this->files->selection($result, $id, $owned['kind']);
            if ($selection === null) { return $this->error(ErrorCode::SOURCE_UNAVAILABLE, 422); }
        } elseif (! in_array($result->status, ['failed', 'cancelled', 'not_found'], true)) {
            return $this->error(ErrorCode::INVALID_NATIVE_RESPONSE, 502);
        }
        $this->state->finishPick($request->session(), $this->owner($request), $id, $selection);
        return $this->json(['id' => $id, 'status' => $result->status, 'terminal' => true, 'selection' => $selection,
            'message' => $selection !== null ? 'Media selected.' : ($result->status === 'cancelled' ? 'Selection cancelled.' : 'Media selection failed. Try selecting the file again.')]);
    }

    public function start(Request $request): JsonResponse
    {
        $input = $this->input($request, ['operation', 'source_document_id', 'options']);
        $operation = $input['operation'] ?? null;
        $source = $input['source_document_id'] ?? null;
        $options = $input['options'] ?? [];
        if (! in_array($operation, State::OPERATIONS, true) || ! is_array($options) || ($options !== [] && array_is_list($options)) ||
            array_key_exists('id', $options) || array_key_exists('source_document_id', $options)) { return $this->error(ErrorCode::INVALID_OPTIONS, 422); }
        $selection = $this->data($request)['selection'];
        if (! Validator::isRequestId($source) || $selection === null || $selection['id'] !== $source) { return $this->forbidden(); }
        $image = in_array($selection['mime_type'], State::IMAGE_MIMES, true);
        if (($operation === 'OptimizeImage' && ! $image) || (in_array($operation, ['OptimizeVideo', 'GenerateThumbnail'], true) && $image)) {
            return $this->error(ErrorCode::UNSUPPORTED_MEDIA, 422);
        }
        $id = (string) Str::uuid();
        $options['id'] = $id;
        $error = Validator::validate($operation, [...$options, 'source_document_id' => $source]);
        if ($error !== null) { return $this->error($error, 422); }
        if (! $this->state->rememberJob($request->session(), $this->owner($request), $id, $operation, $source)) {
            return $this->error(ErrorCode::LIMIT_EXCEEDED, 429);
        }
        $request->session()->save();
        try {
            $result = match ($operation) {
                'InspectMedia' => NativeMediaOptimizer::inspectMedia($source, $options),
                'OptimizeImage' => NativeMediaOptimizer::optimizeImage($source, $options),
                'OptimizeVideo' => NativeMediaOptimizer::optimizeVideo($source, $options),
                'GenerateThumbnail' => NativeMediaOptimizer::generateThumbnail($source, $options),
            };
        } catch (Throwable) { return $this->json(['id' => $id, 'retryable' => true, 'message' => 'The job acknowledgement is unavailable. Refresh its status.'], 503); }
        return $this->result($request, $this->bind($request, $id, $result), $result->accepted ? 202 : 422);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $this->job($request, $id);
        try { $result = NativeMediaOptimizer::getStatus($id); }
        catch (Throwable) { return $this->error(ErrorCode::NATIVE_UNAVAILABLE, 503); }
        return $this->result($request, $this->bind($request, $id, $result));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->input($request, []); $this->job($request, $id);
        try { $result = NativeMediaOptimizer::cancel($id); }
        catch (Throwable) { return $this->error(ErrorCode::NATIVE_UNAVAILABLE, 503); }
        return $this->result($request, $this->bind($request, $id, $result));
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        $verified = $this->verifiedOutput($request, $id);
        if ($verified['size'] > FileGuard::MAX_PREVIEW_BYTES) {
            return $this->json(['errorCode' => ErrorCode::LIMIT_EXCEEDED, 'message' => 'This output exceeds the 12 MiB inline preview limit. Use Open / Share output.'], 413);
        }
        $base64 = $this->files->preview($verified);
        if ($base64 === null) { return $this->error(ErrorCode::OUTPUT_NOT_FOUND, 404); }
        return $this->json(['id' => $id, 'mime_type' => $verified['mime_type'], 'base64' => $base64]);
    }

    public function share(Request $request, string $id): JsonResponse
    {
        $this->input($request, []);
        $verified = $this->verifiedOutput($request, $id);
        try { Share::file('Optimized media', 'Media processed in Native Blog', $verified['path']); }
        catch (Throwable) { return $this->error(ErrorCode::NATIVE_UNAVAILABLE, 503); }
        return $this->json(['id' => $id, 'submitted' => true, 'message' => 'Android share request submitted. Choose an app to open or share this output.']);
    }

    public function delete(Request $request, string $id): JsonResponse
    {
        $this->input($request, []); $this->job($request, $id);
        try {
            $result = $this->bind($request, $id, NativeMediaOptimizer::getResult($id));
            if (! $result->accepted) { return $this->result($request, $result, 422); }
            if (! $result->isTerminal()) { return $this->error(ErrorCode::OUTPUT_IN_USE, 409); }
            $reply = NativeMediaOptimizer::deleteOutput($id);
        } catch (Throwable) { return $this->error(ErrorCode::NATIVE_UNAVAILABLE, 503); }
        if ($reply->deleted) {
            $this->state->finishJob($request->session(), $this->owner($request), $id, false);
        }
        return $this->json((array) $reply, $reply->deleted ? 200 : 422);
    }

    private function verifiedOutput(Request $request, string $id): array
    {
        $this->job($request, $id);
        try { $result = $this->bind($request, $id, NativeMediaOptimizer::getResult($id)); }
        catch (Throwable) { throw new HttpResponseException($this->error(ErrorCode::NATIVE_UNAVAILABLE, 503)); }
        $verified = $this->files->output($result);
        if ($verified === null) { throw new HttpResponseException($this->error(ErrorCode::OUTPUT_NOT_FOUND, 404)); }
        return $verified;
    }

    private function bind(Request $request, string $id, Result $result): Result
    {
        $job = $this->job($request, $id);
        if ($result->id !== $id || ($result->operation !== null && $result->operation !== $job['operation']) ||
            ($result->sourceDocumentId !== null && $result->sourceDocumentId !== $job['source_document_id']) ||
            ($result->accepted && ($result->operation !== $job['operation'] || $result->sourceDocumentId !== $job['source_document_id']))) {
            return Result::failure($id, ErrorCode::INVALID_NATIVE_RESPONSE, $job['operation']);
        }
        return $result;
    }

    private function result(Request $request, Result $result, int $status = 200): JsonResponse
    {
        $retryable = ! $result->accepted && in_array($result->errorCode, [ErrorCode::NATIVE_UNAVAILABLE, ErrorCode::PERSIST_FAILED, ErrorCode::INVALID_NATIVE_RESPONSE], true);
        $terminal = $result->isTerminal() && ! $retryable;
        if ($terminal && $result->id !== null) { $this->state->finishJob($request->session(), $this->owner($request), $result->id, $result->outputAvailable); }
        return $this->json([...$result->toMetadata(), 'terminal' => $terminal, 'retryable' => $retryable], $retryable ? 503 : $status);
    }

    private function job(Request $request, string $id): array
    {
        $job = $this->data($request)['jobs'][$id] ?? null;
        if ($job === null) { throw new HttpResponseException($this->forbidden()); }
        return $job;
    }

    private function data(Request $request): array
    {
        $data = $this->state->read($request->session(), $this->owner($request));
        unset($data['owner']);
        return $data;
    }

    private function owner(Request $request): string { return (string) $request->user()->getAuthIdentifier(); }

    private function input(Request $request, array $allowed): array
    {
        if (strlen($request->getContent()) > Validator::MAX_REQUEST_BYTES) { throw new HttpResponseException($this->error(ErrorCode::REQUEST_TOO_LARGE, 413)); }
        $input = $request->all();
        if (array_diff(array_keys($input), $allowed) !== []) { throw new HttpResponseException($this->error(ErrorCode::INVALID_OPTIONS, 422)); }
        return $input;
    }

    private function forbidden(): JsonResponse { return $this->json(['message' => 'This media request does not belong to the current user and session.'], 403); }
    private function error(string $code, int $status): JsonResponse { return $this->json(['errorCode' => $code, 'message' => ErrorCode::message($code)], $status); }
    private function json(array $data, int $status = 200): JsonResponse { return response()->json($data, $status)->withHeaders($this->headers()); }
    private function headers(): array { return ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']; }
}
