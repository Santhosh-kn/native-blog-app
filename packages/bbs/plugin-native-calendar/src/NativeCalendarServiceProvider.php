<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar;

use Bbs\NativeCalendar\Commands\ConfigureAndroidManifestCommand;
use Bbs\NativeCalendar\Contracts\NativeBridge;
use Bbs\NativeCalendar\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class NativeCalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NativeBridge::class,
            NativePhpBridge::class,
        );

        $this->app->singleton(
            NativeCalendar::class,
            static fn ($app): NativeCalendar => new NativeCalendar(
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
