<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Bbs\NativePasskeys\Contracts\NativeBridge;
use Bbs\NativePasskeys\Facades\NativePasskeys as NativePasskeysFacade;
use Bbs\NativePasskeys\NativePasskeys as NativePasskeysService;
use Bbs\NativePasskeys\Support\NativePasskeysErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecordingNativePasskeysBridge implements NativeBridge
{
    /**
     * @var list<array{
     *     method: string,
     *     parameters: array<string, mixed>
     * }>
     */
    public array $calls = [];

    public function call(
        string $method,
        array $parameters = [],
    ): ?object {
        $this->calls[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        if ($method === 'NativePasskeys.IsAvailable') {
            return (object) [
                'available' => true,
                'apiLevel' => 36,
            ];
        }

        if (
            in_array(
                $method,
                [
                    'NativePasskeys.GetStatus',
                    'NativePasskeys.Cancel',
                    'NativePasskeys.ConsumeResult',
                ],
                true,
            )
        ) {
            $id = $parameters['id'] ?? null;

            if (! is_string($id)) {
                return null;
            }

            return (object) [
                'id' => $id,
                'operation' => null,
                'status' => 'not_found',
                'success' => false,
                'cancelled' => false,
                'consumed' => false,
                'errorCode' =>
                    NativePasskeysErrorCode::RESULT_NOT_FOUND,
            ];
        }

        return null;
    }
}

final class NativePasskeysHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_requires_device_unlock(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('native-passkeys.index'))
            ->assertRedirect(route('unlock'));
    }

    public function test_page_is_available_after_unlock_without_credential_actions(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
            ])
            ->get(route('native-passkeys.index'));

        $response
            ->assertOk()
            ->assertViewIs('native-passkeys')
            ->assertSeeText('Android readiness')
            ->assertSeeText('Safe native diagnostics')
            ->assertSeeText('Run safe diagnostics')
            ->assertSeeText('Public HTTPS application backend');

        $content = $response->getContent();

        $this->assertStringNotContainsString(
            'NativePasskeys.Create',
            $content,
        );

        $this->assertStringNotContainsString(
            'NativePasskeys.Authenticate',
            $content,
        );

        $this->assertStringNotContainsString(
            'responseJson',
            $content,
        );

        $this->assertStringNotContainsString(
            'requestJson',
            $content,
        );
    }

    public function test_home_view_links_to_passkeys_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->view('home', [
            'posts' => collect(),
        ])
            ->assertSeeText('Passkeys')
            ->assertSee(
                'href="'.route('native-passkeys.index').'"',
                false,
            );
    }

    public function test_availability_uses_only_the_native_readiness_method(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
            ])
            ->getJson(
                route('native-passkeys.availability'),
            );

        $response
            ->assertOk()
            ->assertJson([
                'available' => true,
                'platform' => 'android',
                'api_level' => 36,
                'minimum_api_level' => 28,
                'error_code' => null,
                'error_message' => null,
            ]);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get(
                'Cache-Control',
                '',
            ),
        );

        $this->assertSame(
            [
                [
                    'method' =>
                        'NativePasskeys.IsAvailable',
                    'parameters' => [],
                ],
            ],
            $bridge->calls,
        );
    }

    public function test_diagnostics_use_only_safe_unknown_request_operations(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
            ])
            ->postJson(
                route('native-passkeys.diagnostics'),
            );

        $response
            ->assertOk()
            ->assertJson([
                'passed' => true,
                'expected_error_code' =>
                    NativePasskeysErrorCode::RESULT_NOT_FOUND,
                'operations' => [
                    'get_status' => [
                        'method' =>
                            'NativePasskeys.GetStatus',
                        'id_matches' => true,
                        'operation' => null,
                        'status' => 'not_found',
                        'success' => false,
                        'cancelled' => false,
                        'consumed' => false,
                        'error_code' =>
                            NativePasskeysErrorCode::
                                RESULT_NOT_FOUND,
                    ],
                    'cancel' => [
                        'method' =>
                            'NativePasskeys.Cancel',
                        'id_matches' => true,
                        'operation' => null,
                        'status' => 'not_found',
                        'success' => false,
                        'cancelled' => false,
                        'consumed' => false,
                        'error_code' =>
                            NativePasskeysErrorCode::
                                RESULT_NOT_FOUND,
                    ],
                    'consume_result' => [
                        'method' =>
                            'NativePasskeys.ConsumeResult',
                        'id_matches' => true,
                        'operation' => null,
                        'status' => 'not_found',
                        'success' => false,
                        'cancelled' => false,
                        'consumed' => false,
                        'error_code' =>
                            NativePasskeysErrorCode::
                                RESULT_NOT_FOUND,
                    ],
                ],
            ]);

        $requestId = $response->json('request_id');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));

        $this->assertSame(
            [
                [
                    'method' =>
                        'NativePasskeys.GetStatus',
                    'parameters' => [
                        'id' => $requestId,
                    ],
                ],
                [
                    'method' =>
                        'NativePasskeys.Cancel',
                    'parameters' => [
                        'id' => $requestId,
                    ],
                ],
                [
                    'method' =>
                        'NativePasskeys.ConsumeResult',
                    'parameters' => [
                        'id' => $requestId,
                    ],
                ],
            ],
            $bridge->calls,
        );

        foreach ($bridge->calls as $call) {
            $this->assertNotSame(
                'NativePasskeys.Create',
                $call['method'],
            );

            $this->assertNotSame(
                'NativePasskeys.Authenticate',
                $call['method'],
            );
        }

        $content = $response->getContent();

        $this->assertStringNotContainsString(
            'responseJson',
            $content,
        );

        $this->assertStringNotContainsString(
            'requestJson',
            $content,
        );

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get(
                'Cache-Control',
                '',
            ),
        );
    }

    private function installRecordingBridge(): RecordingNativePasskeysBridge
    {
        $bridge = new RecordingNativePasskeysBridge();

        NativePasskeysFacade::clearResolvedInstance(
            NativePasskeysService::class,
        );

        Facade::clearResolvedInstance(
            NativePasskeysService::class,
        );

        $this->app->forgetInstance(
            NativePasskeysService::class,
        );

        $this->app->instance(
            NativePasskeysService::class,
            new NativePasskeysService(
                bridge: $bridge,
            ),
        );

        return $bridge;
    }
}