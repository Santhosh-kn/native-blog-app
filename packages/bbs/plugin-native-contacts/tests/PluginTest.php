<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Tests;

use Bbs\NativeContacts\Contracts\NativeBridge;
use Bbs\NativeContacts\NativeContacts;
use Bbs\NativeContacts\NativeContactsServiceProvider;
use Bbs\NativeContacts\Support\NativeContactsErrorCode as ErrorCode;
use Bbs\NativeContacts\Support\NativeContactsRequestValidator as Validator;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class RecordingContactsBridge implements NativeBridge
{
    public array $calls = [];

    public function __construct(private array $responses = []) {}

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = ['method' => $method, 'parameters' => $parameters];
        $response = array_shift($this->responses);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

final class PluginTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_never_reach_android(
        string $method,
        array $options,
        string $expectedError,
    ): void {
        $bridge = new RecordingContactsBridge();
        $service = new NativeContacts($bridge);
        $result = $service->$method(['id' => self::ID] + $options);

        self::assertSame('failed', $result->status);
        self::assertSame($expectedError, $result->errorCode);
        self::assertSame([], $bridge->calls);
        self::assertNull($result->selectedData());
    }

    public static function invalidRequests(): iterable
    {
        yield 'unknown field' => [
            'pick', ['extra' => 'unused'], ErrorCode::INVALID_PARAMETERS,
        ];
        yield 'invalid mode' => [
            'pick', ['mode' => 'all'], ErrorCode::INVALID_MODE,
        ];
        yield 'null mode' => [
            'pick', ['mode' => null], ErrorCode::INVALID_MODE,
        ];
        yield 'name type' => [
            'create', ['name' => 123], ErrorCode::INVALID_NAME,
        ];
        yield 'blank name' => [
            'create', ['name' => '   '], ErrorCode::INVALID_NAME,
        ];
        yield 'name length' => [
            'create', ['name' => str_repeat('A', 121)], ErrorCode::INVALID_NAME,
        ];
        yield 'name control character' => [
            'create', ['name' => "Name\n"], ErrorCode::INVALID_NAME,
        ];
        yield 'phone letters' => [
            'create', ['phone' => 'abc123'], ErrorCode::INVALID_PHONE,
        ];
        yield 'phone digit count' => [
            'create', ['phone' => str_repeat('1', 16)], ErrorCode::INVALID_PHONE,
        ];
        yield 'phone length' => [
            'create', ['phone' => str_repeat(' ', 65)], ErrorCode::INVALID_PHONE,
        ];
        yield 'email structure' => [
            'create', ['email' => 'invalid'], ErrorCode::INVALID_EMAIL,
        ];
        yield 'email consecutive dots' => [
            'create', ['email' => 'a..b@example.test'], ErrorCode::INVALID_EMAIL,
        ];
        yield 'email invalid domain' => [
            'create', ['email' => 'a@-example.test'], ErrorCode::INVALID_EMAIL,
        ];
        yield 'request size' => [
            'create', ['name' => str_repeat('A', 8192)], ErrorCode::REQUEST_TOO_LARGE,
        ];
        yield 'invalid UTF-8' => [
            'create', ['name' => "\xFF"], ErrorCode::INVALID_PARAMETERS,
        ];
    }

    #[DataProvider('invalidUris')]
    public function test_invalid_uris_never_reach_android(string $uri): void
    {
        $bridge = new RecordingContactsBridge();
        $result = (new NativeContacts($bridge))->open($uri, ['id' => self::ID]);

        self::assertSame(ErrorCode::INVALID_URI, $result->errorCode);
        self::assertSame([], $bridge->calls);
    }

    public static function invalidUris(): iterable
    {
        foreach ([
            'https://example.test/contact/1',
            'file:///contacts/1',
            'content://other.provider/contacts/1',
            'content://user@com.android.contacts/contacts/1',
            'content://com.android.contacts:80/contacts/1',
            'content://com.android.contacts/data/1',
            'content://com.android.contacts/contacts/1?x=1',
            'content://com.android.contacts/contacts/1#fragment',
            'content://com.android.contacts/contacts/0',
            'content://com.android.contacts/contacts/9223372036854775808',
            'content://com.android.contacts/contacts/lookup/key%00',
            'content://com.android.contacts/contacts/lookup/key%ZZ',
            'content://com.android.contacts/contacts/lookup/..',
        ] as $uri) {
            yield [$uri];
        }
    }

    public function test_unicode_name_limit_counts_code_points(): void
    {
        self::assertTrue(Validator::isName(str_repeat('名', 120)));
        self::assertFalse(Validator::isName(str_repeat('名', 121)));
    }

    public function test_supported_contact_references_are_accepted(): void
    {
        self::assertTrue(Validator::isContactUri(
            'content://com.android.contacts/contacts/1',
        ));
        self::assertTrue(Validator::isContactUri(
            'content://com.android.contacts/contacts/lookup/test-key/1',
        ));
        self::assertTrue(Validator::isContactUri(
            'content://com.android.contacts/contacts/9223372036854775807',
        ));
    }

    public function test_invalid_request_id_is_not_echoed_or_sent(): void
    {
        $bridge = new RecordingContactsBridge();
        $result = (new NativeContacts($bridge))->getStatus('invalid-private-value');

        self::assertSame(ErrorCode::INVALID_REQUEST_ID, $result->errorCode);
        self::assertTrue(Validator::isRequestId($result->id));
        self::assertSame([], $bridge->calls);
        self::assertStringNotContainsString(
            'invalid-private-value',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_default_pick_mode_and_method_are_correct(): void
    {
        $response = $this->response('pending', 'pick', 'contact');
        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->pick(['id' => self::ID]);

        self::assertTrue($result->accepted);
        self::assertSame('pending', $result->status);
        self::assertSame([[
            'method' => 'NativeContacts.Pick',
            'parameters' => ['id' => self::ID, 'mode' => 'contact'],
        ]], $bridge->calls);
    }

    public function test_create_reports_launch_without_claiming_save(): void
    {
        $bridge = new RecordingContactsBridge([
            $this->response('launched', 'create', null),
        ]);
        $result = (new NativeContacts($bridge))->create([
            'id' => self::ID,
            'name' => 'Test Contact',
            'phone' => '+1 202-555-0123',
            'email' => 'contact@example.test',
        ]);

        self::assertSame('NativeContacts.Create', $bridge->calls[0]['method']);
        self::assertSame('launched', $result->status);
        self::assertTrue($result->success);
        self::assertNull($result->selectedData());
        self::assertStringNotContainsString(
            'saved',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_open_uses_only_the_validated_reference(): void
    {
        $bridge = new RecordingContactsBridge([
            $this->response('launched', 'open', null),
        ]);
        $uri = 'content://com.android.contacts/contacts/1';
        $result = (new NativeContacts($bridge))->open($uri, ['id' => self::ID]);

        self::assertSame('launched', $result->status);
        self::assertSame([[
            'method' => 'NativeContacts.Open',
            'parameters' => ['id' => self::ID, 'uri' => $uri],
        ]], $bridge->calls);
    }

    public function test_status_rejects_private_selection_data(): void
    {
        $response = $this->response('selected');
        $response->selection = (object) ['phoneNumber' => '+1 202-555-0123'];
        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->getStatus(self::ID);

        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertNull($result->selectedData());
    }

    public function test_consumed_data_requires_explicit_access_and_is_not_serialized(): void
    {
        $first = $this->response('selected');
        $first->consumed = true;
        $first->selection = (object) [
            'displayName' => 'Synthetic Contact',
            'contactUri' => 'content://com.android.contacts/contacts/1',
            'phoneNumber' => '+1 202-555-0123',
        ];

        $second = $this->response('selected');
        $second->consumed = true;
        $second->errorCode = ErrorCode::RESULT_ALREADY_CONSUMED;

        $bridge = new RecordingContactsBridge([$first, $second]);
        $service = new NativeContacts($bridge);
        $result = $service->consumeResult(self::ID);

        self::assertSame('+1 202-555-0123', $result->selectedData()['phoneNumber']);

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['Synthetic Contact', '+1 202-555-0123', 'content://', 'selection'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }

        $repeat = $service->consumeResult(self::ID);
        self::assertSame(ErrorCode::RESULT_ALREADY_CONSUMED, $repeat->errorCode);
        self::assertNull($repeat->selectedData());
        self::assertSame(
            ['NativeContacts.ConsumeResult', 'NativeContacts.ConsumeResult'],
            array_column($bridge->calls, 'method'),
        );
    }

    public function test_wrong_request_response_is_rejected(): void
    {
        $response = $this->response();
        $response->id = '22222222-2222-4222-8222-222222222222';
        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->pick([
            'id' => self::ID,
            'mode' => 'phone',
        ]);

        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertSame(self::ID, $result->id);
    }

    public function test_unknown_request_has_safe_not_found_status(): void
    {
        $response = $this->response('not_found', null, null);
        $response->errorCode = ErrorCode::RESULT_NOT_FOUND;
        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->getStatus(self::ID);

        self::assertSame('not_found', $result->status);
        self::assertFalse($result->success);
        self::assertNull($result->selectedData());
    }

    public function test_bridge_exception_details_do_not_escape(): void
    {
        $bridge = new RecordingContactsBridge([
            new RuntimeException('Private exception sample'),
        ]);
        $result = (new NativeContacts($bridge))->pick(['id' => self::ID]);

        self::assertSame(ErrorCode::BRIDGE_UNAVAILABLE, $result->errorCode);
        self::assertStringNotContainsString(
            'Private exception sample',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_availability_preserves_individual_capabilities(): void
    {
        $response = (object) [
            'available' => true,
            'platform' => 'android',
            'apiLevel' => 36,
            'minimumApiLevel' => 33,
            'capabilities' => (object) [
                'pickContact' => false,
                'pickPhone' => true,
                'pickEmail' => false,
                'create' => false,
                'open' => false,
            ],
            'errorCode' => null,
        ];

        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->isAvailable();

        self::assertTrue($result->available);
        self::assertTrue($result->capabilities->pickPhone);
        self::assertFalse($result->capabilities->pickEmail);
        self::assertSame([[
            'method' => 'NativeContacts.IsAvailable',
            'parameters' => [],
        ]], $bridge->calls);
    }

    public function test_malformed_availability_is_rejected(): void
    {
        $bridge = new RecordingContactsBridge([(object) ['available' => 'yes']]);
        $result = (new NativeContacts($bridge))->isAvailable();

        self::assertFalse($result->available);
        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
    }

    public function test_provider_supports_injected_bridge_and_singleton_service(): void
    {
        $container = new Container();
        (new NativeContactsServiceProvider($container))->register();

        $bridge = new RecordingContactsBridge([$this->response()]);
        $container->instance(NativeBridge::class, $bridge);
        $service = $container->make(NativeContacts::class);

        self::assertSame($service, $container->make(NativeContacts::class));
        self::assertSame('pending', $service->pick([
            'id' => self::ID,
            'mode' => 'phone',
        ])->status);
        self::assertCount(1, $bridge->calls);
    }

    #[DataProvider('lookupMethods')]
    public function test_lookup_preserves_storage_failure(string $method): void
    {
        $response = $this->response('failed', null, null);
        unset($response->accepted);
        $response->errorCode = ErrorCode::RESULT_PERSISTENCE_FAILED;
        $response->errorMessage = 'private-storage-detail';
        $response->createdAtMs = null;
        $response->completedAtMs = null;

        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->$method(self::ID);

        self::assertSame('failed', $result->status);
        self::assertSame(ErrorCode::RESULT_PERSISTENCE_FAILED, $result->errorCode);
        self::assertSame(
            ErrorCode::message(ErrorCode::RESULT_PERSISTENCE_FAILED),
            $result->errorMessage,
        );
        self::assertNull($result->operation);
        self::assertNull($result->mode);
        self::assertFalse($result->accepted);
        self::assertNull($result->selectedData());
        self::assertStringNotContainsString(
            'private-storage-detail',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            $method === 'getStatus'
                ? 'NativeContacts.GetStatus'
                : 'NativeContacts.ConsumeResult',
            $bridge->calls[0]['method'],
        );
    }

    public static function lookupMethods(): iterable
    {
        yield 'status' => ['getStatus'];
        yield 'consume' => ['consumeResult'];
    }

    #[DataProvider('malformedStorageFailures')]
    public function test_malformed_storage_failure_is_rejected(
        array $changes,
    ): void {
        $response = $this->response('failed', null, null);
        $response->errorCode = ErrorCode::RESULT_PERSISTENCE_FAILED;

        foreach ($changes as $field => $value) {
            $response->$field = $value;
        }

        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->consumeResult(self::ID);

        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertNull($result->selectedData());
        self::assertStringNotContainsString(
            'private-storage-detail',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public static function malformedStorageFailures(): iterable
    {
        yield 'mode without operation' => [['mode' => 'phone']];
        yield 'accepted failure' => [['accepted' => true]];
        yield 'creation metadata without operation' => [['createdAtMs' => 1]];
        yield 'completion metadata without operation' => [['completedAtMs' => 1]];
        yield 'private selection on failure' => [[
            'selection' => (object) ['displayName' => 'private-storage-detail'],
        ]];
        yield 'unknown error code' => [[
            'errorCode' => 'private-storage-detail',
        ]];
        yield 'different known error without operation' => [[
            'errorCode' => ErrorCode::CONTACT_READ_FAILED,
        ]];
        yield 'failure claiming success' => [['success' => true]];
    }

    public function test_start_still_requires_matching_operation(): void
    {
        $response = $this->response('failed', null, null);
        $response->errorCode = ErrorCode::RESULT_PERSISTENCE_FAILED;

        $bridge = new RecordingContactsBridge([$response]);
        $result = (new NativeContacts($bridge))->pick([
            'id' => self::ID,
            'mode' => 'phone',
        ]);

        self::assertSame(ErrorCode::INVALID_NATIVE_RESPONSE, $result->errorCode);
        self::assertSame('pick', $result->operation);
        self::assertNull($result->selectedData());
    }

    private function response(
        string $status = 'pending',
        ?string $operation = 'pick',
        ?string $mode = 'phone',
    ): object {
        return (object) [
            'id' => self::ID,
            'operation' => $operation,
            'mode' => $mode,
            'status' => $status,
            'accepted' => in_array($status, ['pending', 'launched'], true),
            'success' => in_array($status, ['selected', 'launched'], true),
            'cancelled' => $status === 'cancelled',
            'consumed' => false,
            'errorCode' => null,
        ];
    }
}
