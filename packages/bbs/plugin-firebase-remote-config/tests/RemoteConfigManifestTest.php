<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use PHPUnit\Framework\TestCase;

final class RemoteConfigManifestTest extends TestCase
{
    public function test_manifest_declares_exactly_the_public_android_bridge_methods(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true, 16, JSON_THROW_ON_ERROR);
        $names = array_column($manifest['bridge_functions'], 'name');
        $expected = Contract::METHODS;
        sort($names); sort($expected);
        self::assertSame($expected, $names);
        self::assertSame(count($names), count(array_unique($names)));
        $source = file_get_contents(dirname(__DIR__).'/resources/android/FirebaseRemoteConfigFunctions.kt');
        foreach ($manifest['bridge_functions'] as $function) {
            $operation = substr($function['name'], strlen('FirebaseRemoteConfig.'));
            self::assertSame('com.bbs.plugins.firebase_remote_config.FirebaseRemoteConfigFunctions.'.$operation, $function['android']);
            self::assertStringContainsString('class '.$operation.'(activity: FragmentActivity)', $source);
            self::assertArrayNotHasKey('ios', $function);
        }
        self::assertSame(['android'], $manifest['platforms']);
        self::assertSame(33, $manifest['android']['min_version']);
    }

    public function test_manifest_reuses_the_existing_bom_without_analytics_or_replacement_google_assets(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__).'/nativephp.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['platform(com.google.firebase:firebase-bom:33.12.0)', 'com.google.firebase:firebase-config'],
            $manifest['android']['dependencies']['implementation']);
        self::assertArrayNotHasKey('gradle_plugins', $manifest['android']);
        self::assertArrayNotHasKey('hooks', $manifest);
        self::assertSame(['android.permission.INTERNET'], $manifest['android']['permissions']);
    }
}
