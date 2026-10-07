<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\NativeMediaOptimizerDemoState as State;
use App\Support\NativeMediaOptimizerFileGuard as FileGuard;
use Bbs\NativeDocumentPicker\Contracts\NativeBridge as PickerBridge;
use Bbs\NativeDocumentPicker\NativeDocumentPicker;
use Bbs\NativeMediaOptimizer\Contracts\NativeBridge;
use Bbs\NativeMediaOptimizer\NativeMediaOptimizer;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Share;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class RecordingNativeMediaOptimizerHttpBridge implements NativeBridge
{
    public array $calls = [];
    public array $records = [];
    public ?Closure $reply = null;

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = [$method, $parameters];
        if ($this->reply !== null) { return ($this->reply)($method, $parameters); }
        $operation = substr($method, strlen('NativeMediaOptimizer.'));
        if ($operation === 'IsAvailable') { return (object) ['platform' => 'android', 'available' => true, 'images' => true, 'video' => true, 'thumbnails' => true]; }
        $id = $parameters['id'];
        if (in_array($operation, State::OPERATIONS, true)) {
            return $this->records[$id] = self::record($id, $operation, $parameters['source_document_id']);
        }
        if ($operation === 'DeleteOutput') {
            $record = $this->records[$id] ?? null;
            if ($record === null || $record->output === null || ! $record->outputAvailable) {
                return (object) ['id' => $id, 'deleted' => false, 'errorCode' => ErrorCode::OUTPUT_NOT_FOUND];
            }
            if (is_file($record->output->path)) { unlink($record->output->path); }
            $record->outputAvailable = false;
            return (object) ['id' => $id, 'deleted' => true, 'errorCode' => null];
        }
        if ($operation === 'Cancel' && isset($this->records[$id])) {
            $this->records[$id]->status = 'cancelling'; $this->records[$id]->phase = 'cancelling';
        }
        return $this->records[$id] ?? (object) ['id' => $id, 'operation' => null, 'accepted' => false, 'status' => 'not_found',
            'sourceDocumentId' => null, 'progress' => null, 'phase' => 'failed', 'input' => null, 'output' => null, 'outputAvailable' => false, 'errorCode' => ErrorCode::RESULT_NOT_FOUND];
    }

    public static function record(string $id, string $operation, string $source, string $status = 'pending', ?object $output = null): object
    {
        return (object) ['id' => $id, 'operation' => $operation, 'accepted' => true, 'status' => $status,
            'sourceDocumentId' => $source, 'progress' => $status === 'succeeded' ? 100 : 0,
            'phase' => $status === 'succeeded' ? 'completed' : ($status === 'interrupted' ? 'interrupted' : 'queued'),
            'input' => $status === 'succeeded' ? (object) ['mime_type' => in_array($operation, ['OptimizeVideo', 'GenerateThumbnail'], true) ? 'video/mp4' : 'image/png',
                'size' => 70, 'width' => in_array($operation, ['OptimizeVideo', 'GenerateThumbnail'], true) ? 160 : 1, 'height' => in_array($operation, ['OptimizeVideo', 'GenerateThumbnail'], true) ? 96 : 1,
                'duration_ms' => in_array($operation, ['OptimizeVideo', 'GenerateThumbnail'], true) ? 3000 : null, 'rotation_degrees' => 0, 'has_audio' => false] : null,
            'output' => $output, 'outputAvailable' => $output !== null,
            'errorCode' => $status === 'interrupted' ? ErrorCode::PROCESS_INTERRUPTED : null];
    }
}

final class RecordingNativeMediaPickerHttpBridge implements PickerBridge
{
    public array $calls = [];
    public ?Closure $reply = null;
    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = [$method, $parameters];
        if ($this->reply !== null) { return ($this->reply)($method, $parameters); }
        return $method === 'NativeDocumentPicker.Pick' ? (object) ['accepted' => true] : (object) ['status' => 'pending'];
    }
}

