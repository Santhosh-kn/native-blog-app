<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer;

use Bbs\NativeMediaOptimizer\Contracts\NativeBridge;
use Bbs\NativeMediaOptimizer\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class NativeMediaOptimizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NativeBridge::class, NativePhpBridge::class);
        $this->app->singleton(NativeMediaOptimizer::class, static fn ($app): NativeMediaOptimizer => new NativeMediaOptimizer(
            bridge: $app->make(NativeBridge::class),
            privateOutputPath: $app->storagePath('app/native-media-optimizer'),
        ));
    }
}
