<?php

declare(strict_types=1);

namespace Bbs\NativePasskeys\Tests;

use Bbs\NativePasskeys\Contracts\NativeBridge;
use Bbs\NativePasskeys\NativePasskeys;
use Bbs\NativePasskeys\Support\NativePasskeysErrorCode;
use PHPUnit\Framework\TestCase;

final class FakeNativeBridge implements NativeBridge
{
    /**
     * @var list<array{
     *     method: string,
     *     parameters: array<string, mixed>
     * }>
     */
    public array $calls = [];

    public function __construct(
        private readonly ?object $response,
    ) {}

    public function call(
        string $method,
        array $parameters = [],
    ): ?object {
        $this->calls[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        return $this->response;
    }
}

final class PluginTest extends TestCase
{
    private const REQUEST_ID =
        '123e4567-e89b-12d3-a456-426614174000';

    public function test_manifest_is_android_only_with_six_functions(): void
    {
        $manifest = json_decode(
            file_get_contents(
                dirname(__DIR__).'/nativephp.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['android'],
            $manifest['platforms'],
        );

        self::assertSame(
            28,
            $manifest['android']['min_version'],
        );

        self::assertSame(
            [
                'NativePasskeys.IsAvailable',
                'NativePasskeys.Create',
                'NativePasskeys.Authenticate',
                'NativePasskeys.GetStatus',
                'NativePasskeys.ConsumeResult',
                'NativePasskeys.Cancel',
            ],
            array_column(
                $manifest['bridge_functions'],
                'name',
            ),
        );

        foreach ($manifest['bridge_functions'] as $function) {
            self::assertArrayHasKey(
                'android',
                $function,
            );

            self::assertArrayNotHasKey(
                'ios',
                $function,
            );
        }
    }

    public function test_create_starts_valid_request(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'accepted' => true,
                'id' => self::REQUEST_ID,
                'operation' => 'create',
                'status' => 'pending',
            ],
        );

        $result = (new NativePasskeys($bridge))
            ->create(
                $this->createOptions(),
                self::REQUEST_ID,
            );

        self::assertTrue($result->accepted);
        self::assertSame(
            self::REQUEST_ID,
            $result->id,
        );

        self::assertSame(
            'NativePasskeys.Create',
            $bridge->calls[0]['method'],
        );

        self::assertSame(
            self::REQUEST_ID,
            $bridge->calls[0]['parameters']['id'],
        );
    }

    public function test_create_rejects_invalid_json_without_native_call(): void
    {
        $bridge = new FakeNativeBridge(null);

        $result = (new NativePasskeys($bridge))
            ->create(
                '{"challenge":',
                self::REQUEST_ID,
            );

        self::assertFalse($result->accepted);

        self::assertSame(
            NativePasskeysErrorCode::
                INVALID_REQUEST_JSON,
            $result->errorCode,
        );

        self::assertCount(0, $bridge->calls);
    }

    public function test_create_requires_registration_fields(): void
    {
        $bridge = new FakeNativeBridge(null);

        $result = (new NativePasskeys($bridge))
            ->create(
                '{"challenge":"test"}',
                self::REQUEST_ID,
            );

        self::assertFalse($result->accepted);

        self::assertSame(
            NativePasskeysErrorCode::
                INVALID_REQUEST_JSON,
            $result->errorCode,
        );
    }

