<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Contracts\NativeBridge;
use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigResponse as Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/RemoteConfigTestFixture.php';

final class RemoteConfigControlFailureTest extends TestCase
{
    #[DataProvider('sharedFailures')]
    public function test_native_control_failures_preserve_uncertainty_and_correlation(object $raw): void
    {
        $result = Response::operation($raw, RemoteConfigTestFixture::ID, $raw->operation);
        self::assertSame('unconfirmed', $result->status);
        self::assertSame(RemoteConfigTestFixture::ID, $result->id);
        self::assertSame($raw->operation, $result->operation);
        self::assertSame($raw->errorCode, $result->errorCode);
        self::assertFalse($result->accepted);
        self::assertFalse($result->terminal);
        self::assertFalse($result->success);
        self::assertNull($result->fetched);
        self::assertNull($result->activated);
        self::assertNull($result->startedAtMs);
        self::assertNull($result->finishedAtMs);
        self::assertNull($result->throttleEndTimeMs);
    }

    public static function sharedFailures(): array
    {
        $vectors = json_decode(file_get_contents(__DIR__.'/fixtures/remote-config-control-failures.json'), true, 16, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($vectors as $vector) {
            $cases[$vector['name']] = [(object) $vector['response']];
        }

        return $cases;
    }

    #[DataProvider('invalidFailures')]
    public function test_malformed_control_failure_is_not_trusted(object $raw): void
    {
        $result = Response::operation($raw, RemoteConfigTestFixture::ID, 'Fetch');
        self::assertSame('unconfirmed', $result->status);
        self::assertSame(RemoteConfigTestFixture::ID, $result->id);
        self::assertSame('Fetch', $result->operation);
        self::assertSame(Error::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertFalse($result->terminal);
    }

    public static function invalidFailures(): array
    {
        $base = ['id' => RemoteConfigTestFixture::ID, 'operation' => 'Fetch', 'success' => false, 'errorCode' => Error::PERSIST_FAILED];
        $cases = [];
        foreach ([['id', RemoteConfigTestFixture::OTHER], ['id', null], ['operation', 'Activate'], ['operation', null],
            ['success', true], ['success', 0], ['errorCode', Error::FETCH_FAILED], ['errorCode', null], ['errorCode', 'private details']] as $index => [$key, $value]) {
            $cases['invalid field '.$index] = [(object) [...$base, $key => $value]];
        }
        $extra = (object) $base; $extra->message = 'private path'; $cases['extra details'] = [$extra];
        $missing = (object) $base; unset($missing->id); $cases['missing id'] = [$missing];
        $missing = (object) $base; unset($missing->operation); $cases['missing operation'] = [$missing];

        return $cases;
    }

    public function test_poll_can_recover_a_persistence_failure_without_starting_a_second_operation(): void
    {
        $bridge = new class implements NativeBridge {
            public array $calls = [];
            public function call(string $method, array $parameters = []): ?object
            {
                $this->calls[] = [$method, $parameters];

                return $method === 'FirebaseRemoteConfig.Fetch'
                    ? (object) ['id' => $parameters['id'], 'operation' => 'Fetch', 'success' => false, 'errorCode' => Error::PERSIST_FAILED]
                    : RemoteConfigTestFixture::operation('Fetch', 'succeeded');
            }
        };
        $service = new FirebaseRemoteConfig($bridge);
        $start = $service->fetch(RemoteConfigTestFixture::ID);
        self::assertSame('unconfirmed', $start->status);
        self::assertSame(Error::PERSIST_FAILED, $start->errorCode);
        self::assertFalse($start->terminal);
        self::assertSame('succeeded', $service->getStatus($start->id)->status);
        self::assertSame(['FirebaseRemoteConfig.Fetch', 'FirebaseRemoteConfig.GetStatus'], array_column($bridge->calls, 0));
        self::assertSame(RemoteConfigTestFixture::ID, $bridge->calls[1][1]['id']);
    }
}
