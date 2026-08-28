<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\NativePrintingSamplePdf;
use Bbs\NativePrinting\Events\NativePrintingStateChanged;
use Bbs\NativePrinting\Contracts\NativeBridge;
use Bbs\NativePrinting\NativePrinting as NativePrintingService;
use Bbs\NativePrinting\Support\LocalPdfValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecordingNativePrintingBridge implements NativeBridge
{
    /**
     * @var list<array{
     *     method: string,
     *     parameters: array<string, mixed>
     * }>
     */
    public array $calls = [];

    public function call(
        string $method,
        array $parameters = [],
    ): ?object {
        $this->calls[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        return (object) [];
    }
}
final class NativePrintingTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_printing_event_is_cached_by_request_id(): void
    {
        $requestId = (string) Str::uuid();

        event(new NativePrintingStateChanged(
            request_id: $requestId,
            action: 'print',
            status: 'submitted',
            job_id: 'print-job-123',
        ));

        $this->assertSame(
            [
                'request_id' => $requestId,
                'action' => 'print',
                'status' => 'submitted',
                'job_id' => 'print-job-123',
                'error_code' => null,
                'error_message' => null,
            ],
            Cache::get(
                "native_printing_result:{$requestId}",
            ),
        );
    }

    public function test_invalid_native_printing_event_is_not_cached(): void
    {
        $requestId = (string) Str::uuid();

        event(new NativePrintingStateChanged(
            request_id: $requestId,
            action: 'print',
            status: 'invented-status',
        ));

        $this->assertNull(
            Cache::get(
                "native_printing_result:{$requestId}",
            ),
        );
    }

    public function test_native_printing_page_is_available_after_unlock(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
            ])
            ->get(route('native-printing.index'));

        $response
            ->assertOk()
            ->assertViewIs('native-printing')
            ->assertViewHas(
                'sampleFileName',
                NativePrintingSamplePdf::FILE_NAME,
            )
            ->assertSee('Preview sample PDF')
            ->assertSee('Open system print dialog');
    }

    public function test_preview_starts_with_private_sample_pdf(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
            ])
            ->postJson(route('native-printing.preview'));

        $response
            ->assertStatus(202)
            ->assertJson([
                'accepted' => true,
                'action' => 'preview',
                'status' => 'accepted',
            ]);

        $this->assertCount(1, $bridge->calls);

        $call = $bridge->calls[0];

        $this->assertSame(
            'NativePrinting.Preview',
            $call['method'],
        );

        $this->assertSame(
            'Native Printing Sample',
            $call['parameters']['title'] ?? null,
        );

        $capturedPath = $call['parameters']['path'] ?? null;
        $capturedRequestId =
            $call['parameters']['request_id'] ?? null;

        $this->assertIsString($capturedPath);
        $this->assertIsString($capturedRequestId);
        $this->assertTrue(Str::isUuid($capturedRequestId));

        $this->assertSame(
            $capturedRequestId,
            $response->json('request_id'),
        );

        $this->assertSame(
            NativePrintingSamplePdf::FILE_NAME,
            basename($capturedPath),
        );

        $storageRoot = realpath(storage_path('app'));

        $this->assertIsString($storageRoot);
        $this->assertStringStartsWith(
            rtrim(
                $storageRoot,
                DIRECTORY_SEPARATOR,
            ).DIRECTORY_SEPARATOR,
            $capturedPath,
        );

        $this->assertSame(
            '%PDF-',
            file_get_contents(
                $capturedPath,
                false,
                null,
                0,
                5,
            ),
        );

        $response->assertSessionHas(
            'native_printing_requests',
            static fn (mixed $requests): bool =>
                is_array($requests) &&
                ($requests[$capturedRequestId] ?? null) ===
                    'preview',
        );
    }
    public function test_print_starts_with_correlated_request_id(): void
    {
        $user = User::factory()->create();
        $bridge = $this->installRecordingBridge();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
            ])
            ->postJson(route('native-printing.print'));

        $response
            ->assertStatus(202)
            ->assertJson([
                'accepted' => true,
                'action' => 'print',
                'status' => 'accepted',
            ]);

        $this->assertCount(1, $bridge->calls);

        $call = $bridge->calls[0];

        $this->assertSame(
            'NativePrinting.Print',
            $call['method'],
        );

        $this->assertSame(
            'Native Printing Sample',
            $call['parameters']['job_name'] ?? null,
        );

        $capturedPath = $call['parameters']['path'] ?? null;
        $capturedRequestId =
            $call['parameters']['request_id'] ?? null;

        $this->assertIsString($capturedPath);
        $this->assertIsString($capturedRequestId);
        $this->assertTrue(Str::isUuid($capturedRequestId));
        $this->assertFileExists($capturedPath);

        $this->assertSame(
            $capturedRequestId,
            $response->json('request_id'),
        );

        $response->assertSessionHas(
            'native_printing_requests',
            static fn (mixed $requests): bool =>
                is_array($requests) &&
                ($requests[$capturedRequestId] ?? null) ===
                    'print',
        );
    }
    public function test_status_remains_pending_without_native_event(): void
    {
        $user = User::factory()->create();
        $requestId = (string) Str::uuid();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                'native_printing_requests' => [
                    $requestId => 'preview',
                ],
            ])
            ->getJson(route(
                'native-printing.status',
                ['requestId' => $requestId],
            ));

        $response
            ->assertOk()
            ->assertExactJson([
                'request_id' => $requestId,
                'action' => 'preview',
                'status' => 'pending',
                'terminal' => false,
            ])
            ->assertSessionHas(
                'native_printing_requests',
                [$requestId => 'preview'],
            );
    }

    public function test_status_rejects_request_not_owned_by_session(): void
    {
        $user = User::factory()->create();

        $ownedRequestId = (string) Str::uuid();
        $differentRequestId = (string) Str::uuid();

        $cacheKey = (
            "native_printing_result:{$differentRequestId}"
        );

        Cache::put(
            $cacheKey,
            [
                'request_id' => $differentRequestId,
                'action' => 'print',
                'status' => 'completed',
                'job_id' => 'print-job-456',
                'error_code' => null,
                'error_message' => null,
            ],
            now()->addMinutes(2),
        );

        $response = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                'native_printing_requests' => [
                    $ownedRequestId => 'print',
                ],
            ])
            ->getJson(route(
                'native-printing.status',
                ['requestId' => $differentRequestId],
            ));

        $response
            ->assertForbidden()
            ->assertJson([
                'status' => 'forbidden',
            ])
            ->assertSessionHas(
                'native_printing_requests',
                [$ownedRequestId => 'print'],
            );

        $this->assertNotNull(Cache::get($cacheKey));
    }

    public function test_print_status_progresses_to_terminal_completion(): void
    {
        $user = User::factory()->create();
        $requestId = (string) Str::uuid();

        event(new NativePrintingStateChanged(
            request_id: $requestId,
            action: 'print',
            status: 'submitted',
            job_id: 'print-job-789',
        ));

        $submittedResponse = $this
            ->actingAs($user)
            ->withSession([
                'device_unlocked' => true,
                'native_printing_requests' => [
                    $requestId => 'print',
                ],
            ])
            ->getJson(route(
                'native-printing.status',
                ['requestId' => $requestId],
            ));

        $submittedResponse
            ->assertOk()
            ->assertExactJson([
                'request_id' => $requestId,
                'action' => 'print',
                'status' => 'submitted',
                'terminal' => false,
                'job_id' => 'print-job-789',
                'error_code' => null,
                'error_message' => null,
            ])
            ->assertSessionHas(
                'native_printing_requests',
                [$requestId => 'print'],
            );

        $this->assertNull(Cache::get(
            "native_printing_result:{$requestId}",
        ));

        event(new NativePrintingStateChanged(
            request_id: $requestId,
            action: 'print',
            status: 'completed',
            job_id: 'print-job-789',
        ));

        $completedResponse = $this->getJson(route(
            'native-printing.status',
            ['requestId' => $requestId],
        ));

        $completedResponse
            ->assertOk()
            ->assertExactJson([
                'request_id' => $requestId,
                'action' => 'print',
                'status' => 'completed',
                'terminal' => true,
                'job_id' => 'print-job-789',
                'error_code' => null,
                'error_message' => null,
            ])
            ->assertSessionMissing(
                'native_printing_requests',
            );

        $this->assertNull(Cache::get(
            "native_printing_result:{$requestId}",
        ));
    }

    private function installRecordingBridge(): RecordingNativePrintingBridge
    {
        $bridge = new RecordingNativePrintingBridge();

        Facade::clearResolvedInstance(
            NativePrintingService::class,
        );

        $this->app->forgetInstance(
            NativePrintingService::class,
        );

        $this->app->instance(
            NativePrintingService::class,
            new NativePrintingService(
                validator: $this->app->make(
                    LocalPdfValidator::class,
                ),
                bridge: $bridge,
            ),
        );

        return $bridge;
    }
}
