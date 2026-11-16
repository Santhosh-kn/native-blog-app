<?php
declare(strict_types=1);

namespace Bbs\NativeNfc;

use Bbs\NativeNfc\Contracts\NativeBridge;
use Bbs\NativeNfc\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class NativeNfcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NativeBridge::class, NativePhpBridge::class);

        $this->app->singleton(
            NativeNfc::class,
            static fn ($app): NativeNfc => new NativeNfc(
                $app->make(NativeBridge::class)
            )
        );
    }
    public function boot(): void
    {
    }
}
