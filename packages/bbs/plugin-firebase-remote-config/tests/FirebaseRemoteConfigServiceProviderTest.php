<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Contracts\NativeBridge;
use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfigServiceProvider;
use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig;
use Bbs\FirebaseRemoteConfig\Facades\FirebaseRemoteConfig as RemoteConfigFacade;
use Bbs\FirebaseRemoteConfig\Support\NativePhpBridge;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class FirebaseRemoteConfigServiceProviderTest extends TestCase
{
    public function test_provider_registers_an_injectable_singleton_bridge(): void
    {
        $application = new Application(dirname(__DIR__, 4));
        (new FirebaseRemoteConfigServiceProvider($application))->register();
        self::assertInstanceOf(NativePhpBridge::class, $application->make(NativeBridge::class));
        self::assertSame($application->make(NativeBridge::class), $application->make(NativeBridge::class));
        self::assertInstanceOf(FirebaseRemoteConfig::class, $application->make(FirebaseRemoteConfig::class));
        self::assertSame($application->make(FirebaseRemoteConfig::class), $application->make(FirebaseRemoteConfig::class));
    }

    public function test_facade_resolves_the_public_service(): void
    {
        $application = new Application(dirname(__DIR__, 4));
        (new FirebaseRemoteConfigServiceProvider($application))->register();
        RemoteConfigFacade::clearResolvedInstances();
        RemoteConfigFacade::setFacadeApplication($application);
        try {
            self::assertSame($application->make(FirebaseRemoteConfig::class), RemoteConfigFacade::getFacadeRoot());
        } finally {
            RemoteConfigFacade::clearResolvedInstances();
            RemoteConfigFacade::setFacadeApplication(null);
        }
    }
}
