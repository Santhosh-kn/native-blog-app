<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Bbs\NativeContacts\Contracts\NativeBridge;
use Bbs\NativeContacts\NativeContacts;
use Bbs\NativeContacts\Support\NativeContactsErrorCode as ErrorCode;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class RecordingNativeContactsHttpBridge implements NativeBridge
{
    public array $calls = [];

    public ?Closure $reply = null;

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        if ($this->reply !== null) {
            return ($this->reply)($method, $parameters);
        }

        if ($method === 'NativeContacts.IsAvailable') {
            return (object) [
                'available' => true,
                'platform' => 'android',
                'apiLevel' => 36,
                'minimumApiLevel' => 33,
                'capabilities' => (object) [
                    'pickContact' => true,
                    'pickPhone' => true,
                    'pickEmail' => true,
                    'create' => true,
                    'open' => true,
                ],
                'errorCode' => null,
            ];
        }

        $operation = match ($method) {
            'NativeContacts.Pick' => 'pick',
            'NativeContacts.Create' => 'create',
            'NativeContacts.Open' => 'open',
            default => null,
        };

        if ($operation !== null) {
            return self::result(
                id: $parameters['id'],
                operation: $operation,
                mode: $parameters['mode'] ?? null,
                status: 'pending',
                accepted: true,
            );
        }

        if (in_array($method, [
            'NativeContacts.GetStatus',
            'NativeContacts.ConsumeResult',
        ], true)) {
            return self::result(
                id: $parameters['id'],
                operation: null,
                mode: null,
                status: 'not_found',
                errorCode: ErrorCode::RESULT_NOT_FOUND,
            );
        }

        return null;
    }

    public static function result(
        string $id,
        ?string $operation = 'pick',
        ?string $mode = 'contact',
        string $status = 'selected',
        bool $accepted = false,
        bool $consumed = false,
        ?string $errorCode = null,
    ): object {
        $missing = $status === 'not_found';

        return (object) [
            'id' => $id,
            'operation' => $operation,
            'mode' => $mode,
            'status' => $status,
            'accepted' => $accepted,
            'success' => in_array($status, ['selected', 'launched'], true),
            'cancelled' => $status === 'cancelled',
            'consumed' => $consumed,
            'errorCode' => $errorCode,
            'createdAtMs' => $missing ? null : 1_700_000_000_000,
            'completedAtMs' => $missing || $status === 'pending'
                ? null
                : 1_700_000_000_010,
        ];
    }
}

final class NativeContactsHttpTest extends TestCase
{
    private const ID = '123e4567-e89b-42d3-a456-426614174000';

    private const SESSION_KEY = 'native_contacts_diagnostics_request_ids';

    private const PRIVATE_NAME = 'Private Selection Fixture';

    private const PRIVATE_PHONE = '+1 202-555-0199';

    private const PRIVATE_EMAIL = 'private.selection@example.invalid';