    public function test_authenticate_starts_valid_request(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'accepted' => true,
                'id' => self::REQUEST_ID,
                'operation' => 'authenticate',
                'status' => 'pending',
            ],
        );

        $result = (new NativePasskeys($bridge))
            ->authenticate(
                $this->authenticationOptions(),
                self::REQUEST_ID,
            );

        self::assertTrue($result->accepted);

        self::assertSame(
            'NativePasskeys.Authenticate',
            $bridge->calls[0]['method'],
        );
    }

    public function test_availability_is_normalized(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'available' => true,
                'apiLevel' => 35,
            ],
        );

        $result = (new NativePasskeys($bridge))
            ->isAvailable();

        self::assertTrue($result->available);
        self::assertSame(35, $result->apiLevel);
        self::assertSame(
            28,
            $result->minimumApiLevel,
        );
        self::assertNull($result->errorCode);
    }

    public function test_get_status_returns_pending_result(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'operation' => 'authenticate',
                'status' => 'pending',
                'success' => false,
                'cancelled' => false,
                'consumed' => false,
            ],
        );

        $result = (new NativePasskeys($bridge))
            ->getStatus(self::REQUEST_ID);

        self::assertSame('pending', $result->status);
        self::assertFalse($result->success);
        self::assertFalse($result->consumed);
    }

    public function test_consume_returns_credential_response_once(): void
    {
        $responseJson = json_encode(
            [
                'id' => 'credential-id',
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => 'encoded',
                ],
            ],
            JSON_THROW_ON_ERROR,
        );

        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'operation' => 'authenticate',
                'status' => 'succeeded',
                'success' => true,
                'cancelled' => false,
                'consumed' => true,
                'responseJson' => $responseJson,
            ],
        );

        $result = (new NativePasskeys($bridge))
            ->consumeResult(self::REQUEST_ID);

        self::assertTrue($result->success);
        self::assertTrue($result->consumed);
        self::assertSame(
            $responseJson,
            $result->responseJson,
        );
    }

    public function test_already_consumed_result_is_a_failure(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'operation' => 'create',
                'status' => 'failed',
                'success' => false,
                'cancelled' => false,
                'consumed' => true,
                'errorCode' =>
                    NativePasskeysErrorCode::
                        RESULT_ALREADY_CONSUMED,
            ],
        );

        $result = (new NativePasskeys($bridge))
            ->consumeResult(self::REQUEST_ID);

        self::assertSame('failed', $result->status);
        self::assertTrue($result->consumed);

        self::assertSame(
            NativePasskeysErrorCode::
                RESULT_ALREADY_CONSUMED,
            $result->errorCode,
        );
    }

    public function test_cancel_returns_cancelled_result(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'operation' => 'create',
                'status' => 'cancelled',
                'success' => false,
                'cancelled' => true,
                'consumed' => false,
            ],
        );

        $result = (new NativePasskeys($bridge))
            ->cancel(self::REQUEST_ID);

        self::assertSame(
            'cancelled',
            $result->status,
        );

        self::assertTrue($result->cancelled);
    }

    public function test_invalid_request_id_never_calls_native_bridge(): void
    {
        $bridge = new FakeNativeBridge(null);

        $result = (new NativePasskeys($bridge))
            ->getStatus('not-a-uuid');

        self::assertSame(
            NativePasskeysErrorCode::
                INVALID_REQUEST_ID,
            $result->errorCode,
        );

        self::assertCount(0, $bridge->calls);
    }

    public function test_missing_native_bridge_is_controlled_failure(): void
    {
        $bridge = new FakeNativeBridge(null);

        $result = (new NativePasskeys($bridge))
            ->authenticate(
                $this->authenticationOptions(),
                self::REQUEST_ID,
            );

        self::assertFalse($result->accepted);

        self::assertSame(
            NativePasskeysErrorCode::
                ACTIVITY_UNAVAILABLE,
            $result->errorCode,
        );
    }

    private function createOptions(): string
    {
        return json_encode(
            [
                'challenge' => 'server-challenge',
                'rp' => [
                    'id' => 'example.com',
                    'name' => 'Example',
                ],
                'user' => [
                    'id' => 'encoded-user-id',
                    'name' => 'user@example.com',
                    'displayName' => 'Example User',
                ],
                'pubKeyCredParams' => [
                    [
                        'type' => 'public-key',
                        'alg' => -7,
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    private function authenticationOptions(): string
    {
        return json_encode(
            [
                'challenge' => 'server-challenge',
                'rpId' => 'example.com',
                'allowCredentials' => [],
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
