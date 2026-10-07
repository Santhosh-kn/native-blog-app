<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer;

use Bbs\NativeMediaOptimizer\Contracts\NativeBridge;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerRequestValidator as Validator;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerResult as Result;
use Illuminate\Support\Str;

final class NativeMediaOptimizer
{
    public function __construct(
        private readonly NativeBridge $bridge,
        private readonly string $privateOutputPath,
    ) {}

    public function isAvailable(): object
    {
        $response = $this->bridge->call('NativeMediaOptimizer.IsAvailable');
        if ($response === null) {
            return $this->unavailable(ErrorCode::NATIVE_UNAVAILABLE);
        }
        $data = get_object_vars($response);
        $keys = ['platform', 'available', 'images', 'video', 'thumbnails'];
        if (array_diff($keys, array_keys($data)) !== [] || array_diff(array_keys($data), $keys) !== [] || $data['platform'] !== 'android') {
            return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
        }
        foreach (['available', 'images', 'video', 'thumbnails'] as $key) {
            if (! is_bool($data[$key])) {
                return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
            }
        }
        if ($data['available'] !== ($data['images'] || $data['video'] || $data['thumbnails'])) {
            return $this->unavailable(ErrorCode::INVALID_NATIVE_RESPONSE);
        }
        return (object) [...$data, 'errorCode' => null, 'errorMessage' => null];
    }

    public function inspectMedia(string $sourceDocumentId, array $options = []): Result
    {
        return $this->start('InspectMedia', $sourceDocumentId, $options);
    }

    public function optimizeImage(string $sourceDocumentId, array $options = []): Result
    {
        return $this->start('OptimizeImage', $sourceDocumentId, $options);
    }

    public function optimizeVideo(string $sourceDocumentId, array $options = []): Result
    {
        return $this->start('OptimizeVideo', $sourceDocumentId, $options);
    }

    public function generateThumbnail(string $sourceDocumentId, array $options = []): Result
    {
        return $this->start('GenerateThumbnail', $sourceDocumentId, $options);
    }

    public function getStatus(mixed $id): Result
    {
        return $this->query('GetStatus', $id);
    }

    public function cancel(mixed $id): Result
    {
        return $this->query('Cancel', $id);
    }

    public function getResult(mixed $id): Result
    {
        return $this->query('GetResult', $id);
    }

    public function deleteOutput(mixed $id): object
    {
        $error = Validator::validate('DeleteOutput', ['id' => $id]);
        if ($error !== null) {
            return $this->deleteFailure(is_string($id) ? $id : null, $error);
        }
        $response = $this->bridge->call('NativeMediaOptimizer.DeleteOutput', ['id' => $id]);
        if ($response === null) {
            return $this->deleteFailure($id, ErrorCode::NATIVE_UNAVAILABLE);
        }
        $data = get_object_vars($response);
        $code = $data['errorCode'] ?? null;
        if (array_diff(['id', 'deleted', 'errorCode'], array_keys($data)) !== [] || array_diff(array_keys($data), ['id', 'deleted', 'errorCode', 'errorMessage']) !== [] || $data['id'] !== $id || ! is_bool($data['deleted']) || ($data['deleted'] && $code !== null) || (! $data['deleted'] && ! ErrorCode::isKnown($code))) {
            return $this->deleteFailure($id, ErrorCode::INVALID_NATIVE_RESPONSE);
        }
        return (object) ['id' => $id, 'deleted' => $data['deleted'], 'errorCode' => $code, 'errorMessage' => $code === null ? null : ErrorCode::message($code)];
    }

    private function start(string $operation, string $source, array $options): Result
    {
        $id = array_key_exists('id', $options) ? $options['id'] : (string) Str::uuid();
        $safeId = Validator::isRequestId($id) ? $id : null;
        if (array_key_exists('source_document_id', $options)) {
            return Result::failure($safeId, ErrorCode::INVALID_OPTIONS, $operation);
        }
        $parameters = [...$options, 'id' => $id, 'source_document_id' => $source];
        $error = Validator::validate($operation, $parameters);
        if ($error !== null) {
            return Result::failure($safeId, $error, $operation);
        }
        $response = $this->bridge->call('NativeMediaOptimizer.'.$operation, $parameters);
        return $response === null
            ? Result::failure($id, ErrorCode::NATIVE_UNAVAILABLE, $operation)
            : Result::fromNative($response, $id, $this->privateOutputPath, $operation, $source);
    }

    private function query(string $operation, mixed $id): Result
    {
        $error = Validator::validate($operation, ['id' => $id]);
        if ($error !== null) {
            return Result::failure(is_string($id) ? $id : null, $error);
        }
        $response = $this->bridge->call('NativeMediaOptimizer.'.$operation, ['id' => $id]);
        return $response === null
            ? Result::failure($id, ErrorCode::NATIVE_UNAVAILABLE)
            : Result::fromNative($response, $id, $this->privateOutputPath);
    }

    private function unavailable(string $code): object
    {
        return (object) ['platform' => 'android', 'available' => false, 'images' => false, 'video' => false, 'thumbnails' => false, 'errorCode' => $code, 'errorMessage' => ErrorCode::message($code)];
    }

    private function deleteFailure(?string $id, string $code): object
    {
        return (object) ['id' => Validator::isRequestId($id) ? $id : null, 'deleted' => false, 'errorCode' => $code, 'errorMessage' => ErrorCode::message($code)];
    }
}
