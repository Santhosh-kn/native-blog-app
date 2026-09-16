<?php

declare(strict_types=1);

namespace Bbs\NativeBackgroundTransfer;

use Bbs\NativeBackgroundTransfer\Contracts\NativeBridge;
use Bbs\NativeBackgroundTransfer\Support\NativePhpBridge;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class NativeBackgroundTransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NativeBridge::class,
            static fn (): NativeBridge => new NativePhpBridge(),
        );

        $this->app->singleton(
            NativeBackgroundTransfer::class,
            static fn (Application $app): NativeBackgroundTransfer =>
                new NativeBackgroundTransfer(
                    bridge: $app->make(NativeBridge::class),
                ),
        );
    }
}