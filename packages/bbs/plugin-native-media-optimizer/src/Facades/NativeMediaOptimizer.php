<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Facades;

use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object isAvailable()
 * @method static NativeMediaOptimizerResult inspectMedia(string $sourceDocumentId, array $options = [])
 * @method static NativeMediaOptimizerResult optimizeImage(string $sourceDocumentId, array $options = [])
 * @method static NativeMediaOptimizerResult optimizeVideo(string $sourceDocumentId, array $options = [])
 * @method static NativeMediaOptimizerResult generateThumbnail(string $sourceDocumentId, array $options = [])
 * @method static NativeMediaOptimizerResult getStatus(mixed $id)
 * @method static NativeMediaOptimizerResult cancel(mixed $id)
 * @method static NativeMediaOptimizerResult getResult(mixed $id)
 * @method static object deleteOutput(mixed $id)
 */
final class NativeMediaOptimizer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativeMediaOptimizer\NativeMediaOptimizer::class;
    }
}