final class NativeMediaOptimizerHttpTest extends TestCase
{
    private const ID = '123e4567-e89b-42d3-a456-426614174000';
    private const SOURCE = '123e4567-e89b-42d3-a456-426614174001';
    private const OTHER = '123e4567-e89b-42d3-a456-426614174002';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGN4FqDRAAAFXQHfw5N8iAAAAABJRU5ErkJggg==';
    private string $temporary;
    private string $originalStorage;
    private RecordingNativeMediaOptimizerHttpBridge $bridge;
    private RecordingNativeMediaPickerHttpBridge $picker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('session.driver', 'array');
        $this->app['config']->set('cache.default', 'array');
        $this->originalStorage = $this->app->storagePath();
        $this->temporary = sys_get_temp_dir().'/native-media-http-'.Str::uuid();
        (new Filesystem)->makeDirectory($this->temporary.'/app/native-media-optimizer', 0700, true);
        (new Filesystem)->makeDirectory($this->temporary.'/app/native-document-picker', 0700, true);
        $this->app->useStoragePath($this->temporary);
        $this->bridge = new RecordingNativeMediaOptimizerHttpBridge;
        $this->picker = new RecordingNativeMediaPickerHttpBridge;
        $this->app->instance(NativeMediaOptimizer::class, new NativeMediaOptimizer($this->bridge, $this->temporary.'/app/native-media-optimizer'));
        $this->app->instance(NativeDocumentPicker::class, new NativeDocumentPicker($this->picker, $this->temporary.'/app/native-document-picker'));
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        (new Filesystem)->deleteDirectory($this->temporary);
        parent::tearDown();
    }

    public static function protectedRoutes(): array
    {
        return [
            'page' => ['GET', 'index', []], 'availability' => ['GET', 'availability', []],
            'picker' => ['POST', 'pick', []], 'picker status' => ['GET', 'pick-status', ['id' => self::ID]],
            'start' => ['POST', 'start', []], 'status' => ['GET', 'status', ['id' => self::ID]],
            'cancel' => ['POST', 'cancel', ['id' => self::ID]], 'preview' => ['GET', 'preview', ['id' => self::ID]],
            'share' => ['POST', 'share', ['id' => self::ID]], 'delete' => ['POST', 'delete', ['id' => self::ID]],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_authentication_precedes_every_native_call(string $method, string $route, array $parameters): void
    {
        $this->call($method, route('native-media-optimizer.'.$route, $parameters))->assertRedirect(route('login'));
        self::assertSame([], $this->bridge->calls); self::assertSame([], $this->picker->calls);
    }

    #[DataProvider('protectedRoutes')]
    public function test_device_unlock_precedes_every_native_call(string $method, string $route, array $parameters): void
    {
        $this->signIn();
        $this->call($method, route('native-media-optimizer.'.$route, $parameters))->assertRedirect(route('unlock'));
        self::assertSame([], $this->bridge->calls); self::assertSame([], $this->picker->calls);
    }

    public function test_page_restores_safe_state_and_automatically_checks_availability(): void
    {
        $this->unlock();
        $data = $this->ownedState(); $data['selection']['path'] = 'PRIVATE_PATH'; $data['selection']['original_name'] = 'PRIVATE_NAME';
        $data['jobs'][self::ID]['extra'] = 'PRIVATE_EXTRA';
        $this->withSession([State::KEY => $data]);
        $response = $this->get(route('native-media-optimizer.index'));
        $response->assertOk()->assertViewIs('native-media-optimizer')->assertSeeText('Select image')->assertSeeText('Select video')
            ->assertSeeText('Optimize image')->assertSeeText('Optimize video')->assertSeeText('Generate thumbnail')->assertSeeText('Refresh status')
            ->assertDontSee('PRIVATE_PATH')->assertDontSee('PRIVATE_NAME')->assertDontSee('PRIVATE_EXTRA')->assertSee('checkAvailability();', false);
        self::assertSame([], $this->bridge->calls); self::assertSame([], $this->picker->calls);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_media_labels_preserve_utf8_symbols(): void
    {
        $this->unlock();
        $this->get(route('native-media-optimizer.index'))->assertOk()->assertSee("Quality (1\u{2013}100)", false)->assertSee(" \u{2022} ", false);
        $this->view('home', ['posts' => collect()])->assertSeeText("\u{1F4DD} My Posts");
    }

    public function test_home_has_a_media_optimizer_link(): void
    {
        $this->unlock();
        $this->view('home', ['posts' => collect()])->assertSeeText('Media Optimizer')->assertSee('href="'.route('native-media-optimizer.index').'"', false);
    }

    public function test_availability_exposes_only_validated_capabilities(): void
    {
        $this->unlock();
        $this->getJson(route('native-media-optimizer.availability'))->assertOk()->assertJsonPath('available', true)->assertJsonPath('images', true);
        self::assertSame([['NativeMediaOptimizer.IsAvailable', []]], $this->bridge->calls);
    }

    public static function mediaKinds(): array { return ['images' => ['image', State::IMAGE_MIMES, 104857600], 'videos' => ['video', State::VIDEO_MIMES, 536870912]]; }

    #[DataProvider('mediaKinds')]
    public function test_picker_uses_bounded_media_filters_and_saves_ownership_first(string $kind, array $mimes, int $maximum): void
    {
        $this->unlock();
        $this->picker->reply = function (string $method, array $parameters): object {
            self::assertArrayHasKey($parameters['id'], $this->app->make('session.store')->get(State::KEY)['picks']);
            return (object) ['accepted' => true];
        };
        $response = $this->postJson(route('native-media-optimizer.pick'), ['kind' => $kind])->assertStatus(202)->assertJsonPath('accepted', true);
        self::assertSame($mimes, $this->picker->calls[0][1]['mime_types']);
        self::assertSame($maximum, $this->picker->calls[0][1]['max_size']);
        self::assertSame($response->json('id'), $this->picker->calls[0][1]['id']);
        self::assertStringNotContainsString($this->temporary, $response->getContent());
    }

    public function test_an_unavailable_picker_acknowledgement_retains_the_id_for_recovery(): void
    {
        $this->unlock(); $this->picker->reply = fn (): ?object => null;
        $response = $this->postJson(route('native-media-optimizer.pick'), ['kind' => 'image'])
            ->assertStatus(503)->assertJsonPath('retryable', true);
        $id = $response->json('id');
        self::assertFalse($this->app->make('session.store')->get(State::KEY)['picks'][$id]['terminal']);
        $this->picker->reply = null;
        $this->getJson(route('native-media-optimizer.pick-status', ['id' => $id]))->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_picker_success_remembers_metadata_without_paths_or_names(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->temporary.'/app/native-document-picker/'.self::ID.'.png';
        file_put_contents($path, base64_decode(self::PNG, true));
        $this->picker->reply = fn (): object => (object) ['status' => 'succeeded', 'success' => true, 'cancelled' => false,
            'path' => $path, 'originalName' => 'PRIVATE_ORIGINAL.png', 'mimeType' => 'image/png', 'size' => filesize($path)];
        $response = $this->getJson(route('native-media-optimizer.pick-status', ['id' => self::ID]))->assertOk()->assertJsonPath('selection.id', self::ID)->assertJsonPath('terminal', true);
        self::assertStringNotContainsString($path, $response->getContent()); self::assertStringNotContainsString('PRIVATE_ORIGINAL', $response->getContent());
        self::assertSame(['id', 'mime_type', 'size'], array_keys($this->app->make('session.store')->get(State::KEY)['selection']));
    }

    public function test_picker_rejects_a_foreign_file_even_if_native_metadata_claims_success(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->temporary.'/app/native-document-picker/'.self::OTHER.'.png';
        file_put_contents($path, base64_decode(self::PNG, true));
        $this->picker->reply = fn (): object => (object) ['status' => 'succeeded', 'success' => true, 'cancelled' => false,
            'path' => $path, 'originalName' => 'file.png', 'mimeType' => 'image/png', 'size' => filesize($path)];
        $this->getJson(route('native-media-optimizer.pick-status', ['id' => self::ID]))->assertStatus(422)->assertJsonPath('errorCode', ErrorCode::SOURCE_UNAVAILABLE);
    }

    public function test_picker_cancellation_is_terminal_and_preserves_the_prior_selection(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->picker->reply = fn (): object => (object) ['status' => 'cancelled', 'success' => false, 'cancelled' => true];
        $this->getJson(route('native-media-optimizer.pick-status', ['id' => self::ID]))->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('terminal', true);
        self::assertSame(self::SOURCE, $this->app->make('session.store')->get(State::KEY)['selection']['id']);
    }

    public static function startOperations(): array { return ['inspect' => ['InspectMedia', 'image/png', []], 'image' => ['OptimizeImage', 'image/png', ['max_width' => 32, 'max_height' => 16]], 'video' => ['OptimizeVideo', 'video/mp4', ['start_ms' => 100, 'end_ms' => 1000, 'remove_audio' => true]], 'thumbnail' => ['GenerateThumbnail', 'video/mp4', ['timestamp_ms' => 100, 'format' => 'png']]]; }

    #[DataProvider('startOperations')]
    public function test_start_routes_validated_settings_to_the_right_native_operation(string $operation, string $mime, array $options): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState($mime)]);
        $response = $this->postJson(route('native-media-optimizer.start'), ['operation' => $operation, 'source_document_id' => self::SOURCE, 'options' => $options])->assertStatus(202)->assertJsonPath('accepted', true)->assertJsonPath('operation', $operation);
        $parameters = $this->bridge->calls[0][1];
        self::assertSame('NativeMediaOptimizer.'.$operation, $this->bridge->calls[0][0]);
        self::assertSame(self::SOURCE, $parameters['source_document_id']); self::assertSame($response->json('id'), $parameters['id']);
        foreach ($options as $key => $value) { self::assertSame($value, $parameters[$key]); }
    }

    public function test_job_ownership_is_saved_before_native_acceptance(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->bridge->reply = function (string $method, array $parameters): object {
            self::assertArrayHasKey($parameters['id'], $this->app->make('session.store')->get(State::KEY)['jobs']);
            return RecordingNativeMediaOptimizerHttpBridge::record($parameters['id'], 'InspectMedia', $parameters['source_document_id']);
        };
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE])->assertStatus(202);
    }

    public function test_an_uncertain_acknowledgement_can_be_recovered_by_its_owned_id(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->bridge->reply = function (string $method, array $parameters): object {
            $this->bridge->records[$parameters['id']] = RecordingNativeMediaOptimizerHttpBridge::record($parameters['id'], 'InspectMedia', self::SOURCE);
            throw new RuntimeException('PRIVATE_NATIVE_EXCEPTION');
        };
        $response = $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE])->assertStatus(503)->assertJsonPath('retryable', true);
        self::assertStringNotContainsString('PRIVATE_NATIVE_EXCEPTION', $response->getContent());
        $this->bridge->reply = null;
        $this->getJson(route('native-media-optimizer.status', ['id' => $response->json('id')]))->assertOk()->assertJsonPath('status', 'pending');
    }

    public static function invalidSettings(): array
    {
        return [
            'string dimension' => ['OptimizeImage', ['max_width' => '320'], ErrorCode::INVALID_DIMENSIONS],
            'zero dimension' => ['OptimizeImage', ['max_width' => 0], ErrorCode::INVALID_DIMENSIONS],
            'wrong format' => ['OptimizeImage', ['format' => 'gif'], ErrorCode::INVALID_FORMAT],
            'PNG quality' => ['OptimizeImage', ['format' => 'png', 'quality' => 80], ErrorCode::INVALID_QUALITY],
            'odd video dimension' => ['OptimizeVideo', ['max_width' => 63], ErrorCode::INVALID_DIMENSIONS],
            'large audio bitrate' => ['OptimizeVideo', ['audio_bitrate' => 320001], ErrorCode::INVALID_BITRATE],
            'empty end' => ['OptimizeVideo', ['end_ms' => null], ErrorCode::INVALID_TIME_RANGE],
            'invalid audio flag' => ['OptimizeVideo', ['remove_audio' => 'false'], ErrorCode::INVALID_OPTIONS],
            'arbitrary path' => ['OptimizeImage', ['path' => '/PRIVATE_PATH'], ErrorCode::INVALID_OPTIONS],
            'caller ID' => ['OptimizeImage', ['id' => self::OTHER], ErrorCode::INVALID_OPTIONS],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_never_reach_native_code(string $operation, array $options, string $error): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState(in_array($operation, ['OptimizeVideo', 'GenerateThumbnail'], true) ? 'video/mp4' : 'image/png')]);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => $operation, 'source_document_id' => self::SOURCE, 'options' => $options])->assertStatus(422)->assertJsonPath('errorCode', $error);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_unknown_outer_keys_and_large_requests_are_rejected(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE, 'path' => 'PRIVATE_PATH'])->assertStatus(422);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE, 'options' => ['large' => str_repeat('x', 9000)]])->assertStatus(413);
        self::assertSame([], $this->bridge->calls);
    }

    public static function ownedRoutes(): array
    {
        return array_filter(self::protectedRoutes(), static fn (array $route): bool => isset($route[2]['id']));
    }

    #[DataProvider('ownedRoutes')]
    public function test_unowned_ids_cannot_reach_native_or_share_code(string $method, string $route, array $parameters): void
    {
        $this->unlock(); Share::shouldReceive('file')->never();
        $this->json($method, route('native-media-optimizer.'.$route, $parameters))->assertForbidden();
        self::assertSame([], $this->bridge->calls); self::assertSame([], $this->picker->calls);
    }

    public function test_another_document_id_cannot_start_a_job_in_the_same_session(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::OTHER])->assertForbidden();
        self::assertSame([], $this->bridge->calls);
    }

    public static function mismatchedMedia(): array
    {
        return [['OptimizeImage', 'video/mp4'], ['OptimizeVideo', 'image/png'], ['GenerateThumbnail', 'image/png']];
    }

    #[DataProvider('mismatchedMedia')]
    public function test_operation_media_mismatches_are_rejected_before_native_calls(string $operation, string $mime): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState($mime)]);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => $operation, 'source_document_id' => self::SOURCE])
            ->assertStatus(422)->assertJsonPath('errorCode', ErrorCode::UNSUPPORTED_MEDIA);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_malformed_session_history_grants_no_job_access(): void
    {
        $this->unlock();
        $this->withSession([State::KEY => ['owner' => '42', 'picks' => 'bad', 'jobs' => [self::ID => ['operation' => 'OptimizeImage', 'source_document_id' => self::SOURCE, 'terminal' => 'false']], 'selection' => ['id' => self::SOURCE, 'mime_type' => 'image/png', 'size' => '70']]]);
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertForbidden();
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE])->assertForbidden();
        self::assertSame([], $this->bridge->calls);
    }

    public function test_switching_user_does_not_inherit_job_or_selection_ownership(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]); $this->signIn(43);
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertForbidden();
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE])->assertForbidden();
        self::assertSame([], $this->bridge->calls);
    }

    public function test_status_and_cancellation_preserve_job_binding_and_redact_output_paths(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->bridge->records[self::ID] = RecordingNativeMediaOptimizerHttpBridge::record(self::ID, 'OptimizeImage', self::SOURCE);
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertOk()->assertJsonPath('terminal', false);
        $this->postJson(route('native-media-optimizer.cancel', ['id' => self::ID]))->assertOk()->assertJsonPath('status', 'cancelling');
        self::assertSame('NativeMediaOptimizer.Cancel', $this->bridge->calls[1][0]);
    }

    public function test_cross_bound_native_results_are_rejected_without_exposing_foreign_metadata(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->bridge->records[self::ID] = RecordingNativeMediaOptimizerHttpBridge::record(self::ID, 'OptimizeImage', self::OTHER);
        $response = $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertStatus(503)->assertJsonPath('errorCode', ErrorCode::INVALID_NATIVE_RESPONSE);
        self::assertStringNotContainsString(self::OTHER, $response->getContent());
    }

    public function test_verified_image_preview_is_byte_exact_and_sharing_uses_only_the_guarded_path(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->completedImage();
        $status = $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertOk()->assertJsonPath('outputAvailable', true);
        self::assertArrayNotHasKey('path', $status->json('output')); self::assertStringNotContainsString($path, $status->getContent());
        $preview = $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertOk()->assertJsonPath('mime_type', 'image/png');
        self::assertSame(file_get_contents($path), base64_decode($preview->json('base64'), true));
        self::assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
        Share::shouldReceive('file')->once()->with('Optimized media', 'Media processed in Native Blog', realpath($path));
        $this->postJson(route('native-media-optimizer.share', ['id' => self::ID]))->assertOk()->assertJsonPath('submitted', true);
    }

    public function test_canonical_native_output_recovers_status_and_preview_with_an_aliased_storage_root(): void
    {
        $this->app->useStoragePath($this->temporary.'/.');
        $this->app->instance(NativeMediaOptimizer::class, new NativeMediaOptimizer($this->bridge, $this->temporary.'/./app/native-media-optimizer'));
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->completedImage();
        $status = $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertOk()
            ->assertJsonPath('status', 'succeeded')->assertJsonPath('terminal', true)->assertJsonPath('outputAvailable', true);
        self::assertArrayNotHasKey('path', $status->json('output'));
        self::assertStringNotContainsString(realpath($path), $status->getContent());
        $preview = $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertOk();
        self::assertSame(file_get_contents($path), base64_decode($preview->json('base64'), true));
        Share::shouldReceive('file')->once()->with('Optimized media', 'Media processed in Native Blog', realpath($path));
        $this->postJson(route('native-media-optimizer.share', ['id' => self::ID]))->assertOk()->assertJsonPath('submitted', true);
        $this->postJson(route('native-media-optimizer.delete', ['id' => self::ID]))->assertOk()->assertJsonPath('deleted', true);
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertOk()
            ->assertJsonPath('status', 'succeeded')->assertJsonPath('outputAvailable', false);
    }

    public function test_changed_missing_and_mistyped_image_outputs_cannot_be_previewed_or_shared(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->completedImage();
        file_put_contents($path, 'changed');
        Share::shouldReceive('file')->never();
        $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertNotFound();
        $this->postJson(route('native-media-optimizer.share', ['id' => self::ID]))->assertNotFound();
        unlink($path);
        $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertNotFound();
        $this->completedImage(); $this->bridge->records[self::ID]->output->width = 2;
        $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertNotFound();
    }

    public function test_preview_rejects_a_same_size_replacement_after_verification(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->completedImage();
        $guard = $this->app->make(FileGuard::class);
        $result = $this->app->make(NativeMediaOptimizer::class)->getResult(self::ID);
        $verified = $guard->output($result);
        self::assertNotNull($verified);
        rename($path, $path.'.old');
        file_put_contents($path, base64_decode(self::PNG, true));
        clearstatcache(true, $path);
        // Some Windows filesystems report no inode identity. Size changes remain guarded.
        if ($verified['file_stat']['ino'] === 0 && stat($path)['ino'] === 0) {
            $this->markTestSkipped('This filesystem does not expose inode identity.');
        }
        self::assertNull($guard->preview($verified));
    }

    public function test_output_links_to_other_files_are_rejected(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->completedImage(); $foreign = $path.'.foreign';
        rename($path, $foreign);
        if (! @symlink($foreign, $path)) { $this->markTestSkipped('Creating symlinks requires permission on this machine.'); }
        clearstatcache(true, $path);
        Share::shouldReceive('file')->never();
        $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertNotFound();
        $this->postJson(route('native-media-optimizer.share', ['id' => self::ID]))->assertNotFound();
    }

    public function test_video_preview_is_bounded_and_does_not_use_a_raw_binary_http_response(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState('video/mp4', 'OptimizeVideo')]);
        $path = $this->temporary.'/app/native-media-optimizer/'.self::ID.'.mp4';
        $bytes = base64_decode(self::VIDEO_FIXTURE, true); file_put_contents($path, $bytes);
        $output = (object) ['id' => self::ID, 'path' => $path, 'mime_type' => 'video/mp4', 'size' => strlen($bytes), 'width' => 160, 'height' => 96, 'duration_ms' => 3000, 'rotation_degrees' => 0, 'has_audio' => false];
        $this->bridge->records[self::ID] = RecordingNativeMediaOptimizerHttpBridge::record(self::ID, 'OptimizeVideo', self::SOURCE, 'succeeded', $output);
        $preview = $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertOk()->assertJsonPath('mime_type', 'video/mp4');
        self::assertSame($bytes, base64_decode($preview->json('base64'), true));
        $handle = fopen($path, 'c+b'); ftruncate($handle, FileGuard::MAX_PREVIEW_BYTES + 1); fclose($handle);
        $output->size = FileGuard::MAX_PREVIEW_BYTES + 1;
        $this->getJson(route('native-media-optimizer.preview', ['id' => self::ID]))->assertStatus(413)->assertJsonPath('errorCode', ErrorCode::LIMIT_EXCEEDED);
        Share::shouldReceive('file')->once()->with('Optimized media', 'Media processed in Native Blog', realpath($path));
        $this->postJson(route('native-media-optimizer.share', ['id' => self::ID]))->assertOk();
    }

    public function test_deletion_uses_native_owned_cleanup_and_keeps_terminal_metadata(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $path = $this->completedImage();
        $original = $this->temporary.'/app/native-document-picker/'.self::SOURCE.'.png'; file_put_contents($original, base64_decode(self::PNG, true));
        $this->postJson(route('native-media-optimizer.delete', ['id' => self::ID]))->assertOk()->assertJsonPath('deleted', true);
        self::assertFileDoesNotExist($path); self::assertFileExists($original);
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('outputAvailable', false);
    }

    public function test_active_job_outputs_cannot_be_deleted(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->bridge->records[self::ID] = RecordingNativeMediaOptimizerHttpBridge::record(self::ID, 'OptimizeImage', self::SOURCE);
        $this->postJson(route('native-media-optimizer.delete', ['id' => self::ID]))->assertStatus(409)->assertJsonPath('errorCode', ErrorCode::OUTPUT_IN_USE);
        self::assertSame([['NativeMediaOptimizer.GetResult', ['id' => self::ID]]], $this->bridge->calls);
    }

    public function test_process_interruption_is_reported_as_terminal(): void
    {
        $this->unlock(); $this->withSession([State::KEY => $this->ownedState()]);
        $this->bridge->records[self::ID] = RecordingNativeMediaOptimizerHttpBridge::record(self::ID, 'OptimizeImage', self::SOURCE, 'interrupted');
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertOk()->assertJsonPath('status', 'interrupted')->assertJsonPath('terminal', true)->assertJsonPath('errorCode', ErrorCode::PROCESS_INTERRUPTED);
    }

    public function test_full_history_does_not_evict_an_active_request(): void
    {
        $this->unlock(); $data = $this->ownedState(); $data['jobs'] = [];
        for ($i = 0; $i < 50; $i++) { $data['jobs'][(string) Str::uuid()] = ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE, 'terminal' => false]; }
        $this->withSession([State::KEY => $data]);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE])->assertStatus(429)->assertJsonPath('errorCode', ErrorCode::LIMIT_EXCEEDED);
        self::assertSame([], $this->bridge->calls);
    }

    public function test_full_legacy_history_preserves_access_to_completed_outputs(): void
    {
        $this->unlock(); $data = $this->ownedState();
        $data['jobs'][self::ID]['terminal'] = true;
        for ($i = 0; $i < 49; $i++) {
            $data['jobs'][(string) Str::uuid()] = ['operation' => 'OptimizeImage', 'source_document_id' => self::SOURCE, 'terminal' => true];
        }
        $this->completedImage(); $this->withSession([State::KEY => $data]);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE])
            ->assertStatus(429)->assertJsonPath('errorCode', ErrorCode::LIMIT_EXCEEDED);
        self::assertSame([], $this->bridge->calls);
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))
            ->assertOk()->assertJsonPath('outputAvailable', true)
            ->assertSessionHas(State::KEY.'.jobs.'.self::ID.'.output_available', true);
    }

    public function test_deleting_an_output_frees_a_full_history_slot(): void
    {
        $this->unlock(); $data = $this->ownedState();
        $data['jobs'][self::ID]['terminal'] = true;
        $data['jobs'][self::ID]['output_available'] = true;
        for ($i = 0; $i < 49; $i++) {
            $data['jobs'][(string) Str::uuid()] = ['operation' => 'OptimizeImage', 'source_document_id' => self::SOURCE,
                'terminal' => true, 'output_available' => true];
        }
        $this->completedImage(); $this->withSession([State::KEY => $data]);
        $this->postJson(route('native-media-optimizer.delete', ['id' => self::ID]))->assertOk()->assertJsonPath('deleted', true)
            ->assertSessionHas(State::KEY.'.jobs.'.self::ID.'.output_available', false);
        $response = $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE]);
        $response->assertStatus(202)->assertSessionMissing(State::KEY.'.jobs.'.self::ID);
        $saved = session(State::KEY);
        self::assertCount(50, $saved['jobs']);
        self::assertArrayHasKey($response->json('id'), $saved['jobs']);
    }

    public function test_reconciling_a_missing_output_frees_a_full_history_slot(): void
    {
        $this->unlock(); $data = $this->ownedState();
        $data['jobs'][self::ID]['terminal'] = true;
        for ($i = 0; $i < 49; $i++) {
            $data['jobs'][(string) Str::uuid()] = ['operation' => 'OptimizeImage', 'source_document_id' => self::SOURCE, 'terminal' => true];
        }
        $this->completedImage(); $this->bridge->records[self::ID]->outputAvailable = false;
        $this->withSession([State::KEY => $data]);
        $this->getJson(route('native-media-optimizer.status', ['id' => self::ID]))->assertOk()
            ->assertJsonPath('outputAvailable', false)->assertSessionHas(State::KEY.'.jobs.'.self::ID.'.output_available', false);
        $this->postJson(route('native-media-optimizer.start'), ['operation' => 'InspectMedia', 'source_document_id' => self::SOURCE])
            ->assertStatus(202)->assertSessionMissing(State::KEY.'.jobs.'.self::ID);
    }

    private function ownedState(string $mime = 'image/png', string $operation = 'OptimizeImage'): array
    {
        return ['owner' => '42', 'picks' => [self::ID => ['kind' => 'image', 'terminal' => false]],
            'jobs' => [self::ID => ['operation' => $operation, 'source_document_id' => self::SOURCE, 'terminal' => false]],
            'selection' => ['id' => self::SOURCE, 'mime_type' => $mime, 'size' => 70]];
    }

    private function completedImage(): string
    {
        $path = $this->temporary.'/app/native-media-optimizer/'.self::ID.'.png';
        $bytes = base64_decode(self::PNG, true); file_put_contents($path, $bytes);
        $output = (object) ['id' => self::ID, 'path' => realpath($path), 'mime_type' => 'image/png', 'size' => strlen($bytes), 'width' => 1, 'height' => 1, 'duration_ms' => null, 'rotation_degrees' => 0, 'has_audio' => false];
        $this->bridge->records[self::ID] = RecordingNativeMediaOptimizerHttpBridge::record(self::ID, 'OptimizeImage', self::SOURCE, 'succeeded', $output);
        return $path;
    }

    private function signIn(int $id = 42): void
    {
        $user = new User; $user->forceFill(['id' => $id, 'name' => 'Media HTTP Test User', 'email' => 'media.http@example.invalid']); $this->actingAs($user);
    }
    private function unlock(): void { $this->signIn(); $this->withSession(['device_unlocked' => true]); }

    private const VIDEO_FIXTURE = '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AAq5ScnJycnJycnJ111111111111111111111111111111111111111111111111114AAAAGQZogOoHsAAAABkGaQD6B7AAAAAZBmmA+gewAAAAGQZqAPoHsAAAABkGaoD6B7AAAAJpBmsA2vCkUAAQf4oAAg/xQABB/igACD/FAAEH+KAAIP8UAAQf4oAAg/xQABB/igACD/FAAEH+KAAIP8UAAQf4oAAg/xQABB/igACD/HAAFIqOAAJ9uJ8T4nxPifE+J8T4nz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fz+fwAAAABkGa4DqB7AAAAAZBmwA+gewAAAAGQZsgPoHsAAAABkGbQD6B7AAAAAZBm2A+gewAAABCZYiEBGhGKAAMSMcAAVco4AAhgycnJycnJycnJ111111111111111111111111111111111111111111111111114AAAABkGaIDqB7AAAAAZBmkA+gewAAAAGQZpgPoHsAAAABkGagD6B7AAAAAZBmqA+gewAAAAGQZrAPoHsAAAABkGa4D6B7AAAAAZBmwA+gewAAAAGQZsgPoHsAAAABkGbQD6B7AAAAAZBm2A+gew=';
}
