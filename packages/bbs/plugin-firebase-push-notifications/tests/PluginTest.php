<?php

declare(strict_types=1);

namespace Bbs\FirebasePushNotifications\Tests;

use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    private string $pluginPath;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginPath = dirname(__DIR__);
        $this->manifestPath = $this->pluginPath.'/nativephp.json';
    }

    private function manifest(): array
    {
        $manifest = json_decode(
            file_get_contents($this->manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertIsArray($manifest);

        return $manifest;
    }

    private function contents(string $relativePath): string
    {
        $content = file_get_contents(
            $this->pluginPath.'/'.$relativePath
        );

        $this->assertIsString($content);

        return $content;
    }

    public function test_manifest_contains_valid_json(): void
    {
        $this->assertFileExists($this->manifestPath);
        $this->assertIsArray($this->manifest());
    }

    public function test_manifest_contains_correct_identity(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(
            'bbs/plugin-firebase-push-notifications',
            $manifest['name']
        );

        $this->assertSame(
            'FirebasePushNotifications',
            $manifest['namespace']
        );

        $this->assertStringContainsString(
            'Firebase Cloud Messaging',
            $manifest['description']
        );
    }

    public function test_manifest_declares_android_platform(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(['android'], $manifest['platforms']);
        $this->assertSame(21, $manifest['android']['min_version']);
        $this->assertSame('15.0', $manifest['ios']['min_version']);
    }

    public function test_manifest_declares_exact_bridge_functions(): void
    {
        $manifest = $this->manifest();

        $names = array_column(
            $manifest['bridge_functions'],
            'name'
        );

        $this->assertSame([
            'FirebasePushNotifications.CheckPermission',
            'FirebasePushNotifications.RequestPermission',
            'FirebasePushNotifications.GetToken',
            'FirebasePushNotifications.GetStoredToken',
        ], $names);

        foreach ($manifest['bridge_functions'] as $function) {
            $this->assertArrayHasKey('name', $function);
            $this->assertArrayHasKey('android', $function);
            $this->assertArrayHasKey('description', $function);
            $this->assertArrayNotHasKey('ios', $function);
        }
    }

    public function test_manifest_declares_android_permissions(): void
    {
        $permissions = $this->manifest()['android']['permissions'];

        $this->assertContains(
            'android.permission.INTERNET',
            $permissions
        );

        $this->assertContains(
            'android.permission.POST_NOTIFICATIONS',
            $permissions
        );
    }

    public function test_manifest_does_not_duplicate_firebase_dependencies(): void
    {
        $dependencies = $this->manifest()['android']['dependencies']['implementation'];

        $this->assertSame([], $dependencies);
    }

    public function test_manifest_declares_event_without_hooks(): void
    {
        $manifest = $this->manifest();

        $this->assertContains(
            'Bbs\\FirebasePushNotifications\\Events\\FirebasePushNotificationsCompleted',
            $manifest['events']
        );

        $this->assertArrayNotHasKey('hooks', $manifest);
    }

    public function test_kotlin_bridge_file_exists(): void
    {
        $path = $this->pluginPath
            .'/resources/android/FirebasePushNotificationsFunctions.kt';

        $this->assertFileExists($path);

        $content = $this->contents(
            'resources/android/FirebasePushNotificationsFunctions.kt'
        );

        $this->assertStringContainsString(
            'package com.bbs.plugins.firebase_push_notifications',
            $content
        );

        $this->assertStringContainsString(
            'object FirebasePushNotificationsFunctions',
            $content
        );

        $this->assertStringContainsString(
            'BridgeFunction',
            $content
        );
    }

    public function test_kotlin_contains_all_bridge_classes(): void
    {
        $content = $this->contents(
            'resources/android/FirebasePushNotificationsFunctions.kt'
        );

        foreach ($this->manifest()['bridge_functions'] as $function) {
            $parts = explode('.', $function['android']);
            $className = end($parts);

            $this->assertStringContainsString(
                "class {$className}",
                $content
            );
        }
    }

    public function test_kotlin_handles_notification_permission(): void
    {
        $content = $this->contents(
            'resources/android/FirebasePushNotificationsFunctions.kt'
        );

        $this->assertStringContainsString(
            'Manifest.permission.POST_NOTIFICATIONS',
            $content
        );

        $this->assertStringContainsString(
            'ActivityCompat.requestPermissions',
            $content
        );

        $this->assertStringContainsString(
            'Build.VERSION_CODES.TIRAMISU',
            $content
        );
    }

    public function test_kotlin_retrieves_token_asynchronously(): void
    {
        $content = $this->contents(
            'resources/android/FirebasePushNotificationsFunctions.kt'
        );

        $this->assertStringContainsString(
            'FirebaseMessaging.getInstance().token',
            $content
        );

        $this->assertStringContainsString(
            'NativeActionCoordinator.dispatchEvent',
            $content
        );

        $this->assertStringContainsString(
            'COMPLETED_EVENT',
            $content
        );
    }

    public function test_kotlin_persists_refreshed_tokens_for_secure_synchronization(): void
    {
        $storeContent = $this->contents(
            'resources/android/FirebasePushTokenStore.kt'
        );

        $this->assertStringContainsString(
            'internal object FirebasePushTokenStore',
            $storeContent
        );

        $this->assertStringContainsString(
            'AtomicFile',
            $storeContent
        );

        $this->assertStringContainsString(
            'context.filesDir',
            $storeContent
        );

        $this->assertStringContainsString(
            'TOKEN_FILE_NAME',
            $storeContent
        );

        $this->assertStringContainsString(
            'LEGACY_TOKEN_KEY',
            $storeContent
        );

        $bridgeContent = $this->contents(
            'resources/android/FirebasePushNotificationsFunctions.kt'
        );

        $this->assertStringContainsString(
            'FirebasePushTokenStore.save(',
            $bridgeContent
        );

        $this->assertStringContainsString(
            'class GetStoredToken',
            $bridgeContent
        );

        $this->assertStringContainsString(
            'FirebasePushTokenStore.readableFile(context)',
            $bridgeContent
        );

        $this->assertStringContainsString(
            'response["path"]',
            $bridgeContent
        );

        $this->assertStringNotContainsString(
            'response["token"]',
            $bridgeContent
        );

        $this->assertStringNotContainsString(
            'put("token", token)',
            $bridgeContent
        );

        $serviceContent = $this->contents(
            'resources/android/FirebasePushMessagingService.kt'
        );

        $this->assertStringContainsString(
            'FirebasePushTokenStore.save(',
            $serviceContent
        );
    }

    public function test_javascript_does_not_expose_stored_token_bridge(): void
    {
        $content = $this->contents(
            'resources/js/firebasePushNotifications.js'
        );

        $this->assertStringNotContainsString(
            'getStoredToken',
            $content
        );

        $this->assertStringNotContainsString(
            'FirebasePushNotifications.GetStoredToken',
            $content
        );
    }

    public function test_plugin_has_no_ios_implementation(): void
    {
        $path = $this->pluginPath
            .'/resources/ios/FirebasePushNotificationsFunctions.swift';

        $this->assertFileDoesNotExist($path);
    }

    public function test_service_provider_registers_singleton(): void
    {
        $content = $this->contents(
            'src/FirebasePushNotificationsServiceProvider.php'
        );

        $this->assertStringContainsString(
            'namespace Bbs\FirebasePushNotifications;',
            $content
        );

        $this->assertStringContainsString(
            '$this->app->singleton',
            $content
        );

        $this->assertStringNotContainsString(
            'CopyAssetsCommand',
            $content
        );
    }

    public function test_facade_documents_real_methods(): void
    {
        $content = $this->contents(
            'src/Facades/FirebasePushNotifications.php'
        );

        $this->assertStringContainsString(
            '@method static object|null checkPermission()',
            $content
        );

        $this->assertStringContainsString(
            '@method static object|null requestPermission()',
            $content
        );

        $this->assertStringContainsString(
            '@method static object|null getToken(?string $id = null)',
            $content
        );

        $this->assertStringContainsString(
            '@method static object|null getStoredToken()',
            $content
        );
    }

    public function test_php_methods_map_to_native_bridge_calls(): void
    {
        $content = $this->contents(
            'src/FirebasePushNotifications.php'
        );

        $this->assertStringContainsString(
            'function checkPermission',
            $content
        );

        $this->assertStringContainsString(
            'function requestPermission',
            $content
        );

        $this->assertStringContainsString(
            'function getToken',
            $content
        );

        $this->assertStringContainsString(
            'FirebasePushNotifications.CheckPermission',
            $content
        );

        $this->assertStringContainsString(
            'FirebasePushNotifications.RequestPermission',
            $content
        );

        $this->assertStringContainsString(
            'FirebasePushNotifications.GetToken',
            $content
        );

        $this->assertStringContainsString(
            'function getStoredToken',
            $content
        );

        $this->assertStringContainsString(
            'FirebasePushNotifications.GetStoredToken',
            $content
        );

        $this->assertStringContainsString(
            'is_file($path)',
            $content
        );

        $this->assertStringContainsString(
            'is_readable($path)',
            $content
        );

        $this->assertStringContainsString(
            'file_get_contents($path)',
            $content
        );
    }

    public function test_completion_event_defines_payload(): void
    {
        $content = $this->contents(
            'src/Events/FirebasePushNotificationsCompleted.php'
        );

        $this->assertStringContainsString(
            'public bool $success',
            $content
        );

        $this->assertStringNotContainsString(
            'public ?string $token',
            $content
        );

        $this->assertStringContainsString(
            'public ?string $error',
            $content
        );

        $this->assertStringContainsString(
            'public ?string $id',
            $content
        );
    }

    public function test_composer_configuration_is_valid(): void
    {
        $composer = json_decode(
            $this->contents('composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            'bbs/plugin-firebase-push-notifications',
            $composer['name']
        );

        $this->assertSame('1.0.0', $composer['version']);
        $this->assertSame('nativephp-plugin', $composer['type']);

        $this->assertSame(
            'src/',
            $composer['autoload']['psr-4']['Bbs\\FirebasePushNotifications\\']
        );

        $this->assertSame(
            'nativephp.json',
            $composer['extra']['nativephp']['manifest']
        );
    }

    public function test_manifest_registers_custom_firebase_messaging_service(): void
    {
        $android = $this->manifest()['android'];

        $this->assertSame(
            'com.bbs.plugins.firebase_push_notifications.initializeFirebasePushNotifications',
            $android['init_function']
        );

        $this->assertSame([
            [
                'name' => 'com.bbs.plugins.firebase_push_notifications.FirebasePushMessagingService',
                'exported' => false,
                'intent_filters' => [
                    [
                        'action' => 'com.google.firebase.MESSAGING_EVENT',
                    ],
                ],
            ],
        ], $android['services']);

        $this->assertSame([
            [
                'name' => 'firebase_messaging_notification_delegation_enabled',
                'value' => 'false',
            ],
        ], $android['meta_data']);
    }

    public function test_kotlin_messaging_service_handles_incoming_notifications(): void
    {
        $path = $this->pluginPath
            .'/resources/android/FirebasePushMessagingService.kt';

        $this->assertFileExists($path);

        $content = $this->contents(
            'resources/android/FirebasePushMessagingService.kt'
        );

        $this->assertStringContainsString(
            'class FirebasePushMessagingService : FirebaseMessagingService()',
            $content
        );

        $this->assertStringContainsString(
            'override fun onMessageReceived',
            $content
        );

        $this->assertStringContainsString(
            'NotificationChannel(',
            $content
        );

        $this->assertStringContainsString(
            'NotificationCompat.Builder',
            $content
        );

        $this->assertStringContainsString(
            'NotificationManagerCompat',
            $content
        );

        $this->assertStringContainsString(
            'override fun onNewToken',
            $content
        );
    }

    public function test_kotlin_initializer_disables_notification_delegation(): void
    {
        $path = $this->pluginPath
            .'/resources/android/FirebasePushNotificationsInitializer.kt';

        $this->assertFileExists($path);

        $content = $this->contents(
            'resources/android/FirebasePushNotificationsInitializer.kt'
        );

        $this->assertStringContainsString(
            'fun initializeFirebasePushNotifications(',
            $content
        );

        $this->assertStringContainsString(
            'setNotificationDelegationEnabled(false)',
            $content
        );

        $this->assertStringContainsString(
            'Firebase notification delegation disabled',
            $content
        );
    }
}
