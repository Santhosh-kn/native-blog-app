<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\FirebaseRemoteConfigDemo as Demo;
use App\Support\NativeMediaOptimizerDemoState as MediaState;
use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig;
use Bbs\NativeMediaOptimizer\Contracts\NativeBridge;
use Bbs\NativeMediaOptimizer\NativeMediaOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Facades\Network;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FirebaseRemoteConfigTestBridge;
use Tests\TestCase;

final class RemoteConfigMediaJobBridge implements NativeBridge
{
    public array $calls = [];
    public array $records = [];

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = [$method, $parameters];
        $id = $parameters['id'];
        if ($method === 'NativeMediaOptimizer.GetStatus') { return $this->records[$id]; }
        return $this->records[$id] = (object) ['id' => $id, 'operation' => 'OptimizeImage', 'accepted' => true,
            'status' => 'pending', 'sourceDocumentId' => $parameters['source_document_id'], 'progress' => 0,
            'phase' => 'queued', 'input' => null, 'output' => null, 'outputAvailable' => false, 'errorCode' => null];
    }
}

final class FirebaseRemoteConfigMediaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = '123e4567-e89b-42d3-a456-426614174001';
    private FirebaseRemoteConfigTestBridge $config;
    private RemoteConfigMediaJobBridge $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('session.driver', 'array');
        $this->app['config']->set('cache.default', 'array');
        $this->config = new FirebaseRemoteConfigTestBridge;
        $this->media = new RemoteConfigMediaJobBridge;
        $this->app->instance(FirebaseRemoteConfig::class, new FirebaseRemoteConfig($this->config));
        $this->app->instance(NativeMediaOptimizer::class, new NativeMediaOptimizer($this->media,
            $this->app->storagePath('app/native-media-optimizer')));
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['device_unlocked' => true]);
        $this->withSession([MediaState::KEY => ['owner' => (string) $user->id, 'picks' => [], 'jobs' => [],
            'selection' => ['id' => self::SOURCE, 'mime_type' => 'image/jpeg', 'size' => 100]]]);
    }

    public function test_home_uses_welcome_and_flag_but_keeps_media_route_protected_independently(): void
    {
        $this->config->values['welcome_message'] = ['type' => 'string', 'value' => 'A newly activated welcome', 'source' => 'remote'];
        $this->config->values['show_media_optimizer'] = ['type' => 'boolean', 'value' => false, 'source' => 'remote'];
        Network::shouldReceive('status')->once()->andReturn((object) ['connected' => true, 'type' => 'wifi']);
        $this->get(route('home'))->assertOk()->assertSeeText('A newly activated welcome')->assertSeeText('Remote Config')
            ->assertSee('id="rc-media-link"', false)->assertSee('style="display: none;"', false);
        // Hiding navigation does not replace or weaken auth/device.unlocked middleware.
        $this->get(route('native-media-optimizer.index'))->assertOk();
        self::assertSame([], $this->media->calls);
        $this->assertNoFetchOrActivation();
    }

    public function test_home_and_media_page_are_usable_without_native_config(): void
    {
        $this->config->reply = static fn (): ?object => null;
        Network::shouldReceive('status')->once()->andReturn((object) ['connected' => false, 'type' => 'none']);
        $this->get(route('home'))->assertOk()->assertSeeText(Demo::DEFAULTS['welcome_message']['value']);
        $this->get(route('native-media-optimizer.index'))->assertOk()->assertSee('id="image-quality"', false)
            ->assertSee('value="80" disabled', false)->assertSeeText('Use the current app default');
    }

    public static function lossyFormats(): array { return ['JPEG' => ['jpeg'], 'WebP' => ['webp']]; }

    #[DataProvider('lossyFormats')]
    public function test_new_lossy_job_samples_validated_quality(string $format): void
    {
        $this->config->values['media_image_quality'] = ['type' => 'integer', 'value' => 63, 'source' => 'remote'];
        $this->start(['format' => $format])->assertStatus(202);
        self::assertSame(63, $this->media->calls[0][1]['quality']);
        $this->assertNoFetchOrActivation();
    }

    public function test_default_jpeg_format_uses_the_remote_quality(): void
    {
        $this->config->values['media_image_quality']['value'] = 91;
        $this->start([])->assertStatus(202);
        self::assertSame(91, $this->media->calls[0][1]['quality']);
    }

    public function test_png_never_receives_a_quality_default(): void
    {
        $this->config->values['media_image_quality']['value'] = 91;
        $this->start(['format' => 'png'])->assertStatus(202);
        self::assertArrayNotHasKey('quality', $this->media->calls[0][1]);
        self::assertSame([], $this->config->calls);
    }

    public function test_manual_quality_remains_an_explicit_job_option(): void
    {
        $this->config->values['media_image_quality']['value'] = 63;
        $this->start(['format' => 'jpeg', 'quality' => 92])->assertStatus(202);
        self::assertSame(92, $this->media->calls[0][1]['quality']);
        self::assertSame([], $this->config->calls);
    }

    public function test_invalid_manual_quality_is_not_replaced_by_remote_config(): void
    {
        $this->start(['format' => 'jpeg', 'quality' => 101])->assertStatus(422);
        self::assertSame([], $this->media->calls); self::assertSame([], $this->config->calls);
    }

    public function test_later_config_change_affects_a_new_job_and_never_rewrites_the_old_one(): void
    {
        $this->config->values['media_image_quality']['value'] = 63;
        $first = $this->start([])->assertStatus(202)->assertJsonPath('imageQuality', 63)->json('id');
        $this->config->values['media_image_quality']['value'] = 91; $this->config->calls = [];
        $this->getJson(route('native-media-optimizer.status', ['id' => $first]))->assertOk()->assertJsonPath('imageQuality', 63);
        self::assertSame([], $this->config->calls);
        self::assertSame(63, $this->media->calls[0][1]['quality']);
        $second = $this->start([])->assertStatus(202)->assertJsonPath('imageQuality', 91)->json('id');
        self::assertNotSame($first, $second); self::assertSame(91, $this->media->calls[2][1]['quality']);
        self::assertSame(63, $this->media->calls[0][1]['quality']);
    }

    public function test_invalid_remote_quality_falls_back_for_a_new_job(): void
    {
        $this->config->values['media_image_quality'] = ['type' => 'integer', 'value' => 999, 'source' => 'remote'];
        $this->start([])->assertStatus(202);
        self::assertSame(80, $this->media->calls[0][1]['quality']);
    }

    public function test_media_page_displays_quality_without_fetching_and_offers_an_override(): void
    {
        $this->config->values['media_image_quality'] = ['type' => 'integer', 'value' => 63, 'source' => 'remote'];
        $this->get(route('native-media-optimizer.index'))->assertOk()->assertSee('value="63" disabled', false)
            ->assertSeeText('63 (remote)')->assertSee('id="image-use-config"', false);
        $this->assertNoFetchOrActivation();
    }

    private function start(array $options): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('native-media-optimizer.start'), ['operation' => 'OptimizeImage',
            'source_document_id' => self::SOURCE, 'options' => $options]);
    }

    private function assertNoFetchOrActivation(): void
    {
        foreach ($this->config->calls as [$method]) {
            self::assertNotContains($method, ['FirebaseRemoteConfig.Fetch', 'FirebaseRemoteConfig.Activate', 'FirebaseRemoteConfig.FetchAndActivate']);
        }
    }
}
