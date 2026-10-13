<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Contracts\NativeBridge;
use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/RemoteConfigTestFixture.php';

final class RecordingFirebaseRemoteConfigBridge implements NativeBridge
{
    public array $calls = [];
    public ?Closure $reply = null;

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = [$method, $parameters];
        if ($this->reply !== null) {
            return ($this->reply)($method, $parameters);
        }
        $operation = substr($method, strlen('FirebaseRemoteConfig.'));
        if ($operation === 'IsAvailable') {
            return (object) ['platform' => 'android', 'available' => true, 'errorCode' => null];
        }
        if ($operation === 'GetInfo') {
            return RemoteConfigTestFixture::info();
        }
        $result = RemoteConfigTestFixture::operation($operation === 'GetStatus' ? 'Fetch' : $operation);
        $result->id = $parameters['id'];

        return $result;
    }
}

final class FirebaseRemoteConfigTest extends TestCase
{
    private RecordingFirebaseRemoteConfigBridge $bridge;
    private FirebaseRemoteConfig $service;

    protected function setUp(): void
    {
        $this->bridge = new RecordingFirebaseRemoteConfigBridge;
        $this->service = new FirebaseRemoteConfig($this->bridge);
    }

    public function test_readiness_and_info_use_only_read_methods(): void
    {
        self::assertTrue($this->service->isAvailable()->available);
        self::assertTrue($this->service->getInfo()->success);
        self::assertSame([['FirebaseRemoteConfig.IsAvailable', []], ['FirebaseRemoteConfig.GetInfo', []]], $this->bridge->calls);
    }

    public function test_configure_supplies_production_defaults(): void
    {
        self::assertSame('pending', $this->service->configure([], RemoteConfigTestFixture::ID)->status);
        self::assertSame(['FirebaseRemoteConfig.Configure', ['id' => RemoteConfigTestFixture::ID,
            'minimum_fetch_interval_seconds' => 43200, 'fetch_timeout_seconds' => 60]], $this->bridge->calls[0]);
    }

    public function test_zero_interval_requires_an_explicit_choice(): void
    {
        $this->service->configure(['minimum_fetch_interval_seconds' => 0], RemoteConfigTestFixture::ID);
        self::assertSame(0, $this->bridge->calls[0][1]['minimum_fetch_interval_seconds']);
        self::assertSame(60, $this->bridge->calls[0][1]['fetch_timeout_seconds']);
    }

