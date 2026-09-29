<?php

declare(strict_types=1);

namespace Bbs\NativePasskeys;

use Bbs\NativePasskeys\Contracts\NativeBridge;
use Bbs\NativePasskeys\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class NativePasskeysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NativeBridge::class,
            NativePhpBridge::class,
        );

        $this->app->singleton(
            NativePasskeys::class,
            static fn ($app): NativePasskeys =>
                new NativePasskeys(
                    bridge: $app->make(
                        NativeBridge::class,
                    ),
                ),
        );
    }
}
