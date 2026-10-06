<?php

declare(strict_types=1);

namespace Bbs\NativeContacts;

use Bbs\NativeContacts\Commands\ConfigureAndroidManifestCommand;
use Bbs\NativeContacts\Contracts\NativeBridge;
use Bbs\NativeContacts\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class NativeContactsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NativeBridge::class,
            NativePhpBridge::class,
        );

        $this->app->singleton(
            NativeContacts::class,
            static fn ($app): NativeContacts => new NativeContacts(
                bridge: $app->make(NativeBridge::class),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureAndroidManifestCommand::class,
            ]);
        }
    }
}