    private const PRIVATE_URI = 'content://com.android.contacts/contacts/12345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('session.driver', 'array');
        $this->app['config']->set('cache.default', 'array');
    }

    public static function protectedRoutes(): iterable
    {
        yield 'page' => ['GET', 'index', []];
        yield 'availability' => ['GET', 'availability', []];
        yield 'diagnostics' => ['POST', 'diagnostics', []];
        yield 'pick' => ['POST', 'pick', []];
        yield 'create' => ['POST', 'create', []];
        yield 'status' => ['GET', 'status', ['id' => self::ID]];
        yield 'consume' => ['POST', 'consume', ['id' => self::ID]];
        yield 'open' => ['POST', 'open-selection', ['id' => self::ID]];
    }

    #[DataProvider('protectedRoutes')]
    public function test_routes_require_login_before_native_calls(
        string $method,
        string $route,
        array $parameters,
    ): void {
        $bridge = $this->installBridge();

        $this->call(
            $method,
            route('native-contacts.'.$route, $parameters),
            ['mode' => 'contact'],
        )->assertRedirect(route('login'));

        self::assertSame([], $bridge->calls);
    }

    #[DataProvider('protectedRoutes')]
    public function test_routes_require_unlock_before_native_calls(
        string $method,
        string $route,
        array $parameters,
    ): void {
        $bridge = $this->installBridge();
        $this->signIn();

        $this->call(
            $method,
            route('native-contacts.'.$route, $parameters),
            ['mode' => 'contact'],
        )->assertRedirect(route('unlock'));

        self::assertSame([], $bridge->calls);
    }

    public function test_page_renders_without_starting_native_operations(): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->get(route('native-contacts.index'));

        $response->assertOk()
            ->assertViewIs('native-contacts')
            ->assertSeeText('Android readiness')
            ->assertSeeText('Run safe diagnostics')
            ->assertSeeText('Pick contact')
            ->assertSeeText('Pick phone')
            ->assertSeeText('Pick email')
            ->assertSeeText('Open demo contact editor')
            ->assertSeeText('Consume and open selected contact');

        self::assertSame([], $bridge->calls);
        self::assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', ''),
        );
    }

    public function test_home_links_to_contacts(): void
    {
        $this->unlock();

        $this->view('home', ['posts' => collect()])
            ->assertSeeText('Contacts')
            ->assertSee(
                'href="'.route('native-contacts.index').'"',
                false,
            );
    }

    public function test_availability_calls_only_the_readiness_method(): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->getJson(route('native-contacts.availability'));

        $response->assertOk()->assertJson([
            'available' => true,
            'platform' => 'android',
            'apiLevel' => 36,
            'minimumApiLevel' => 33,
            'capabilities' => [
                'pickContact' => true,
                'pickPhone' => true,
                'pickEmail' => true,
                'create' => true,
                'open' => true,
            ],
            'errorCode' => null,
            'errorMessage' => null,
        ]);

        self::assertSame([
            ['method' => 'NativeContacts.IsAvailable', 'parameters' => []],
        ], $bridge->calls);

        self::assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', ''),
        );
    }

    public function test_diagnostics_generate_an_unused_id_and_start_no_ui(): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->postJson(route('native-contacts.diagnostics'), [
            'id' => self::ID,
            'phone' => self::PRIVATE_PHONE,
        ]);

        $response->assertOk()->assertJson([
            'passed' => true,
            'expectedErrorCode' => ErrorCode::RESULT_NOT_FOUND,
        ]);

        $id = $response->json('requestId');
        self::assertTrue(Str::isUuid($id));
        self::assertNotSame(self::ID, $id);

        self::assertSame([
            ['method' => 'NativeContacts.GetStatus', 'parameters' => ['id' => $id]],
            ['method' => 'NativeContacts.ConsumeResult', 'parameters' => ['id' => $id]],
        ], $bridge->calls);

        $response->assertSessionMissing(self::SESSION_KEY);
        $this->assertPrivateDataAbsent($response->json());
    }

    public function test_diagnostics_skip_consumption_if_the_id_is_not_missing(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static fn (string $method, array $parameters): object => RecordingNativeContactsHttpBridge::result(
            id: $parameters['id'],
            status: 'pending',
        );

        $this->unlock();

        $this->postJson(route('native-contacts.diagnostics'))
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonMissingPath('operations.consumeResult');

        self::assertSame(['NativeContacts.GetStatus'], array_column($bridge->calls, 'method'));
    }

    public static function pickerModes(): iterable
    {
        yield 'contact' => ['contact'];
        yield 'phone' => ['phone'];
        yield 'email' => ['email'];
    }

    #[DataProvider('pickerModes')]
    public function test_pick_uses_a_server_id_and_only_the_selected_mode(string $mode): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->postJson(route('native-contacts.pick'), [
            'id' => self::ID,
            'mode' => $mode,
            'name' => self::PRIVATE_NAME,
            'phone' => self::PRIVATE_PHONE,
        ]);

        $response->assertOk()
            ->assertJsonPath('result.operation', 'pick')
            ->assertJsonPath('result.mode', $mode)
            ->assertJsonPath('result.status', 'pending')
            ->assertJsonPath('result.accepted', true);

        $id = $response->json('result.id');
        self::assertTrue(Str::isUuid($id));
        self::assertNotSame(self::ID, $id);

        self::assertSame([
            ['method' => 'NativeContacts.Pick', 'parameters' => ['id' => $id, 'mode' => $mode]],
        ], $bridge->calls);

        $response->assertSessionHas(self::SESSION_KEY, [$id]);
        $this->assertPrivateDataAbsent($response->json());
    }

    public static function invalidModes(): iterable
    {
        yield 'missing' => [null];
        yield 'uppercase' => ['CONTACT'];
        yield 'number' => [123];
        yield 'array' => [['contact']];
        yield 'unknown' => ['private-mode'];
    }

    #[DataProvider('invalidModes')]
    public function test_invalid_modes_do_not_reach_native_code(mixed $mode): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $this->postJson(route('native-contacts.pick'), ['mode' => $mode])
            ->assertStatus(422)
            ->assertJsonPath('result.errorCode', ErrorCode::INVALID_MODE)
            ->assertSessionMissing(self::SESSION_KEY);

        self::assertSame([], $bridge->calls);
    }

    public function test_create_uses_demo_prefills_and_reports_launch_without_save(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static fn (string $method, array $parameters): object => RecordingNativeContactsHttpBridge::result(
            id: $parameters['id'],
            operation: 'create',
            mode: null,
            status: 'launched',
            accepted: true,
        );

        $this->unlock();

        $response = $this->postJson(route('native-contacts.create'), [
            'name' => self::PRIVATE_NAME,
            'phone' => self::PRIVATE_PHONE,
            'email' => self::PRIVATE_EMAIL,
        ]);

        $response->assertOk()
            ->assertJsonPath('result.status', 'launched')
            ->assertJsonPath('result.operation', 'create')
            ->assertJsonMissingPath('saved')
            ->assertJsonMissingPath('result.saved');

        $id = $response->json('result.id');

        self::assertSame([
            [
                'method' => 'NativeContacts.Create',
                'parameters' => [
                    'id' => $id,
                    'name' => 'Native Contacts Demo',
                    'phone' => '+1 202-555-0100',
                    'email' => 'native.contacts@example.invalid',
                ],
            ],
        ], $bridge->calls);

        $response->assertSessionHas(self::SESSION_KEY, [$id]);
        $this->assertPrivateDataAbsent($response->json());
    }

    public static function ownedEndpoints(): iterable
    {
        yield 'status' => ['GET', 'status'];
        yield 'consume' => ['POST', 'consume'];
        yield 'open' => ['POST', 'open-selection'];
    }

    #[DataProvider('ownedEndpoints')]
    public function test_unowned_ids_are_rejected_before_native_calls(
        string $method,
        string $route,
    ): void {
        $bridge = $this->installBridge();
        $this->unlock();

        $this->json($method, route('native-contacts.'.$route, ['id' => self::ID]))
            ->assertNotFound();

        self::assertSame([], $bridge->calls);
    }

    public function test_owned_status_returns_metadata_only(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static fn (string $method, array $parameters): object => RecordingNativeContactsHttpBridge::result(id: $parameters['id']);

        $this->unlock();
        $this->withSession([self::SESSION_KEY => [self::ID]]);

        $response = $this->getJson(route('native-contacts.status', ['id' => self::ID]));

        $response->assertOk()
            ->assertJsonPath('result.status', 'selected')
            ->assertJsonPath('result.consumed', false)
            ->assertJsonMissingPath('selectionReceived');

        self::assertSame([
            ['method' => 'NativeContacts.GetStatus', 'parameters' => ['id' => self::ID]],
        ], $bridge->calls);

        $this->assertPrivateDataAbsent($response->json());
    }

    #[DataProvider('pickerModes')]
    public function test_consumption_redacts_data_and_repeat_consumption_is_metadata_only(
        string $mode,
    ): void {
        $bridge = $this->installBridge();
        $calls = 0;

        $bridge->reply = function (string $method, array $parameters) use ($mode, &$calls): object {
            $calls++;

            $result = RecordingNativeContactsHttpBridge::result(
                id: $parameters['id'],
                mode: $mode,
                consumed: true,
                errorCode: $calls === 1 ? null : ErrorCode::RESULT_ALREADY_CONSUMED,
            );

            if ($calls === 1) {
                $result->selection = $this->selection($mode);
            }

            return $result;
        };

        $this->unlock();
        $this->withSession([self::SESSION_KEY => [self::ID]]);

        $first = $this->postJson(route('native-contacts.consume', ['id' => self::ID]));

        $first->assertOk()
            ->assertJsonPath('result.consumed', true)
            ->assertJsonPath('selectionReceived', true);

        $second = $this->postJson(route('native-contacts.consume', ['id' => self::ID]));

        $second->assertOk()
            ->assertJsonPath('selectionReceived', false)
            ->assertJsonPath('result.errorCode', ErrorCode::RESULT_ALREADY_CONSUMED);

        self::assertSame([
            'NativeContacts.ConsumeResult',
            'NativeContacts.ConsumeResult',
        ], array_column($bridge->calls, 'method'));

        $this->assertPrivateDataAbsent($first->json());
        $this->assertPrivateDataAbsent($second->json());
        $this->assertPrivateDataAbsent($this->app['session']->all());
    }

    public function test_open_consumes_the_contact_and_passes_its_uri_only_to_native(): void
    {
        $bridge = $this->installBridge();

        $bridge->reply = function (string $method, array $parameters): object {
            if ($method === 'NativeContacts.Open') {
                return RecordingNativeContactsHttpBridge::result(
                    id: $parameters['id'],
                    operation: 'open',
                    mode: null,
                    status: 'pending',
                    accepted: true,
                );
            }

            $result = RecordingNativeContactsHttpBridge::result(
                id: $parameters['id'],
                consumed: $method === 'NativeContacts.ConsumeResult',
            );

            if ($method === 'NativeContacts.ConsumeResult') {
                $result->selection = $this->selection('contact');
            }

            return $result;
        };

        $this->unlock();
        $this->withSession([self::SESSION_KEY => [self::ID]]);

        $response = $this->postJson(
            route('native-contacts.open-selection', ['id' => self::ID]),
        );

        $response->assertOk()
            ->assertJsonPath('openAttempted', true)
            ->assertJsonPath('selectionResult.consumed', true)
            ->assertJsonPath('result.operation', 'open')
            ->assertJsonPath('result.status', 'pending');

        $openId = $response->json('result.id');

        self::assertNotSame(self::ID, $openId);
        self::assertSame([
            ['method' => 'NativeContacts.GetStatus', 'parameters' => ['id' => self::ID]],
            ['method' => 'NativeContacts.ConsumeResult', 'parameters' => ['id' => self::ID]],
            ['method' => 'NativeContacts.Open', 'parameters' => ['id' => $openId, 'uri' => self::PRIVATE_URI]],
        ], $bridge->calls);

        $response->assertSessionHas(self::SESSION_KEY, [self::ID, $openId]);
        $this->assertPrivateDataAbsent($response->json());
        $this->assertPrivateDataAbsent($this->app['session']->all());
    }

    public static function selectionsThatCannotOpen(): iterable
    {
        yield 'phone' => ['phone', 'selected', false];
        yield 'email' => ['email', 'selected', false];
        yield 'consumed contact' => ['contact', 'selected', true];
        yield 'pending contact' => ['contact', 'pending', false];
    }

    #[DataProvider('selectionsThatCannotOpen')]
    public function test_open_does_not_consume_an_unsuitable_selection(
        string $mode,
        string $status,
        bool $consumed,
    ): void {
        $bridge = $this->installBridge();

        $bridge->reply = static fn (string $method, array $parameters): object => RecordingNativeContactsHttpBridge::result(
            id: $parameters['id'],
            mode: $mode,
            status: $status,
            consumed: $consumed,
        );

        $this->unlock();
        $this->withSession([self::SESSION_KEY => [self::ID]]);

        $this->postJson(route('native-contacts.open-selection', ['id' => self::ID]))
            ->assertStatus(409)
            ->assertJsonPath('openAttempted', false);

        self::assertSame(['NativeContacts.GetStatus'], array_column($bridge->calls, 'method'));
    }

    public function test_request_history_is_bounded_and_contains_only_valid_ids(): void
    {
        $this->installBridge();
        $this->unlock();

        $ids = [];
        for ($index = 0; $index < 32; $index++) {
            $ids[] = (string) Str::uuid();
        }

        $this->withSession([
            self::SESSION_KEY => [self::PRIVATE_NAME, 123, ...$ids],
        ]);

        $response = $this->postJson(route('native-contacts.pick'), ['mode' => 'contact']);
        $response->assertOk();

        $stored = $this->app['session']->get(self::SESSION_KEY);

        self::assertCount(32, $stored);
        self::assertSame(array_slice($ids, 1), array_slice($stored, 0, 31));
        self::assertSame($response->json('result.id'), $stored[31]);

        $this->assertPrivateDataAbsent($this->app['session']->all());
    }

    public function test_bridge_exception_details_are_not_returned_or_logged(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static function (): never {
            throw new RuntimeException(self::PRIVATE_NAME.' '.self::PRIVATE_URI);
        };

        $this->unlock();
        Log::spy();

        $response = $this->getJson(route('native-contacts.availability'));

        $response->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('errorCode', ErrorCode::BRIDGE_UNAVAILABLE);

        $this->assertPrivateDataAbsent($response->json());
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    private function installBridge(): RecordingNativeContactsHttpBridge
    {
        $bridge = new RecordingNativeContactsHttpBridge;

        $this->app->instance(NativeContacts::class, new NativeContacts($bridge));

        return $bridge;
    }

    private function signIn(): void
    {
        $user = new User;
        $user->forceFill([
            'id' => 41,
            'name' => 'Contacts HTTP Test User',
            'email' => 'contacts.http@example.invalid',
        ]);

        $this->actingAs($user);
    }

    private function unlock(): void
    {
        $this->signIn();
        $this->withSession(['device_unlocked' => true]);
    }

    private function selection(string $mode): object
    {
        $selection = [
            'displayName' => self::PRIVATE_NAME,
            'contactUri' => self::PRIVATE_URI,
        ];

        if ($mode === 'phone') {
            $selection['phoneNumber'] = self::PRIVATE_PHONE;
        } elseif ($mode === 'email') {
            $selection['emailAddress'] = self::PRIVATE_EMAIL;
        }

        return (object) $selection;
    }

    private function assertPrivateDataAbsent(array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        foreach ([
            self::PRIVATE_NAME,
            self::PRIVATE_PHONE,
            self::PRIVATE_EMAIL,
            self::PRIVATE_URI,
            '"selection":',
            '"displayName":',
            '"contactUri":',
            '"phoneNumber":',
            '"emailAddress":',
        ] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }
}
