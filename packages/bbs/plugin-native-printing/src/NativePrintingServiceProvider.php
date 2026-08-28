<?php

declare(strict_types=1);

namespace Bbs\NativePrinting;

use Bbs\NativePrinting\Contracts\NativeBridge;
use Bbs\NativePrinting\Support\LocalPdfValidator;
use Bbs\NativePrinting\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class NativePrintingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NativeBridge::class,
            NativePhpBridge::class,
        );

        $this->app->singleton(
            LocalPdfValidator::class,
            static fn ($app): LocalPdfValidator =>
                new LocalPdfValidator([
                    $app->storagePath('app'),
                ]),
        );

        $this->app->singleton(
            NativePrinting::class,
            static fn ($app): NativePrinting =>
                new NativePrinting(
                    validator: $app->make(
                        LocalPdfValidator::class,
                    ),
                    bridge: $app->make(
                        NativeBridge::class,
                    ),
                ),
        );
    }

    public function boot(): void
    {
        //
    }
}
