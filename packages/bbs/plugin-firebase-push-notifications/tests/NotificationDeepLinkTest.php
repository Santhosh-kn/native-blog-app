<?php

declare(strict_types=1);

namespace Bbs\FirebasePushNotifications\Tests;

use PHPUnit\Framework\TestCase;

final class NotificationDeepLinkTest extends TestCase
{
    private string $pluginPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginPath = dirname(__DIR__);
    }

    private function contents(string $relativePath): string
    {
        $content = file_get_contents(
            $this->pluginPath.'/'.$relativePath
        );

        $this->assertIsString($content);

        return $content;
    }

    private function manifest(): array
    {
        $manifest = json_decode(
            $this->contents('nativephp.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertIsArray($manifest);

        return $manifest;
    }

    public function test_manifest_registers_private_tap_file_bridge(): void
    {
        $functions = $this->manifest()['bridge_functions'];
        $function = collect($functions)->firstWhere(
            'name',
            'FirebasePushNotifications.GetPendingNotification'
        );

        $this->assertIsArray($function);

        $this->assertSame(
            'com.bbs.plugins.firebase_push_notifications.FirebasePushNotificationsFunctions.GetPendingNotification',
            $function['android']
        );

        $this->assertArrayNotHasKey('ios', $function);
    }

    public function test_native_store_normalizes_versioned_allow_list(): void
    {
        $content = $this->contents(
            'resources/android/FirebasePushNotificationTapStore.kt'
        );

        $this->assertStringContainsString(
            'internal object FirebasePushNotificationTapStore',
            $content
        );

        $this->assertStringContainsString(
            'private const val CONTRACT_VERSION = 1',
            $content
        );

        foreach ([
            'navigation_version',
            'navigation_destination',
            'navigation_resource_id',
            '"home"',
            '"posts"',
            '"post_create"',
            '"post_edit"',
            '"push_settings"',
        ] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $content
            );
        }

        $this->assertStringContainsString(
            'UUID.randomUUID().toString()',
            $content
        );

        $this->assertStringContainsString(
            'AtomicFile',
            $content
        );

        $this->assertStringContainsString(
            'context.filesDir',
            $content
        );
    }

    public function test_notification_intent_contains_only_fixed_uri_and_tap_id(): void
    {
        $content = $this->contents(
            'resources/android/FirebasePushMessagingService.kt'
        );

        $this->assertStringContainsString(
            'FirebasePushNotificationTapStore.create(',
            $content
        );

        $this->assertStringContainsString(
            'Uri.Builder()',
            $content
        );

        $this->assertStringContainsString(
            '.scheme(INTERNAL_SCHEME)',
            $content
        );

        $this->assertStringContainsString(
            '.appendQueryParameter(',
            $content
        );

        $this->assertStringContainsString(
            '"nativeblog"',
            $content
        );

        $this->assertStringNotContainsString(
            'data.forEach',
            $content
        );

        $this->assertStringNotContainsString(
            'putExtra(key, value)',
            $content
        );
    }

    public function test_native_bridge_returns_only_private_file_reference(): void
    {
        $content = $this->contents(
            'resources/android/FirebasePushNotificationsFunctions.kt'
        );

        $this->assertStringContainsString(
            'class GetPendingNotification',
            $content
        );

        $this->assertStringContainsString(
            'FirebasePushNotificationTapStore',
            $content
        );

        $this->assertStringContainsString(
            '.readableFile(',
            $content
        );

        $this->assertStringContainsString(
            'response["path"]',
            $content
        );

        $this->assertStringNotContainsString(
            'response["payload"]',
            $content
        );

        $this->assertStringNotContainsString(
            'response["destination"]',
            $content
        );
    }

    public function test_trusted_php_consumes_private_tap_file_once(): void
    {
        $content = $this->contents(
            'src/FirebasePushNotifications.php'
        );

        $this->assertStringContainsString(
            'function getPendingNotification',
            $content
        );

        $this->assertStringContainsString(
            'FirebasePushNotifications.GetPendingNotification',
            $content
        );

        $this->assertStringContainsString(
            'file_get_contents($path)',
            $content
        );

        $this->assertStringContainsString(
            'JSON_THROW_ON_ERROR',
            $content
        );

        $this->assertStringContainsString(
            '@unlink($path)',
            $content
        );

        $this->assertStringContainsString(
            "'firebase_push_notification_taps'",
            $content
        );

        $this->assertStringContainsString(
            'hash_equals(',
            $content
        );
    }

    public function test_javascript_cannot_read_pending_notification_file(): void
    {
        $content = $this->contents(
            'resources/js/firebasePushNotifications.js'
        );

        $this->assertStringNotContainsString(
            'getPendingNotification',
            $content
        );

        $this->assertStringNotContainsString(
            'FirebasePushNotifications.GetPendingNotification',
            $content
        );
    }

    public function test_readme_documents_data_only_v1_contract(): void
    {
        $content = $this->contents('README.md');

        foreach ([
            'Data-only message requirement',
            'navigation_version',
            'navigation_destination',
            'navigation_resource_id',
            'post_edit',
            'Invalid or unsupported destinations fall back to Home',
        ] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $content
            );
        }
    }
}
