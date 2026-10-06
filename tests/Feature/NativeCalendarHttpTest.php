<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Bbs\NativeCalendar\Contracts\NativeBridge;
use Bbs\NativeCalendar\NativeCalendar;
use Bbs\NativeCalendar\Support\NativeCalendarErrorCode as ErrorCode;
use Bbs\NativeCalendar\Support\NativeCalendarRequestValidator as Validator;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class RecordingNativeCalendarHttpBridge implements NativeBridge
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

        if ($method === 'NativeCalendar.IsAvailable') {
            return (object) [
                'available' => true,
                'platform' => 'android',
                'apiLevel' => 36,
                'minimumApiLevel' => 33,
                'capabilities' => (object) [
                    'createEvent' => true,
                    'openDate' => true,
                    'openEvent' => true,
                ],
                'errorCode' => null,
                'errorMessage' => null,
            ];
        }

        if ($method === 'NativeCalendar.CreateEvent') {
            return self::result($parameters['id'], 'create_event', 'editor');
        }

        if ($method === 'NativeCalendar.Open') {
            return self::result(
                $parameters['id'],
                'open',
                array_key_exists('dateMs', $parameters) ? 'date' : 'event',
            );
        }

        if ($method === 'NativeCalendar.GetStatus') {
            return self::result(
                $parameters['id'],
                null,
                null,
                'not_found',
                ErrorCode::RESULT_NOT_FOUND,
            );
        }

        return null;
    }

    public static function result(
        string $id,
        ?string $operation,
        ?string $target,
        string $status = 'pending',
        ?string $errorCode = null,
    ): object {
        $missing = $status === 'not_found';

        return (object) [
            'id' => $id,
            'operation' => $operation,
            'target' => $target,
            'status' => $status,
            'accepted' => ! $missing,
            'success' => $status === 'launched',
            'errorCode' => $errorCode,
            'errorMessage' => $errorCode === null
                ? null
                : ErrorCode::message($errorCode),
            'createdAtMs' => $missing ? null : 1_700_000_000_000,
            'completedAtMs' => $missing || $status === 'pending'
                ? null
                : 1_700_000_000_010,
        ];
    }
}

final class NativeCalendarHttpTest extends TestCase
{
    private const ID = '123e4567-e89b-42d3-a456-426614174000';

