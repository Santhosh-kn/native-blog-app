<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig;

use Bbs\FirebaseRemoteConfig\Contracts\NativeBridge;
use Bbs\FirebaseRemoteConfig\Support\NativePhpBridge;
use Illuminate\Support\ServiceProvider;

final class FirebaseRemoteConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NativeBridge::class, NativePhpBridge::class);
        $this->app->singleton(FirebaseRemoteConfig::class, static fn ($app): FirebaseRemoteConfig =>
            new FirebaseRemoteConfig($app->make(NativeBridge::class)));
    }
}
