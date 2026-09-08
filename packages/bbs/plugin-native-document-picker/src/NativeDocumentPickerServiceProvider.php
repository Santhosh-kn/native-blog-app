<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker;

use Bbs\NativeDocumentPicker\Contracts\NativeBridge;
use Bbs\NativeDocumentPicker\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class NativeDocumentPickerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NativeBridge::class,
            NativePhpBridge::class,
        );

        $this->app->singleton(
            NativeDocumentPicker::class,
            static fn ($app): NativeDocumentPicker => new NativeDocumentPicker(
                bridge: $app->make(NativeBridge::class),
                privateStoragePath: $app->storagePath(
                    'app/native-document-picker',
                ),
            ),
        );
    }
}