    private const SECOND_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const SESSION_KEY = 'native_calendar_diagnostics_request_ids';

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
        yield 'create' => ['POST', 'create', []];
        yield 'date viewer' => ['POST', 'open-date', []];
        yield 'event viewer' => ['POST', 'open-event', []];
        yield 'status' => ['GET', 'status', ['id' => self::ID]];
    }

    #[DataProvider('protectedRoutes')]
    public function test_login_is_required_before_native_calls(
        string $method,
        string $route,
        array $parameters,
    ): void {
        $bridge = $this->installBridge();

        $this->call(
            $method,
            route('native-calendar.'.$route, $parameters),
            ['preset' => 'timed', 'eventId' => '1'],
        )->assertRedirect(route('login'));

        self::assertSame([], $bridge->calls);
    }

    #[DataProvider('protectedRoutes')]
    public function test_device_unlock_is_required_before_native_calls(
        string $method,
        string $route,
        array $parameters,
    ): void {
        $bridge = $this->installBridge();
        $this->signIn();

        $this->call(
            $method,
            route('native-calendar.'.$route, $parameters),
            ['preset' => 'timed', 'eventId' => '1'],
        )->assertRedirect(route('unlock'));

        self::assertSame([], $bridge->calls);
    }

    public function test_page_renders_filtered_history_without_native_calls(): void
    {
        $bridge = $this->installBridge();
        $this->unlock();
        $this->withSession([
            self::SESSION_KEY => [self::ID, 'PRIVATE_HISTORY', self::SECOND_ID, self::ID],
        ]);

        $response = $this->get(route('native-calendar.index'));

        $response->assertOk()
            ->assertViewIs('native-calendar')
            ->assertViewHas('requestIds', [self::SECOND_ID, self::ID])
            ->assertSeeText('Timed event')
            ->assertSeeText('All-day event')
            ->assertSeeText('Weekly event')
            ->assertSeeText('Open tomorrow')
            ->assertSeeText('Open event')
            ->assertSeeText('Refresh status')
            ->assertDontSee('PRIVATE_HISTORY');

        self::assertSame([], $bridge->calls);
        $this->assertNoStore($response);
    }

    public function test_home_links_to_calendar(): void
    {
        $this->unlock();

        $this->view('home', ['posts' => collect()])
            ->assertSeeText('Calendar')
            ->assertSee('href="'.route('native-calendar.index').'"', false);
    }

    public function test_availability_calls_only_the_readiness_bridge(): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->getJson(route('native-calendar.availability'));

        $response->assertOk()->assertExactJson([
            'available' => true,
            'platform' => 'android',
            'apiLevel' => 36,
            'minimumApiLevel' => 33,
            'capabilities' => [
                'createEvent' => true,
                'openDate' => true,
                'openEvent' => true,
            ],
            'errorCode' => null,
            'errorMessage' => null,
        ]);

        self::assertSame([
            ['method' => 'NativeCalendar.IsAvailable', 'parameters' => []],
        ], $bridge->calls);
        $this->assertNoStore($response);
    }

    public function test_malformed_availability_is_controlled(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static fn (): object => (object) [
            'available' => true,
            'title' => 'PRIVATE_NATIVE_TITLE',
        ];
        $this->unlock();

        $response = $this->getJson(route('native-calendar.availability'));

        $response->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('errorCode', ErrorCode::INVALID_NATIVE_RESPONSE);

        $this->assertPrivateDataAbsent($response->json());
        $this->assertNoStore($response);
    }

    public static function presets(): iterable
    {
        yield 'timed' => ['timed', false, false];
        yield 'all day' => ['all-day', true, false];
        yield 'recurring' => ['recurring', false, true];
    }

    #[DataProvider('presets')]
    public function test_presets_use_fixed_values_and_store_only_request_ids(
        string $preset,
        bool $allDay,
        bool $recurring,
    ): void {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->postJson(route('native-calendar.create'), [
            'preset' => $preset,
            'id' => self::ID,
            'title' => 'PRIVATE_BODY_TITLE',
            'description' => 'PRIVATE_BODY_DESCRIPTION',
            'location' => 'PRIVATE_BODY_LOCATION',
        ]);

        $response->assertOk()
            ->assertJsonPath('result.operation', 'create_event')
            ->assertJsonPath('result.target', 'editor')
            ->assertJsonPath('result.status', 'pending')
            ->assertJsonPath('result.accepted', true)
            ->assertJsonPath('result.success', false);

        self::assertCount(1, $bridge->calls);
        self::assertSame('NativeCalendar.CreateEvent', $bridge->calls[0]['method']);
        $options = $bridge->calls[0]['parameters'];

        self::assertTrue(Validator::isRequestId($options['id']));
        self::assertNotSame(self::ID, $options['id']);
        self::assertSame($response->json('result.id'), $options['id']);
        self::assertSame('Native Calendar Demo', $options['title']);
        self::assertSame(
            'This is a sample event. Review it before saving.',
            $options['description'],
        );
        self::assertSame('Demo location', $options['location']);
        self::assertSame($allDay, $options['allDay']);
        self::assertIsInt($options['startTimeMs']);
        self::assertIsInt($options['endTimeMs']);
        self::assertSame(
            $allDay ? 86_400_000 : 3_600_000,
            $options['endTimeMs'] - $options['startTimeMs'],
        );
        self::assertSame($allDay ? 'UTC' : 'Asia/Kolkata', $options['timeZone']);

        if ($allDay) {
            self::assertSame(0, $options['startTimeMs'] % 86_400_000);
            self::assertSame(0, $options['endTimeMs'] % 86_400_000);
        } else {
            $localStart = (new DateTimeImmutable('@'.intdiv($options['startTimeMs'], 1000)))
                ->setTimezone(new DateTimeZone('Asia/Kolkata'));
            self::assertSame('10:00:00', $localStart->format('H:i:s'));
        }

        if ($recurring) {
            self::assertSame('FREQ=WEEKLY;COUNT=3', $options['recurrence']);
        } else {
            self::assertArrayNotHasKey('recurrence', $options);
        }

        $response->assertSessionHas(self::SESSION_KEY, [$options['id']]);
        $this->assertMetadata($response->json('result'));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertPrivateDataAbsent($this->app['session']->all());
        $this->assertNoStore($response);
    }

    public static function invalidPresets(): iterable
    {
        yield 'missing' => [null];
        yield 'unknown' => ['PRIVATE_UNKNOWN'];
        yield 'numeric' => [1];
        yield 'array' => [['PRIVATE_PRESET']];
    }

    #[DataProvider('invalidPresets')]
    public function test_invalid_presets_do_not_call_the_bridge(mixed $preset): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->postJson(route('native-calendar.create'), [
            'preset' => $preset,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('result.errorCode', ErrorCode::INVALID_OPTIONS)
            ->assertJsonPath('result.accepted', false);

        self::assertSame([], $bridge->calls);
        self::assertNull($this->app['session']->get(self::SESSION_KEY));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertNoStore($response);
    }

    public function test_date_viewer_uses_tomorrow_without_storing_the_date(): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $before = (new DateTimeImmutable('tomorrow 00:00:00', new DateTimeZone('UTC')))
            ->getTimestamp() * 1000;

        $response = $this->postJson(route('native-calendar.open-date'), [
            'dateMs' => 'PRIVATE_BODY_DATE',
        ]);

        $after = (new DateTimeImmutable('tomorrow 00:00:00', new DateTimeZone('UTC')))
            ->getTimestamp() * 1000;

        $response->assertOk()->assertJsonPath('result.target', 'date');
        self::assertCount(1, $bridge->calls);
        self::assertSame('NativeCalendar.Open', $bridge->calls[0]['method']);

        $options = $bridge->calls[0]['parameters'];
        self::assertSame(['dateMs', 'id'], array_keys($options));
        self::assertTrue(in_array($options['dateMs'], [$before, $after], true));
        $response->assertSessionHas(self::SESSION_KEY, [$options['id']]);

        $this->assertMetadata($response->json('result'));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertPrivateDataAbsent($this->app['session']->all());
    }

    public static function validEventIds(): iterable
    {
        yield 'minimum' => ['1', 1];
        yield 'maximum safe integer' => ['9007199254740991', 9007199254740991];
    }

    #[DataProvider('validEventIds')]
    public function test_event_id_is_converted_without_float_coercion_and_not_stored(
        string $input,
        int $expected,
    ): void {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->postJson(route('native-calendar.open-event'), [
            'eventId' => $input,
        ]);

        $response->assertOk()->assertJsonPath('result.target', 'event');
        self::assertCount(1, $bridge->calls);
        self::assertSame('NativeCalendar.Open', $bridge->calls[0]['method']);

        $options = $bridge->calls[0]['parameters'];
        self::assertSame(['eventId', 'id'], array_keys($options));
        self::assertSame($expected, $options['eventId']);
        $response->assertSessionHas(self::SESSION_KEY, [$options['id']]);

        $this->assertMetadata($response->json('result'));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertPrivateDataAbsent($this->app['session']->all());
        $this->assertNoStore($response);
    }

    public static function invalidEventIds(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => ['0'];
        yield 'leading zero' => ['01'];
        yield 'fraction' => ['1.5'];
        yield 'too large' => ['9007199254740992'];
        yield 'JSON number instead of browser string' => [1];
        yield 'array' => [['PRIVATE_EVENT_ID']];
    }

    #[DataProvider('invalidEventIds')]
    public function test_invalid_event_id_never_reaches_the_bridge(mixed $input): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->postJson(route('native-calendar.open-event'), [
            'eventId' => $input,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('result.errorCode', ErrorCode::INVALID_EVENT_ID)
            ->assertJsonPath('result.accepted', false);

        self::assertSame([], $bridge->calls);
        self::assertNull($this->app['session']->get(self::SESSION_KEY));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertNoStore($response);
    }

    public static function inaccessibleIds(): iterable
    {
        yield 'valid but unowned' => [self::ID];
        yield 'malformed' => ['not-a-uuid'];
    }

    #[DataProvider('inaccessibleIds')]
    public function test_unowned_or_malformed_status_ids_do_not_call_native(string $id): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $this->getJson(route('native-calendar.status', ['id' => $id]))
            ->assertNotFound();

        self::assertSame([], $bridge->calls);
    }

    public function test_owned_status_returns_only_validated_metadata(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static fn (string $method, array $parameters): object => RecordingNativeCalendarHttpBridge::result(
            $parameters['id'],
            'open',
            'event',
            'launched',
        );

        $this->unlock();
        $this->withSession([self::SESSION_KEY => [self::ID]]);

        $response = $this->getJson(route('native-calendar.status', ['id' => self::ID]));

        $response->assertOk()
            ->assertJsonPath('result.status', 'launched')
            ->assertJsonPath('result.success', true);

        self::assertSame([
            ['method' => 'NativeCalendar.GetStatus', 'parameters' => ['id' => self::ID]],
        ], $bridge->calls);

        $this->assertMetadata($response->json('result'));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertNoStore($response);
    }

    public function test_diagnostics_only_checks_a_fresh_unused_id(): void
    {
        $bridge = $this->installBridge();
        $this->unlock();

        $response = $this->postJson(route('native-calendar.diagnostics'));

        $response->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('result.status', 'not_found')
            ->assertJsonPath('result.errorCode', ErrorCode::RESULT_NOT_FOUND);

        self::assertCount(1, $bridge->calls);
        self::assertSame('NativeCalendar.GetStatus', $bridge->calls[0]['method']);
        $id = $bridge->calls[0]['parameters']['id'];
        self::assertTrue(Validator::isRequestId($id));
        self::assertSame($id, $response->json('result.id'));
        $response->assertSessionHas(self::SESSION_KEY, [$id]);

        $this->assertMetadata($response->json('result'));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertNoStore($response);
    }

    public function test_request_history_filters_values_and_keeps_at_most_32_ids(): void
    {
        $this->installBridge();
        $this->unlock();

        $ids = [];
        for ($index = 0; $index < 32; $index++) {
            $ids[] = (string) Str::uuid();
        }

        $this->withSession([
            self::SESSION_KEY => ['PRIVATE_HISTORY', 42, $ids[0], ...$ids],
        ]);

        $response = $this->postJson(route('native-calendar.open-date'));
        $response->assertOk();

        $stored = $this->app['session']->get(self::SESSION_KEY);
        self::assertCount(32, $stored);
        self::assertSame(array_slice($ids, 1), array_slice($stored, 0, 31));
        self::assertSame($response->json('result.id'), $stored[31]);
        $this->assertPrivateDataAbsent($this->app['session']->all());
    }

    public static function malformedHistory(): iterable
    {
        yield 'string' => ['PRIVATE_HISTORY'];
        yield 'nested values' => [[['PRIVATE_HISTORY'], null, false]];
    }

    #[DataProvider('malformedHistory')]
    public function test_malformed_history_is_replaced_by_valid_request_ids(mixed $history): void
    {
        $this->installBridge();
        $this->unlock();
        $this->withSession([self::SESSION_KEY => $history]);

        $response = $this->postJson(route('native-calendar.open-date'));

        $response->assertOk()
            ->assertSessionHas(self::SESSION_KEY, [$response->json('result.id')]);

        $this->assertPrivateDataAbsent($this->app['session']->all());
    }

    public function test_bridge_exception_is_redacted_without_logging_details(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static function (): never {
            throw new RuntimeException('PRIVATE_EXCEPTION event details');
        };

        $this->unlock();
        Log::spy();

        $response = $this->postJson(route('native-calendar.create'), [
            'preset' => 'timed',
        ]);

        $response->assertOk()
            ->assertJsonPath('result.errorCode', ErrorCode::BRIDGE_UNAVAILABLE)
            ->assertJsonPath('result.accepted', false);

        $response->assertSessionHas(self::SESSION_KEY, [$response->json('result.id')]);
        $this->assertMetadata($response->json('result'));
        $this->assertPrivateDataAbsent($response->json());
        $this->assertPrivateDataAbsent($this->app['session']->all());
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_native_event_fields_cannot_leak_through_a_status_response(): void
    {
        $bridge = $this->installBridge();
        $bridge->reply = static function (string $method, array $parameters): object {
            $result = RecordingNativeCalendarHttpBridge::result(
                $parameters['id'],
                'create_event',
                'editor',
                'launched',
            );
            $result->title = 'PRIVATE_NATIVE_TITLE';
            $result->eventId = 12345;

            return $result;
        };

        $this->unlock();
        $this->withSession([self::SESSION_KEY => [self::ID]]);

        $response = $this->getJson(route('native-calendar.status', ['id' => self::ID]));

        $response->assertOk()
            ->assertJsonPath('result.errorCode', ErrorCode::INVALID_NATIVE_RESPONSE);

        $this->assertMetadata($response->json('result'));
        $this->assertPrivateDataAbsent($response->json());
    }

    private function installBridge(): RecordingNativeCalendarHttpBridge
    {
        $bridge = new RecordingNativeCalendarHttpBridge;

        $this->app->instance(NativeCalendar::class, new NativeCalendar($bridge));

        return $bridge;
    }

    private function signIn(): void
    {
        $user = new User;
        $user->forceFill([
            'id' => 42,
            'name' => 'Calendar HTTP Test User',
            'email' => 'calendar.http@example.invalid',
        ]);

        $this->actingAs($user);
    }

    private function unlock(): void
    {
        $this->signIn();
        $this->withSession(['device_unlocked' => true]);
    }

    private function assertMetadata(array $metadata): void
    {
        $expected = [
            'id', 'operation', 'target', 'status', 'accepted', 'success',
            'errorCode', 'errorMessage', 'createdAtMs', 'completedAtMs',
        ];
        $actual = array_keys($metadata);
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    private function assertPrivateDataAbsent(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('PRIVATE_', $json);

        foreach ([
            'title', 'description', 'location', 'dateMs', 'eventId',
            'startTimeMs', 'endTimeMs', 'timeZone', 'recurrence',
        ] as $key) {
            self::assertStringNotContainsString('"'.$key.'":', $json);
        }
    }

    private function assertNoStore(mixed $response): void
    {
        self::assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', ''),
        );
    }
}
