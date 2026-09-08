<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\NativeDocumentPickerState;
use Bbs\NativeDocumentPicker\Contracts\NativeBridge;
use Bbs\NativeDocumentPicker\Facades\NativeDocumentPicker as NativeDocumentPickerFacade;
use Bbs\NativeDocumentPicker\NativeDocumentPicker as NativeDocumentPickerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecordingNativeDocumentPickerBridge implements NativeBridge
{
    /**
     * @var list<array{
     *     method: string,
     *     parameters: array<string, mixed>
     * }>
     */
    public array $calls = [];

    /** @var list<object|null> */
    private array $responses = [];

    public function queue(?object $response): void
    {
        $this->responses[] = $response;
    }

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = ['method' => $method, 'parameters' => $parameters];

        if ($this->responses === []) {
            return null;
        }

        return array_shift($this->responses);
    }
}

final class NativeDocumentPickerHttpTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_SESSION_KEY = 'native_document_picker_requests';

    private const SELECTION_SESSION_KEY = 'native_document_picker_selection';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        NativeDocumentPickerFacade::clearResolvedInstance(NativeDocumentPickerService::class);
        parent::tearDown();
    }

    public function test_page_is_available_after_unlock_and_contains_only_safe_metadata(): void
    {
        $user = User::factory()->create();
        $requestId = (string) Str::uuid();
        $privatePath = storage_path('app/native-document-picker/never-render-this-path.pdf');

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                self::SELECTION_SESSION_KEY => [
                    'request_id' => $requestId,
                    'original_name' => 'quarterly-report.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 4096,
                ],
            ])
            ->get(route('native-document-picker.index'));

        $response
            ->assertOk()
            ->assertViewIs('native-document-picker')
            ->assertViewHas('allowedMimeTypes', NativeDocumentPickerState::ALLOWED_MIME_TYPES)
            ->assertViewHas('defaultMaxSize', NativeDocumentPickerState::DEFAULT_MAX_SIZE)
            ->assertViewHas('maximumMaxSize', NativeDocumentPickerState::MAX_DEMO_SIZE)
            ->assertSeeText('Choose one document securely')
            ->assertSeeText('quarterly-report.pdf')
            ->assertSeeText('application/pdf');

        $content = $response->getContent();

        $this->assertStringContainsString('native-document-picker.active-request-id', $content);
        $this->assertStringNotContainsString($privatePath, $content);
        $this->assertStringNotContainsString('destination_path', $content);
        $this->assertStringNotContainsString('innerHTML', $content);
    }

    public function test_home_view_links_to_document_picker(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->view('home', [
            'posts' => collect(),
        ])
            ->assertSeeText('Document Picker')
            ->assertSee('href="'.route('native-document-picker.index').'"', false);
    }

    public function test_pick_starts_a_correlated_native_request(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();
        $bridge->queue((object) ['accepted' => true]);

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->postJson(
                route('native-document-picker.pick'),
                ['mime_types' => ['application/pdf', 'text/plain'], 'max_size' => 4096]
            );

        $response
            ->assertStatus(202)
            ->assertJson([
                'accepted' => true,
                'status' => 'pending',
                'error_code' => null,
                'error_message' => null,
            ]);

        $requestId = $response->json('request_id');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertCount(1, $bridge->calls);
        $this->assertSame('NativeDocumentPicker.Pick', $bridge->calls[0]['method']);
        $this->assertSame(
            [
                'id' => $requestId,
                'mime_types' => ['application/pdf', 'text/plain'],
                'max_size' => 4096,
                'destination_path' => storage_path('app/native-document-picker'),
            ],
            $bridge->calls[0]['parameters'],
        );

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('path', $payload);
        $this->assertArrayNotHasKey('destination_path', $payload);
        $this->assertStringNotContainsString(storage_path('app/native-document-picker'), $response->getContent());

        $response->assertSessionHas(
            self::REQUEST_SESSION_KEY,
            static fn (mixed $requests): bool => is_array($requests) &&
                ($requests[$requestId] ?? null) === [
                    'mime_types' => ['application/pdf', 'text/plain'],
                    'max_size' => 4096,
                ],
        );
    }

    public function test_pick_rejects_invalid_limits_without_calling_native_code(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true])
            ->postJson(
                route('native-document-picker.pick'),
                ['mime_types' => ['image/png'], 'max_size' => 0],
                ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
            );

        $this->assertSame(422, $response->getStatusCode(), 'Invalid picker options must return JSON validation errors.');

        $response
            ->assertJsonValidationErrors(['mime_types.0', 'max_size'])
            ->assertSessionMissing(self::REQUEST_SESSION_KEY);

        $this->assertSame([], $bridge->calls);
    }

    public function test_new_picker_request_clears_the_previous_selection(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();

        $bridge->queue((object) [
            'accepted' => true,
        ]);

        $previousRequestId = (string) Str::uuid();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                self::SELECTION_SESSION_KEY => [
                    'request_id' => $previousRequestId,
                    'original_name' => 'previous-document.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 4096,
                ],
            ])
            ->postJson(
                route('native-document-picker.pick'),
                [
                    'mime_types' => ['application/pdf'],
                    'max_size' => 4096,
                ],
            );

        $response
            ->assertStatus(202)
            ->assertSessionMissing(self::SELECTION_SESSION_KEY);

        $page = $this->get(route('native-document-picker.index'));

        $page
            ->assertOk()
            ->assertViewHas('selection', null)
            ->assertDontSeeText('previous-document.pdf');
    }

    public function test_status_remains_pending_for_an_owned_request(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();
        $bridge->queue((object) ['status' => 'pending']);

        $requestId = (string) Str::uuid();
        $requestOptions = ['mime_types' => ['application/pdf'], 'max_size' => 4096];

        $response = $this
            ->actingAs($user)
            ->withSession(['device_unlocked' => true, self::REQUEST_SESSION_KEY => [$requestId => $requestOptions]])
            ->getJson(route('native-document-picker.status', ['requestId' => $requestId]));

        $response
            ->assertOk()
            ->assertExactJson(['request_id' => $requestId, 'status' => 'pending', 'terminal' => false])
            ->assertSessionHas(self::REQUEST_SESSION_KEY, [$requestId => $requestOptions]);

        $this->assertSame([
            ['method' => 'NativeDocumentPicker.GetStatus', 'parameters' => ['id' => $requestId]],
        ], $bridge->calls);
    }

    public function test_status_rejects_an_unowned_request_without_consuming_its_cache(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();
        $ownedRequestId = (string) Str::uuid();
        $differentRequestId = (string) Str::uuid();
        $cacheKey = "native_document_picker_result:{$differentRequestId}";
        $cachedCompletion = [
            'id' => $differentRequestId,
            'status' => 'succeeded',
            'success' => true,
            'cancelled' => false,
            'error_code' => null,
            'error_message' => null,
        ];

        Cache::put($cacheKey, $cachedCompletion, 60);

        try {
            $response = $this
                ->actingAs($user)
                ->withSession([
                    'device_unlocked' => true,
                    self::REQUEST_SESSION_KEY => [$ownedRequestId => ['mime_types' => ['application/pdf'], 'max_size' => 4096]],
                ])
                ->getJson(route('native-document-picker.status', ['requestId' => $differentRequestId]));

            $response
                ->assertStatus(403)
                ->assertJson(['status' => 'forbidden', 'terminal' => true])
                ->assertSessionHas(
                    self::REQUEST_SESSION_KEY,
                    [$ownedRequestId => ['mime_types' => ['application/pdf'], 'max_size' => 4096]],
                );

            $this->assertSame($cachedCompletion, Cache::get($cacheKey));
            $this->assertSame([], $bridge->calls);
        } finally {
            Cache::forget($cacheKey);
        }
    }

    public function test_cancelled_completion_is_terminal_without_a_native_status_call(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();
        $requestId = (string) Str::uuid();
        $cacheKey = "native_document_picker_result:{$requestId}";

        Cache::put($cacheKey, [
            'id' => $requestId,
            'status' => 'cancelled',
            'success' => false,
            'cancelled' => true,
            'error_code' => null,
            'error_message' => null,
        ], 60);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                self::REQUEST_SESSION_KEY => [$requestId => ['mime_types' => ['application/pdf'], 'max_size' => 4096]],
            ])
            ->getJson(route('native-document-picker.status', ['requestId' => $requestId]));

        $response
            ->assertOk()
            ->assertExactJson([
                'request_id' => $requestId,
                'status' => 'cancelled',
                'terminal' => true,
                'success' => false,
                'cancelled' => true,
                'error_code' => null,
                'error_message' => null,
            ])
            ->assertSessionMissing(self::REQUEST_SESSION_KEY);

        $this->assertNull(Cache::get($cacheKey));
        $this->assertSame([], $bridge->calls);
    }

    public function test_cancelled_request_clears_previous_selection_on_page_revisit(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();
        $requestId = (string) Str::uuid();
        $previousRequestId = (string) Str::uuid();
        $cacheKey = "native_document_picker_result:{$requestId}";

        Cache::put($cacheKey, [
            'id' => $requestId,
            'status' => 'cancelled',
            'success' => false,
            'cancelled' => true,
            'error_code' => null,
            'error_message' => null,
        ], 60);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                self::REQUEST_SESSION_KEY => [
                    $requestId => [
                        'mime_types' => ['application/pdf'],
                        'max_size' => 4096,
                    ],
                ],
                self::SELECTION_SESSION_KEY => [
                    'request_id' => $previousRequestId,
                    'original_name' => 'previous-document.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 4096,
                ],
            ])
            ->getJson(route(
                'native-document-picker.status',
                ['requestId' => $requestId],
            ));

        $response
            ->assertOk()
            ->assertJson([
                'request_id' => $requestId,
                'status' => 'cancelled',
                'terminal' => true,
                'success' => false,
                'cancelled' => true,
            ])
            ->assertSessionMissing(self::REQUEST_SESSION_KEY)
            ->assertSessionMissing(self::SELECTION_SESSION_KEY);

        $page = $this->get(route('native-document-picker.index'));

        $page
            ->assertOk()
            ->assertViewHas('selection', null)
            ->assertDontSeeText('previous-document.pdf');

        $this->assertNull(Cache::get($cacheKey));
        $this->assertSame([], $bridge->calls);
    }

    public function test_successful_status_returns_metadata_without_the_private_path(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();
        $requestId = (string) Str::uuid();
        $contents = "%PDF-1.4\nprivate HTTP fixture";
        $privateRoot = storage_path('app/native-document-picker');

        File::ensureDirectoryExists($privateRoot);

        $privatePath = sprintf('%s%shttp-%s.pdf', $privateRoot, DIRECTORY_SEPARATOR, Str::uuid());

        File::put($privatePath, $contents);

        $bridge->queue((object) [
            'status' => 'succeeded',
            'success' => true,
            'cancelled' => false,
            'path' => $privatePath,
            'originalName' => 'safe-report.pdf',
            'mimeType' => 'application/pdf',
            'size' => strlen($contents),
        ]);

        try {
            $response = $this
                ->actingAs($user)
                ->withSession([
                    'device_unlocked' => true,
                    self::REQUEST_SESSION_KEY => [$requestId => ['mime_types' => ['application/pdf'], 'max_size' => 4096]],
                ])
                ->getJson(route('native-document-picker.status', ['requestId' => $requestId]));

            $selection = ['request_id' => $requestId, 'original_name' => 'safe-report.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($contents)];

            $response
                ->assertOk()
                ->assertExactJson([
                    'request_id' => $requestId,
                    'status' => 'succeeded',
                    'terminal' => true,
                    'success' => true,
                    'cancelled' => false,
                    'document' => $selection,
                    'error_code' => null,
                    'error_message' => null,
                ])
                ->assertSessionHas(self::SELECTION_SESSION_KEY, $selection)
                ->assertSessionMissing(self::REQUEST_SESSION_KEY);

            $payload = $response->json();

            $this->assertIsArray($payload);
            $this->assertArrayNotHasKey('path', $payload);
            $this->assertIsArray($payload['document'] ?? null);
            $this->assertArrayNotHasKey('path', $payload['document']);
            $this->assertStringNotContainsString($privatePath, $response->getContent());
            $this->assertFileExists($privatePath);
        } finally {
            File::delete($privatePath);
        }
    }

    public function test_failed_native_status_is_terminal_and_forgets_the_request(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();
        $requestId = (string) Str::uuid();

        $bridge->queue((object) ['status' => 'failed', 'success' => false, 'cancelled' => false, 'errorCode' => 'FILE_TOO_LARGE']);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                self::REQUEST_SESSION_KEY => [$requestId => ['mime_types' => ['application/pdf'], 'max_size' => 4096]],
            ])
            ->getJson(route('native-document-picker.status', ['requestId' => $requestId]));

        $response
            ->assertOk()
            ->assertJson([
                'request_id' => $requestId,
                'status' => 'failed',
                'terminal' => true,
                'success' => false,
                'cancelled' => false,
                'error_code' => 'FILE_TOO_LARGE',
            ])
            ->assertSessionMissing(self::REQUEST_SESSION_KEY);

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('path', $payload);
    }

    private function installRecordingBridge(): RecordingNativeDocumentPickerBridge
    {
        $bridge = new RecordingNativeDocumentPickerBridge;

        $this->app->instance(NativeBridge::class, $bridge);
        $this->app->instance(
            NativeDocumentPickerService::class,
            new NativeDocumentPickerService(bridge: $bridge, privateStoragePath: storage_path('app/native-document-picker')),
        );

        NativeDocumentPickerFacade::clearResolvedInstance(NativeDocumentPickerService::class);

        return $bridge;
    }
}
