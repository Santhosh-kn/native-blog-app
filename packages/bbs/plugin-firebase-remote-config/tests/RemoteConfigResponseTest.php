<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigResponse as Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/RemoteConfigTestFixture.php';

final class RemoteConfigResponseTest extends TestCase
{
    #[DataProvider('typedSources')]
    public function test_preserves_typed_values_and_all_source_names(string $type, mixed $value, string $source): void
    {
        $result = Response::values(RemoteConfigTestFixture::values($type, $value, $source), ['example' => $type]);
        self::assertTrue($result->success);
        self::assertSame($source, $result->values->example->source);
        self::assertSame($type === 'decimal' ? (float) $value : $value, $result->values->example->value);
    }

    public static function typedSources(): array
    {
        $cases = [];
        foreach (['remote', 'default', 'static'] as $source) {
            foreach (['string' => 'Welcome', 'boolean' => false, 'integer' => 0, 'decimal' => 1.25] as $type => $value) {
                $cases[$source.' '.$type] = [$type, $value, $source];
            }
        }
        $cases['whole decimal normalized'] = ['decimal', 1, 'default'];

        return $cases;
    }

    #[DataProvider('badValues')]
    public function test_rejects_malformed_values(object $raw): void
    {
        $result = Response::values($raw, ['example' => 'string']);
        self::assertFalse($result->success);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertSame([], get_object_vars($result->values));
    }

    public static function badValues(): array
    {
        $cases = [];
        foreach ([['type', 'boolean'], ['value', 1], ['value', str_repeat('x', 1025)], ['source', 'unknown'], ['source', 2]] as $index => [$key, $value]) {
            $raw = RemoteConfigTestFixture::values();
            $raw->values->example->{$key} = $value;
            $cases['invalid entry '.$index] = [$raw];
        }
        $extra = RemoteConfigTestFixture::values(); $extra->extra = true; $cases['extra top field'] = [$extra];
        $extra = RemoteConfigTestFixture::values(); $extra->values->example->extra = true; $cases['extra value field'] = [$extra];
        $missing = RemoteConfigTestFixture::values(); unset($missing->values->example->source); $cases['missing source'] = [$missing];
        $missing = RemoteConfigTestFixture::values(); $missing->values = (object) []; $cases['missing requested key'] = [$missing];
        $extra = RemoteConfigTestFixture::values(); $extra->values->other = $extra->values->example; $cases['unrequested key'] = [$extra];
        $raw = RemoteConfigTestFixture::values(); $raw->success = 1; $cases['success integer'] = [$raw];
        $raw = RemoteConfigTestFixture::values(); $raw->errorCode = Error::FETCH_FAILED; $cases['success with error'] = [$raw];
        $raw = RemoteConfigTestFixture::values(); $raw->values = []; $cases['values array'] = [$raw];
        $raw = RemoteConfigTestFixture::values(); $raw->values->example = null; $cases['entry null'] = [$raw];
        $raw = RemoteConfigTestFixture::values(); $raw->success = false; $raw->errorCode = Error::NOT_INITIALIZED; $cases['failure exposing values'] = [$raw];
        $cases['failure without code'] = [(object) ['success' => false, 'values' => (object) [], 'errorCode' => null]];
        $cases['failure unknown code'] = [(object) ['success' => false, 'values' => (object) [], 'errorCode' => 'PRIVATE_DETAIL']];

        return $cases;
    }

