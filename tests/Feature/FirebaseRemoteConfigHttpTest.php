<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\FirebaseRemoteConfigDemo as Demo;
use App\Support\FirebaseRemoteConfigDemoState as State;
use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FirebaseRemoteConfigTestBridge as Bridge;
use Tests\TestCase;

final class FirebaseRemoteConfigHttpTest extends TestCase
{
    private const ID = '123e4567-e89b-42d3-a456-426614174000';
    private Bridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('session.driver', 'array');
        $this->app['config']->set('cache.default', 'array');
        $this->bridge = new Bridge;
        $this->app->instance(FirebaseRemoteConfig::class, new FirebaseRemoteConfig($this->bridge));
    }

    public static function protectedRoutes(): array
    {
        return ['page' => ['GET', 'index', []], 'snapshot' => ['GET', 'snapshot', []],
            'start' => ['POST', 'start', []], 'status' => ['GET', 'status', ['id' => self::ID]]];
    }

    #[DataProvider('protectedRoutes')]
    public function test_authentication_precedes_native_calls(string $method, string $name, array $parameters): void
    {
        $this->call($method, route('firebase-remote-config.'.$name, $parameters))->assertRedirect(route('login'));
        self::assertSame([], $this->bridge->calls);
    }

    #[DataProvider('protectedRoutes')]
    public function test_unlock_precedes_native_calls(string $method, string $name, array $parameters): void
    {
        $this->signIn();
        $this->call($method, route('firebase-remote-config.'.$name, $parameters))->assertRedirect(route('unlock'));
        self::assertSame([], $this->bridge->calls);
    }

    public function test_page_has_explicit_controls_and_get_never_mutates_settings(): void
    {
        $this->unlock();
        $response = $this->get(route('firebase-remote-config.index'))->assertOk()->assertViewIs('firebase-remote-config')
            ->assertSeeText('Fetch')->assertSeeText('Activate')->assertSeeText('Fetch and activate')
            ->assertSeeText('Register local defaults')->assertSeeText('Apply fetch settings')->assertSeeText('Refresh values and status');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame(['FirebaseRemoteConfig.GetInfo', 'FirebaseRemoteConfig.GetValues',
            'FirebaseRemoteConfig.GetValues', 'FirebaseRemoteConfig.GetValues'], array_column($this->bridge->calls, 0));
    }

    public function test_unavailable_native_runtime_has_usable_local_defaults(): void
    {
        $this->unlock(); $this->bridge->reply = static fn (): ?object => null;
        $this->getJson(route('firebase-remote-config.snapshot'))->assertOk()
            ->assertJsonPath('values.welcome_message.value', Demo::DEFAULTS['welcome_message']['value'])
            ->assertJsonPath('values.show_media_optimizer.value', true)
            ->assertJsonPath('values.media_image_quality.value', 80)
            ->assertJsonPath('values.media_image_quality.source', 'local')
            ->assertJsonPath('info.errorCode', Error::NATIVE_UNAVAILABLE);
        self::assertSame([['FirebaseRemoteConfig.GetInfo', []]], $this->bridge->calls);
    }

    public function test_initialization_does_not_wait_for_network_or_read_unready_values(): void
    {
        $this->unlock(); $this->bridge->info['ready'] = false; $this->bridge->info['defaultsReady'] = false;
        $this->getJson(route('firebase-remote-config.snapshot'))->assertOk()->assertJsonPath('values.media_image_quality.value', 80)
            ->assertJsonPath('info.ready', false);
        self::assertSame([['FirebaseRemoteConfig.GetInfo', []]], $this->bridge->calls);
    }

    public static function sources(): array { return ['remote' => ['remote'], 'default' => ['default'], 'static' => ['static']]; }

    #[DataProvider('sources')]
    public function test_sources_refer_to_the_value_actually_used(string $source): void
    {
        $this->unlock();
        $this->bridge->values['welcome_message'] = ['type' => 'string', 'value' => 'Typed welcome', 'source' => $source];
        $this->getJson(route('firebase-remote-config.snapshot'))->assertOk()
            ->assertJsonPath('values.welcome_message.value', 'Typed welcome')->assertJsonPath('values.welcome_message.source', $source);
    }

    public static function invalidQuality(): array
    {
        return ['low' => [0], 'high' => [101], 'negative' => [-5], 'numeric string' => ['75'], 'decimal' => [75.0], 'boolean' => [true]];
    }

    #[DataProvider('invalidQuality')]
    public function test_invalid_quality_uses_local_fallback_without_losing_other_valid_values(mixed $quality): void
    {
        $this->unlock();
        $this->bridge->values['media_image_quality'] = ['type' => 'integer', 'value' => $quality, 'source' => 'remote'];
        $this->bridge->values['welcome_message']['value'] = 'Another valid setting';
        $this->getJson(route('firebase-remote-config.snapshot'))->assertOk()->assertJsonPath('values.media_image_quality.value', 80)
            ->assertJsonPath('values.media_image_quality.source', 'local')->assertJsonPath('values.welcome_message.value', 'Another valid setting');
    }

    public function test_false_and_integer_boundaries_are_preserved(): void
    {
        $this->unlock(); $this->bridge->values['show_media_optimizer']['value'] = false;
        foreach ([1, 100] as $quality) {
            $this->bridge->values['media_image_quality']['value'] = $quality;
            $this->getJson(route('firebase-remote-config.snapshot'))->assertOk()->assertJsonPath('values.show_media_optimizer.value', false)
                ->assertJsonPath('values.media_image_quality.value', $quality);
        }
    }

    public function test_default_registration_is_explicit_typed_and_owned_before_native_execution(): void
    {
        $this->unlock();
        $this->bridge->reply = function (string $method, array $parameters): object {
            self::assertSame('SetDefaults', $this->app->make('session.store')->get(State::KEY)['operations'][self::ID]['operation']);
            self::assertSame(Demo::DEFAULTS, $parameters['defaults']);
            return Bridge::record(self::ID, 'SetDefaults');
        };
        $this->start('SetDefaults')->assertStatus(202)->assertJsonPath('id', self::ID)->assertJsonPath('status', 'pending');
    }

    public static function invalidRequests(): array
    {
        return [
            'unknown operation' => [['id' => self::ID, 'operation' => 'DeleteEverything']],
            'bad id' => [['id' => '../private', 'operation' => 'Fetch']],
            'uppercase id' => [['id' => strtoupper(self::ID), 'operation' => 'Fetch']],
            'unknown field' => [['id' => self::ID, 'operation' => 'Fetch', 'extra' => true]],
            'caller defaults' => [['id' => self::ID, 'operation' => 'SetDefaults', 'defaults' => ['secret' => 'value']]],
            'negative interval' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['minimum_fetch_interval_seconds' => -1]]],
            'long interval' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['minimum_fetch_interval_seconds' => 604801]]],
            'null interval' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['minimum_fetch_interval_seconds' => null]]],
            'string interval' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['minimum_fetch_interval_seconds' => '0']]],
            'short timeout' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['fetch_timeout_seconds' => 0]]],
            'long timeout' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['fetch_timeout_seconds' => 121]]],
            'null timeout' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['fetch_timeout_seconds' => null]]],
            'extra setting' => [['id' => self::ID, 'operation' => 'Configure', 'settings' => ['secret' => 'value']]],
            'missing settings object' => [['id' => self::ID, 'operation' => 'Configure']],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_do_not_reach_native_code(array $input): void
    {
        $this->unlock(); $this->postJson(route('firebase-remote-config.start'), $input)->assertStatus(422);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_oversized_request_is_rejected_before_native_code(): void
    {
        $this->unlock();
        $this->postJson(route('firebase-remote-config.start'), ['id' => self::ID, 'operation' => 'Fetch', 'extra' => str_repeat('a', 9000)])
            ->assertStatus(413)->assertJsonPath('errorCode', Error::REQUEST_TOO_LARGE);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_development_interval_is_explicit_and_does_not_fetch(): void
    {
        $this->unlock(); $this->start('Configure', ['minimum_fetch_interval_seconds' => 0, 'fetch_timeout_seconds' => 10])->assertStatus(202);
        self::assertSame([['FirebaseRemoteConfig.Configure', ['id' => self::ID, 'minimum_fetch_interval_seconds' => 0, 'fetch_timeout_seconds' => 10]]], $this->bridge->calls);
        $this->bridge->finish(self::ID);
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertOk()->assertJsonPath('success', true);
        $this->getJson(route('firebase-remote-config.snapshot'))->assertJsonPath('info.minimumFetchIntervalSeconds', 0);
    }

    public function test_empty_configure_settings_use_production_defaults(): void
    {
        $this->unlock(); $this->start('Configure', [])->assertStatus(202);
        self::assertSame(43200, $this->bridge->calls[0][1]['minimum_fetch_interval_seconds']);
        self::assertSame(60, $this->bridge->calls[0][1]['fetch_timeout_seconds']);
    }

    public function test_fetch_completion_does_not_claim_activation(): void
    {
        $this->unlock(); $this->start('Fetch')->assertStatus(202); $this->bridge->finish(self::ID);
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertOk()->assertJsonPath('fetched', true)
            ->assertJsonPath('activated', null)->assertJsonPath('terminal', true)->assertSee('Use Activate');
        self::assertSame(['FirebaseRemoteConfig.Fetch', 'FirebaseRemoteConfig.GetStatus'], array_column($this->bridge->calls, 0));
    }

    public function test_activation_without_changes_is_successful_unchanged(): void
    {
        $this->unlock(); $this->start('Activate'); $this->bridge->finish(self::ID, 'unchanged');
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertOk()
            ->assertJsonPath('status', 'unchanged')->assertJsonPath('success', true)->assertJsonPath('activated', false)->assertSee('No new values');
    }

    public static function failures(): array
    {
        return ['offline' => ['failed', Error::FETCH_FAILED], 'deadline' => ['timed_out', Error::OPERATION_TIMEOUT],
            'throttled' => ['failed', Error::THROTTLED], 'restart' => ['interrupted', Error::PROCESS_INTERRUPTED]];
    }

    #[DataProvider('failures')]
    public function test_controlled_failures_are_terminal_and_keep_current_values(string $status, string $error): void
    {
        $this->unlock(); $this->start('Fetch'); $this->bridge->finish(self::ID, $status, $error);
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertOk()->assertJsonPath('terminal', true)
            ->assertJsonPath('success', false)->assertJsonPath('errorCode', $error);
        $this->getJson(route('firebase-remote-config.snapshot'))->assertJsonPath('values.media_image_quality.value', 80);
    }

    public function test_lost_acknowledgement_retains_ownership_and_recovers_the_same_id(): void
    {
        $this->unlock();
        $this->bridge->reply = function (string $method, array $parameters): ?object {
            $this->bridge->respond($method, $parameters); return null;
        };
        $this->start('Fetch')->assertStatus(503)->assertJsonPath('status', 'unconfirmed')->assertJsonPath('terminal', false)
            ->assertSessionHas(State::KEY.'.operations.'.self::ID.'.terminal', false);
        $this->bridge->reply = null; $this->bridge->finish(self::ID);
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertOk()->assertJsonPath('success', true)
            ->assertSessionHas(State::KEY.'.operations.'.self::ID.'.terminal', true);
        self::assertCount(1, $this->bridge->started);
    }

    public function test_correlated_persistence_failure_is_not_a_terminal_rejection(): void
    {
        $this->unlock(); $this->bridge->reply = static fn (string $method, array $input): object =>
            (object) ['id' => $input['id'], 'operation' => 'Fetch', 'success' => false, 'errorCode' => Error::PERSIST_FAILED];
        $this->start('Fetch')->assertStatus(503)->assertJsonPath('status', 'unconfirmed')->assertJsonPath('terminal', false)
            ->assertSessionHas(State::KEY.'.operations.'.self::ID.'.terminal', false);
    }

    public function test_repeated_request_replays_but_changed_request_conflicts_before_bridge(): void
    {
        $this->unlock(); $this->start('Fetch')->assertStatus(202); $this->start('Fetch')->assertStatus(202);
        self::assertCount(1, $this->bridge->started);
        $this->start('Activate')->assertStatus(409)->assertJsonPath('errorCode', Error::REQUEST_ID_CONFLICT);
        self::assertCount(2, $this->bridge->calls);
    }

    public function test_foreign_and_unknown_ids_never_reach_native_status(): void
    {
        $this->unlock(); $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertNotFound();
        $this->start('Fetch'); $this->signIn(43); $this->bridge->calls = [];
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertNotFound();
        self::assertSame([], $this->bridge->calls);
    }

    public function test_status_operation_mismatch_remains_unconfirmed(): void
    {
        $this->unlock(); $this->start('Fetch');
        $this->bridge->records[self::ID] = Bridge::record(self::ID, 'Activate', 'succeeded');
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertStatus(503)
            ->assertJsonPath('status', 'unconfirmed')->assertJsonPath('errorCode', Error::INVALID_NATIVE_RESPONSE);
    }

    public function test_evicted_native_result_is_not_reported_as_success(): void
    {
        $this->unlock(); $this->start('Fetch'); unset($this->bridge->records[self::ID]);
        $this->getJson(route('firebase-remote-config.status', ['id' => self::ID]))->assertOk()->assertJsonPath('status', 'not_found')
            ->assertJsonPath('success', false)->assertJsonPath('errorCode', Error::RESULT_NOT_FOUND);
    }

    public function test_full_active_history_is_preserved_and_prevents_new_admission(): void
    {
        $this->unlock(); $operations = [];
        for ($i = 0; $i < State::LIMIT; $i++) {
            $operations[(string) Str::uuid()] = ['operation' => 'Fetch', 'fingerprint' => str_repeat('a', 64), 'terminal' => false];
        }
        $this->withSession([State::KEY => ['owner' => '42', 'operations' => $operations]]);
        $this->start('Fetch')->assertStatus(429)->assertJsonPath('errorCode', Error::LIMIT_EXCEEDED);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_snapshot_exposes_only_safe_owned_operation_metadata(): void
    {
        $this->unlock(); $this->start('Fetch');
        $response = $this->getJson(route('firebase-remote-config.snapshot'))->assertOk()->assertJsonPath('operations.0.id', self::ID);
        self::assertSame(['id', 'operation', 'terminal'], array_keys($response->json('operations.0')));
    }

    public function test_known_busy_rejection_is_terminal_without_changing_effective_values(): void
    {
        $this->unlock();
        $this->bridge->reply = static fn (string $method, array $input): object => Bridge::record($input['id'], 'Fetch', 'rejected', Error::BUSY);
        $this->start('Fetch')->assertStatus(422)->assertJsonPath('status', 'rejected')->assertJsonPath('terminal', true)
            ->assertSessionHas(State::KEY.'.operations.'.self::ID.'.terminal', true);
        $this->bridge->reply = null;
        $this->getJson(route('firebase-remote-config.snapshot'))->assertJsonPath('values.media_image_quality.value', 80);
    }

    public function test_terminal_history_can_be_pruned_without_losing_active_ownership(): void
    {
        $this->unlock(); $operations = [];
        $oldest = (string) Str::uuid(); $active = (string) Str::uuid();
        $operations[$oldest] = ['operation' => 'Fetch', 'fingerprint' => str_repeat('a', 64), 'terminal' => true];
        $operations[$active] = ['operation' => 'Fetch', 'fingerprint' => str_repeat('b', 64), 'terminal' => false];
        for ($i = 2; $i < State::LIMIT; $i++) {
            $operations[(string) Str::uuid()] = ['operation' => 'Fetch', 'fingerprint' => str_repeat('a', 64), 'terminal' => true];
        }
        $this->withSession([State::KEY => ['owner' => '42', 'operations' => $operations]]);
        $this->start('Fetch')->assertStatus(202)->assertSessionMissing(State::KEY.'.operations.'.$oldest)
            ->assertSessionHas(State::KEY.'.operations.'.$active)->assertSessionHas(State::KEY.'.operations.'.self::ID);
        self::assertCount(State::LIMIT, session(State::KEY)['operations']);
    }

    public function test_explicit_retry_of_a_rejected_id_restores_nonterminal_ownership(): void
    {
        $this->unlock();
        $this->bridge->reply = static fn (string $method, array $input): object => Bridge::record($input['id'], 'Fetch', 'rejected', Error::BUSY);
        $this->start('Fetch')->assertStatus(422)->assertSessionHas(State::KEY.'.operations.'.self::ID.'.terminal', true);
        $this->bridge->reply = null;
        $this->start('Fetch')->assertStatus(202)->assertSessionHas(State::KEY.'.operations.'.self::ID.'.terminal', false);
    }

    public function test_native_exception_details_never_reach_the_demo(): void
    {
        $this->unlock(); $this->bridge->reply = static function (): never { throw new \RuntimeException('PRIVATE_PATH PRIVATE_TOKEN'); };
        $this->getJson(route('firebase-remote-config.snapshot'))->assertOk()->assertJsonPath('values.media_image_quality.source', 'local')
            ->assertDontSee('PRIVATE_PATH')->assertDontSee('PRIVATE_TOKEN');
        $this->start('Fetch')->assertStatus(503)->assertJsonPath('status', 'unconfirmed')->assertDontSee('PRIVATE_PATH')->assertDontSee('PRIVATE_TOKEN');
    }

    public function test_remote_markup_is_escaped_in_the_demo_and_home(): void
    {
        $this->unlock(); $this->bridge->values['welcome_message']['value'] = '<script>window.pwned=1</script>';
        $this->get(route('firebase-remote-config.index'))->assertOk()->assertDontSee('<script>window.pwned=1</script>', false);
        $snapshot = $this->app->make(Demo::class)->snapshot();
        $this->view('home', ['posts' => collect(), 'remoteConfig' => $snapshot])->assertDontSee('<script>window.pwned=1</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    private function start(string $operation, ?array $settings = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('firebase-remote-config.start'), ['id' => self::ID, 'operation' => $operation,
            ...($operation === 'Configure' ? ['settings' => $settings] : [])]);
    }

    private function signIn(int $id = 42): void
    {
        $user = new User; $user->forceFill(['id' => $id, 'name' => 'Remote Config Test', 'email' => 'remote@example.invalid']); $this->actingAs($user);
    }
    private function unlock(): void { $this->signIn(); $this->withSession(['device_unlocked' => true]); }
}