    public function test_configure_rejects_null_setting_instead_of_substituting_a_default(): void
    {
        self::assertSame(Error::INVALID_SETTINGS, $this->service->configure(['fetch_timeout_seconds' => null])->errorCode);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_configure_rejects_unknown_options_without_native_calls(): void
    {
        self::assertSame(Error::INVALID_OPTIONS, $this->service->configure(['force' => true])->errorCode);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_default_registration_preserves_explicit_types(): void
    {
        $defaults = ['flag' => ['type' => 'boolean', 'value' => false], 'scale' => ['type' => 'decimal', 'value' => 1.0]];
        self::assertSame('SetDefaults', $this->service->setDefaults($defaults, RemoteConfigTestFixture::ID)->operation);
        self::assertSame(['FirebaseRemoteConfig.SetDefaults', ['id' => RemoteConfigTestFixture::ID, 'defaults' => $defaults]], $this->bridge->calls[0]);
    }

    public function test_bad_default_registration_is_rejected_before_native_calls(): void
    {
        self::assertSame(Error::INVALID_VALUE, $this->service->setDefaults(['flag' => ['type' => 'boolean', 'value' => 'true']])->errorCode);
        self::assertSame([], $this->bridge->calls);
    }

    #[DataProvider('startMethods')]
    public function test_start_methods_preserve_request_ids_and_operations(string $method, string $operation): void
    {
        $result = $this->service->{$method}(RemoteConfigTestFixture::ID);
        self::assertSame(RemoteConfigTestFixture::ID, $result->id);
        self::assertSame($operation, $result->operation);
        self::assertSame('pending', $result->status);
        self::assertSame(['FirebaseRemoteConfig.'.$operation, ['id' => RemoteConfigTestFixture::ID]], $this->bridge->calls[0]);
    }

    public static function startMethods(): array
    {
        return [['fetch', 'Fetch'], ['activate', 'Activate'], ['fetchAndActivate', 'FetchAndActivate']];
    }

    public function test_omitted_id_is_generated_as_a_valid_uuid(): void
    {
        $result = $this->service->fetch();
        self::assertTrue(Contract::isRequestId($result->id));
        self::assertSame($result->id, $this->bridge->calls[0][1]['id']);
    }

    public function test_invalid_id_is_rejected_before_native_calls(): void
    {
        $result = $this->service->fetch('invalid');
        self::assertSame(Error::INVALID_REQUEST_ID, $result->errorCode);
        self::assertSame('rejected', $result->status);
        self::assertNull($result->id);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_repeated_explicit_id_is_forwarded_for_native_replay_handling(): void
    {
        $this->service->fetch(RemoteConfigTestFixture::ID);
        $this->service->fetch(RemoteConfigTestFixture::ID);
        self::assertSame($this->bridge->calls[0], $this->bridge->calls[1]);
    }

    public function test_invalid_status_id_never_reaches_native(): void
    {
        self::assertSame(Error::INVALID_REQUEST_ID, $this->service->getStatus('invalid')->errorCode);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_status_uses_the_supplied_id(): void
    {
        self::assertSame('pending', $this->service->getStatus(RemoteConfigTestFixture::ID)->status);
        self::assertSame(['FirebaseRemoteConfig.GetStatus', ['id' => RemoteConfigTestFixture::ID]], $this->bridge->calls[0]);
    }

    #[DataProvider('typedMethods')]
    public function test_typed_getters_return_values_and_sources(string $method, string $type, mixed $value): void
    {
        $this->bridge->reply = static fn (): object => RemoteConfigTestFixture::values($type, $value, 'default');
        $result = $this->service->{$method}('example');
        self::assertTrue($result->success);
        self::assertSame($type === 'decimal' ? (float) $value : $value, $result->value);
        self::assertSame('default', $result->source);
        self::assertSame('example', $result->key);
        self::assertSame(['FirebaseRemoteConfig.GetValues', ['values' => ['example' => $type]]], $this->bridge->calls[0]);
    }

    public static function typedMethods(): array
    {
        return [['getString', 'string', 'Welcome'], ['getBoolean', 'boolean', false],
            ['getInteger', 'integer', 80], ['getDecimal', 'decimal', 1]];
    }

    public function test_invalid_key_and_type_are_rejected_before_reads(): void
    {
        self::assertSame(Error::INVALID_KEY, $this->service->getString('invalid.key')->errorCode);
        self::assertSame(Error::INVALID_TYPE, $this->service->getValue('example', 'json')->errorCode);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_malformed_values_are_not_replaced_with_fabricated_native_values(): void
    {
        $this->bridge->reply = static fn (): object => RemoteConfigTestFixture::values('boolean', 'true', 'remote');
        $result = $this->service->getBoolean('example');
        self::assertFalse($result->success);
        self::assertNull($result->value);
        self::assertNull($result->source);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, $result->errorCode);
    }

    public function test_lost_acknowledgement_can_be_reconciled_by_status(): void
    {
        $this->bridge->reply = static function (string $method): ?object {
            return $method === 'FirebaseRemoteConfig.Fetch' ? null : RemoteConfigTestFixture::operation('Fetch', 'succeeded');
        };
        $start = $this->service->fetch(RemoteConfigTestFixture::ID);
        self::assertSame('unconfirmed', $start->status);
        self::assertFalse($start->terminal);
        $finished = $this->service->getStatus($start->id);
        self::assertSame('succeeded', $finished->status);
        self::assertTrue($finished->fetched);
        self::assertNull($finished->activated);
    }

    public function test_mismatched_native_id_is_not_treated_as_the_requested_job(): void
    {
        $this->bridge->reply = static function (): object {
            $result = RemoteConfigTestFixture::operation(); $result->id = RemoteConfigTestFixture::OTHER;

            return $result;
        };
        $result = $this->service->fetch(RemoteConfigTestFixture::ID);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertSame(RemoteConfigTestFixture::ID, $result->id);
    }

    public function test_bridge_exceptions_produce_controlled_results(): void
    {
        $this->bridge->reply = static function (): never { throw new RuntimeException('private native details'); };
        self::assertSame(Error::NATIVE_UNAVAILABLE, $this->service->isAvailable()->errorCode);
        self::assertSame(Error::NATIVE_UNAVAILABLE, $this->service->getInfo()->errorCode);
        self::assertSame(Error::NATIVE_UNAVAILABLE, $this->service->getString('example')->errorCode);
        self::assertSame('unconfirmed', $this->service->fetch(RemoteConfigTestFixture::ID)->status);
        self::assertSame('unconfirmed', $this->service->getStatus(RemoteConfigTestFixture::ID)->status);
    }
}
