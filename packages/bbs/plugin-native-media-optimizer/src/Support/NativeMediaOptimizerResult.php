<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Support;

use JsonSerializable;

final readonly class NativeMediaOptimizerResult implements JsonSerializable
{
    public bool $success;
    public ?string $errorMessage;

    private const OPERATIONS = ['InspectMedia', 'OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'];
    private const STATES = ['pending', 'running', 'cancelling', 'succeeded', 'failed', 'cancelled', 'interrupted', 'not_found'];
    private const PHASES = [
        'pending' => ['queued'],
        'running' => ['inspecting', 'decoding', 'encoding', 'finalizing'],
        'cancelling' => ['cancelling'],
        'succeeded' => ['completed'],
        'failed' => ['failed'],
        'cancelled' => ['cancelled'],
        'interrupted' => ['interrupted'],
        'not_found' => ['failed'],
    ];

    private function __construct(
        public ?string $id,
        public ?string $operation,
        public bool $accepted,
        public string $status,
        public ?string $sourceDocumentId,
        public ?int $progress,
        public string $phase,
        public ?array $input,
        public ?array $output,
        public bool $outputAvailable,
        public ?string $errorCode,
    ) {
        $this->success = $accepted && $status === 'succeeded';
        $this->errorMessage = $errorCode === null ? null : NativeMediaOptimizerErrorCode::message($errorCode);
    }

    public static function failure(?string $id, string $code, ?string $operation = null): self
    {
        $code = NativeMediaOptimizerErrorCode::isKnown($code) ? $code : NativeMediaOptimizerErrorCode::INVALID_NATIVE_RESPONSE;
        return new self(
            NativeMediaOptimizerRequestValidator::isRequestId($id) ? $id : null,
            in_array($operation, self::OPERATIONS, true) ? $operation : null,
            false,
            $code === NativeMediaOptimizerErrorCode::RESULT_NOT_FOUND ? 'not_found' : 'failed',
            null, null, 'failed', null, null, false, $code,
        );
    }

    /**
     * Native paths are retained for trusted PHP file guards only.
     * All public serializers omit paths; they are never accepted from the UI.
     */
    public static function fromNative(
        ?object $response,
        string $requestedId,
        string $outputRoot,
        ?string $expectedOperation = null,
        ?string $expectedSource = null,
    ): self {
        $invalid = static fn (): self => self::failure($requestedId, NativeMediaOptimizerErrorCode::INVALID_NATIVE_RESPONSE, $expectedOperation);
        if ($response === null || ! NativeMediaOptimizerRequestValidator::isRequestId($requestedId)) {
            return $invalid();
        }
        $data = get_object_vars($response);
        $required = ['id', 'operation', 'accepted', 'status', 'sourceDocumentId', 'progress', 'phase', 'input', 'output', 'outputAvailable', 'errorCode'];
        if (array_diff($required, array_keys($data)) !== [] || array_diff(array_keys($data), [...$required, 'errorMessage']) !== []) {
            return $invalid();
        }
        if ($data['id'] !== $requestedId || ! is_bool($data['accepted']) || ! is_string($data['status']) || ! in_array($data['status'], self::STATES, true)) {
            return $invalid();
        }
        $operation = $data['operation'];
        if (($operation !== null && ! in_array($operation, self::OPERATIONS, true)) || ($expectedOperation !== null && $operation !== $expectedOperation)) {
            return $invalid();
        }
        $accepted = $data['accepted'];
        $status = $data['status'];
        $source = $data['sourceDocumentId'];
        if (($source !== null && ! NativeMediaOptimizerRequestValidator::isRequestId($source)) || ($accepted && ($operation === null || $source === null)) || ($accepted && $expectedSource !== null && $source !== $expectedSource)) {
            return $invalid();
        }
        if (! is_string($data['phase']) || ! in_array($data['phase'], self::PHASES[$status], true)) {
            return $invalid();
        }
        $progress = $data['progress'];
        if ($progress !== null && (! is_int($progress) || $progress < 0 || $progress > 100)) {
            return $invalid();
        }
        if (($status === 'succeeded' && $progress !== 100) || ($status === 'pending' && $progress !== 0) || (in_array($status, ['running', 'cancelling'], true) && $progress === 100) || (! $accepted && $progress !== null)) {
            return $invalid();
        }
        if ((! $accepted && ! in_array($status, ['failed', 'not_found'], true)) || ($accepted && $status === 'not_found')) {
            return $invalid();
        }
        $code = $data['errorCode'];
        if ($code !== null && ! NativeMediaOptimizerErrorCode::isKnown($code)) {
            return $invalid();
        }
        if ((in_array($status, ['failed', 'interrupted', 'not_found'], true) && $code === null) || (! in_array($status, ['failed', 'interrupted', 'not_found'], true) && $code !== null) || ($status === 'interrupted' && $code !== NativeMediaOptimizerErrorCode::PROCESS_INTERRUPTED) || ($status === 'not_found' && $code !== NativeMediaOptimizerErrorCode::RESULT_NOT_FOUND)) {
            return $invalid();
        }
        $input = self::metadata($data['input'], false);
        if (($data['input'] !== null && $input === null) || ($status === 'succeeded' && $input === null) || (! $accepted && $input !== null)) {
            return $invalid();
        }
        if ($input !== null && (($operation === 'OptimizeImage' && ! str_starts_with($input['mime_type'], 'image/')) || (in_array($operation, ['OptimizeVideo', 'GenerateThumbnail'], true) && ! str_starts_with($input['mime_type'], 'video/')))) {
            return $invalid();
        }
        $output = null;
        if ($data['output'] !== null) {
            if ($status !== 'succeeded' || $operation === 'InspectMedia' || ! is_object($data['output'])) {
                return $invalid();
            }
            $output = get_object_vars($data['output']);
            if (array_diff(array_keys($output), ['id', 'path', 'mime_type', 'size', 'width', 'height', 'duration_ms', 'rotation_degrees', 'has_audio']) !== [] || ($output['id'] ?? null) !== $requestedId) {
                return $invalid();
            }
            $metadata = self::metadata((object) array_diff_key($output, ['id' => true, 'path' => true]), true);
            if ($metadata === null || ! self::outputBinding($output, $metadata, $operation, $requestedId, $outputRoot)) {
                return $invalid();
            }
            $output = ['id' => $requestedId, 'path' => $output['path'], ...$metadata];
        }
        if ($status === 'succeeded' && $operation !== 'InspectMedia' && $output === null) {
            return $invalid();
        }
        if (! is_bool($data['outputAvailable']) || ($data['outputAvailable'] && $output === null)) {
            return $invalid();
        }
        return new self($requestedId, $operation, $accepted, $status, $source, $progress, $data['phase'], $input, $output, $data['outputAvailable'], $code);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled', 'interrupted', 'not_found'], true);
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        $output = $this->output;
        if ($output !== null) {
            unset($output['path']);
        }
        return [
            'id' => $this->id, 'operation' => $this->operation,
            'accepted' => $this->accepted, 'status' => $this->status,
            'success' => $this->success, 'sourceDocumentId' => $this->sourceDocumentId,
            'progress' => $this->progress, 'phase' => $this->phase,
            'input' => $this->input, 'output' => $output,
            'outputAvailable' => $this->outputAvailable,
            'errorCode' => $this->errorCode, 'errorMessage' => $this->errorMessage,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toMetadata();
    }

    private static function metadata(mixed $value, bool $output): ?array
    {
        if (! is_object($value)) {
            return null;
        }
        $data = get_object_vars($value);
        $keys = ['mime_type', 'size', 'width', 'height', 'duration_ms', 'rotation_degrees', 'has_audio'];
        if (array_diff($keys, array_keys($data)) !== [] || array_diff(array_keys($data), $keys) !== []) {
            return null;
        }
        if (! is_string($data['mime_type']) || ! in_array($data['mime_type'], ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm', 'video/quicktime'], true)) {
            return null;
        }
        $image = str_starts_with($data['mime_type'], 'image/');
        $maximumSize = $image && ! $output ? NativeMediaOptimizerRequestValidator::MAX_IMAGE_INPUT_BYTES : NativeMediaOptimizerRequestValidator::MAX_INPUT_BYTES;
        if (! is_int($data['size']) || $data['size'] < 1 || $data['size'] > $maximumSize || ! is_int($data['width']) || ! is_int($data['height']) || $data['width'] < 1 || $data['height'] < 1 || $data['width'] > 100000 || $data['height'] > 100000 || $data['width'] * $data['height'] > NativeMediaOptimizerRequestValidator::MAX_IMAGE_SOURCE_PIXELS) {
            return null;
        }
        if (! is_int($data['rotation_degrees']) || ! in_array($data['rotation_degrees'], [0, 90, 180, 270], true) || ! is_bool($data['has_audio'])) {
            return null;
        }
        if ($image) {
            if ($data['duration_ms'] !== null || $data['has_audio'] !== false || ($output && $data['rotation_degrees'] !== 0)) {
                return null;
            }
        } elseif (! is_int($data['duration_ms']) || $data['duration_ms'] < 1 || $data['duration_ms'] > NativeMediaOptimizerRequestValidator::MAX_DURATION_MS) {
            return null;
        }
        return $data;
    }

    private static function outputBinding(array $output, array $metadata, string $operation, string $id, string $root): bool
    {
        $mime = $metadata['mime_type'];
        if ($operation === 'OptimizeVideo') {
            if ($mime !== 'video/mp4' || $metadata['width'] > NativeMediaOptimizerRequestValidator::MAX_VIDEO_EDGE || $metadata['height'] > NativeMediaOptimizerRequestValidator::MAX_VIDEO_EDGE || $metadata['width'] * $metadata['height'] > NativeMediaOptimizerRequestValidator::MAX_VIDEO_OUTPUT_PIXELS) {
                return false;
            }
        } else {
            $edge = $operation === 'GenerateThumbnail' ? NativeMediaOptimizerRequestValidator::MAX_THUMBNAIL_EDGE : NativeMediaOptimizerRequestValidator::MAX_IMAGE_EDGE;
            if (! str_starts_with($mime, 'image/') || $metadata['width'] > $edge || $metadata['height'] > $edge || $metadata['width'] * $metadata['height'] > NativeMediaOptimizerRequestValidator::MAX_IMAGE_OUTPUT_PIXELS) {
                return false;
            }
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4'][$mime] ?? null;
        $path = $output['path'] ?? null;
        if ($extension === null || ! is_string($path) || strlen($path) > 4096 || preg_match('//u', $path) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $path) !== 0 || $root === '') {
            return false;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        $name = $id.'.'.$extension;
        if (hash_equals($root.'/'.$name, $path)) {
            return true;
        }
        // Android's configured /data/user/0 root may canonicalize to /data/data.
        // Resolve only the trusted parent, never a path supplied by native data.
        // The fixed output directory can be absent after deletion or recovery.
        $parent = realpath(dirname($root));
        if (! is_string($parent) || is_link($root)) {
            return false;
        }
        $canonicalRoot = rtrim(str_replace('\\', '/', $parent), '/').'/'.basename($root);
        return hash_equals($canonicalRoot.'/'.$name, $path);
    }
}