    public function test_remote_integer_must_be_in_safe_range(): void
    {
        $result = Response::values(RemoteConfigTestFixture::values('integer', Contract::MAX_SAFE_INTEGER + 1), ['example' => 'integer']);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, $result->errorCode);
    }

    public function test_remote_decimal_must_be_finite(): void
    {
        $result = Response::values(RemoteConfigTestFixture::values('decimal', INF), ['example' => 'decimal']);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, $result->errorCode);
    }

    public function test_large_reply_is_rejected_even_from_an_injected_bridge(): void
    {
        $raw = RemoteConfigTestFixture::values();
        $expected = [];
        $raw->values = (object) [];
        foreach (range(1, 32) as $index) {
            $key = 'key_'.$index;
            $expected[$key] = 'string';
            $raw->values->{$key} = (object) ['type' => 'string', 'value' => str_repeat('x', 1024), 'source' => 'remote'];
        }
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, Response::values($raw, $expected)->errorCode);
    }

    public function test_null_values_reply_is_controlled(): void
    {
        self::assertSame(Error::NATIVE_UNAVAILABLE, Response::values(null, ['example' => 'string'])->errorCode);
    }

    public function test_known_values_failure_is_preserved_without_internal_data(): void
    {
        $result = Response::values((object) ['success' => false, 'values' => (object) [], 'errorCode' => Error::DEFAULTS_NOT_READY], ['example' => 'string']);
        self::assertSame(Error::DEFAULTS_NOT_READY, $result->errorCode);
    }

    #[DataProvider('operationStates')]
    public function test_accepts_consistent_native_states(string $operation, string $status): void
    {
        $raw = RemoteConfigTestFixture::operation($operation, $status);
        $result = Response::operation($raw, RemoteConfigTestFixture::ID, $operation);
        self::assertSame($status, $result->status);
        self::assertSame($raw->success, $result->success);
        self::assertSame($raw->terminal, $result->terminal);
    }

    public static function operationStates(): array
    {
        $cases = [];
        foreach (Response::OPERATIONS as $operation) {
            foreach (['pending', 'running', 'succeeded', 'failed', 'timed_out', 'interrupted', 'rejected'] as $status) {
                $cases[$operation.' '.$status] = [$operation, $status];
            }
        }
        $cases['Activate unchanged'] = ['Activate', 'unchanged'];
        $cases['FetchAndActivate unchanged'] = ['FetchAndActivate', 'unchanged'];

        return $cases;
    }

    #[DataProvider('badOperationStates')]
    public function test_inconsistent_states_become_unconfirmed(object $raw): void
    {
        $result = Response::operation($raw, RemoteConfigTestFixture::ID, 'Fetch');
        self::assertSame('unconfirmed', $result->status);
        self::assertFalse($result->terminal);
        self::assertSame(RemoteConfigTestFixture::ID, $result->id);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, $result->errorCode);
    }

    public static function badOperationStates(): array
    {
        $cases = [];
        $mutations = [
            ['id', RemoteConfigTestFixture::OTHER], ['operation', 'Activate'], ['status', 'unknown'],
            ['accepted', false], ['accepted', 1], ['terminal', true], ['success', true],
            ['fetched', true], ['activated', true], ['startedAtMs', 0], ['startedAtMs', '1000'],
            ['finishedAtMs', 2000], ['errorCode', Error::FETCH_FAILED], ['throttleEndTimeMs', 5000],
        ];
        foreach ($mutations as $index => [$key, $value]) {
            $raw = RemoteConfigTestFixture::operation(); $raw->{$key} = $value; $cases['pending mutation '.$index] = [$raw];
        }
        $raw = RemoteConfigTestFixture::operation(); unset($raw->fetched); $cases['missing field'] = [$raw];
        $raw = RemoteConfigTestFixture::operation(); $raw->extra = true; $cases['extra field'] = [$raw];
        foreach ([['fetched', false], ['activated', true], ['success', false], ['errorCode', Error::FETCH_FAILED],
            ['finishedAtMs', null], ['startedAtMs', null], ['finishedAtMs', 500], ['terminal', false]] as $index => [$key, $value]) {
            $raw = RemoteConfigTestFixture::operation('Fetch', 'succeeded'); $raw->{$key} = $value;
            $cases['success mutation '.$index] = [$raw];
        }
        $cases['fetch cannot be unchanged'] = [RemoteConfigTestFixture::operation('Fetch', 'unchanged')];
        $raw = RemoteConfigTestFixture::operation('Fetch', 'failed'); $raw->errorCode = null; $cases['failed without code'] = [$raw];
        $raw = RemoteConfigTestFixture::operation('Fetch', 'failed'); $raw->errorCode = 'raw-secret'; $cases['unknown error'] = [$raw];
        $raw = RemoteConfigTestFixture::operation('Fetch', 'timed_out'); $raw->errorCode = Error::FETCH_FAILED; $cases['timeout mismatch'] = [$raw];
        $raw = RemoteConfigTestFixture::operation('Fetch', 'interrupted'); $raw->errorCode = Error::FETCH_FAILED; $cases['interrupt mismatch'] = [$raw];
        $raw = RemoteConfigTestFixture::operation('Fetch', 'rejected'); $raw->accepted = true; $cases['accepted rejected'] = [$raw];
        $raw = RemoteConfigTestFixture::operation('Fetch', 'rejected'); $raw->fetched = true; $cases['rejected fetched'] = [$raw];
        $cases['start cannot return not found'] = [RemoteConfigTestFixture::notFound()];

        return $cases;
    }

    public function test_unchanged_is_successful_and_activation_is_false(): void
    {
        $result = Response::operation(RemoteConfigTestFixture::operation('FetchAndActivate', 'unchanged'), RemoteConfigTestFixture::ID, 'FetchAndActivate');
        self::assertTrue($result->success);
        self::assertTrue($result->fetched);
        self::assertFalse($result->activated);
    }

    public function test_partial_combined_operation_records_fetch_before_activation(): void
    {
        $raw = RemoteConfigTestFixture::operation('FetchAndActivate', 'running'); $raw->fetched = true;
        self::assertSame('running', Response::operation($raw, RemoteConfigTestFixture::ID, 'FetchAndActivate')->status);
    }

    public function test_null_acknowledgement_retains_id_for_polling(): void
    {
        $result = Response::operation(null, RemoteConfigTestFixture::ID, 'Fetch');
        self::assertSame('unconfirmed', $result->status);
        self::assertFalse($result->terminal);
        self::assertSame(RemoteConfigTestFixture::ID, $result->id);
        self::assertSame(Error::NATIVE_UNAVAILABLE, $result->errorCode);
    }

    public function test_status_query_accepts_not_found(): void
    {
        self::assertSame('not_found', Response::operation(RemoteConfigTestFixture::notFound(), RemoteConfigTestFixture::ID)->status);
    }

    public function test_status_query_rejects_not_found_with_started_work(): void
    {
        $raw = RemoteConfigTestFixture::notFound(); $raw->startedAtMs = 1000;
        self::assertSame('unconfirmed', Response::operation($raw, RemoteConfigTestFixture::ID)->status);
    }

    public function test_throttling_has_a_controlled_code_and_retry_timestamp(): void
    {
        $raw = RemoteConfigTestFixture::operation('Fetch', 'failed');
        $raw->errorCode = Error::THROTTLED; $raw->throttleEndTimeMs = 5000;
        self::assertSame(5000, Response::operation($raw, RemoteConfigTestFixture::ID, 'Fetch')->throttleEndTimeMs);
    }

    #[DataProvider('badInfo')]
    public function test_rejects_malformed_info(string $key, mixed $value): void
    {
        $raw = RemoteConfigTestFixture::info(); $raw->{$key} = $value;
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, Response::info($raw)->errorCode);
    }

    public static function badInfo(): array
    {
        return [
            'success type' => ['success', 1], 'ready type' => ['ready', 1], 'defaults type' => ['defaultsReady', 1],
            'unknown fetch status' => ['lastFetchStatus', 'pending'], 'missing fetch timestamp' => ['lastSuccessfulFetchAtMs', null],
            'invalid timestamp' => ['lastSuccessfulFetchAtMs', -1], 'unsafe timestamp' => ['lastSuccessfulFetchAtMs', Contract::MAX_SAFE_INTEGER + 1],
            'interval type' => ['minimumFetchIntervalSeconds', '43200'], 'negative interval' => ['minimumFetchIntervalSeconds', -1],
            'interval above bound' => ['minimumFetchIntervalSeconds', 604801], 'timeout zero' => ['fetchTimeoutSeconds', 0],
            'timeout above bound' => ['fetchTimeoutSeconds', 121], 'busy type' => ['sdkBusy', 1],
            'bad active id' => ['activeOperationId', 'bad'], 'active id when idle' => ['activeOperationId', RemoteConfigTestFixture::ID],
            'ready contradiction' => ['ready', false], 'success with code' => ['errorCode', Error::FETCH_FAILED],
            'extra field' => ['privateDiagnostic', 'hidden'],
        ];
    }

    public function test_info_can_report_sdk_busy_after_operation_deadline(): void
    {
        $raw = RemoteConfigTestFixture::info(); $raw->sdkBusy = true;
        $result = Response::info($raw);
        self::assertTrue($result->success);
        self::assertNull($result->activeOperationId);
        self::assertTrue($result->sdkBusy);
    }

    public function test_info_failure_has_only_safe_error_fields(): void
    {
        self::assertSame(Error::NATIVE_UNAVAILABLE, Response::info(null)->errorCode);
        self::assertSame(Error::NOT_INITIALIZED, Response::info((object) ['success' => false, 'errorCode' => Error::NOT_INITIALIZED])->errorCode);
    }

    public function test_readiness_requires_a_boolean_and_known_error_contract(): void
    {
        self::assertTrue(Response::availability((object) ['platform' => 'android', 'available' => true, 'errorCode' => null])->available);
        self::assertSame(Error::NATIVE_UNAVAILABLE, Response::availability(null)->errorCode);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, Response::availability((object) ['platform' => 'android', 'available' => 1, 'errorCode' => null])->errorCode);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, Response::availability((object) ['platform' => 'android', 'available' => false, 'errorCode' => null])->errorCode);
    }
}
