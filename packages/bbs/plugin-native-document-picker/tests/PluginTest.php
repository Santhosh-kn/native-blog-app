<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker\Tests;

use Bbs\NativeDocumentPicker\Contracts\NativeBridge;
use Bbs\NativeDocumentPicker\Events\NativeDocumentPickerCompleted;
use Bbs\NativeDocumentPicker\Facades\NativeDocumentPicker as PickerFacade;
use Bbs\NativeDocumentPicker\NativeDocumentPicker;
use Bbs\NativeDocumentPicker\NativeDocumentPickerServiceProvider;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerErrorCode;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginTest extends TestCase
{
    private const REQUEST_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const PRIVATE_PATH =
        '/data/user/0/com.example/storage/app/native-document-picker';

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_composer_configuration_matches_the_contract(): void
    {
        $composer = $this->readJson(dirname(__DIR__).'/composer.json');

        self::assertSame('bbs/plugin-native-document-picker', $composer['name']);
        self::assertSame('1.0.0', $composer['version']);
        self::assertSame('nativephp-plugin', $composer['type']);
        self::assertSame('^8.4', $composer['require']['php']);
        self::assertSame('^4.2', $composer['require']['nativephp/mobile']);
        self::assertSame('src/', $composer['autoload']['psr-4']['Bbs\\NativeDocumentPicker\\']);
        self::assertSame('phpunit --bootstrap tests/bootstrap.php tests/PluginTest.php', $composer['scripts']['test']);
    }

    public function test_manifest_declares_the_android_only_contract(): void
    {
        $manifest = $this->readJson(dirname(__DIR__).'/nativephp.json');

        self::assertSame('bbs/plugin-native-document-picker', $manifest['name']);
        self::assertSame('NativeDocumentPicker', $manifest['namespace']);
        self::assertSame(['android'], $manifest['platforms']);
        self::assertSame([], $manifest['android']['permissions']);
        self::assertSame('15.0', $manifest['ios']['min_version']);
        self::assertArrayNotHasKey('hooks', $manifest);
        self::assertSame(['NativeDocumentPicker.Pick', 'NativeDocumentPicker.GetStatus'], array_column($manifest['bridge_functions'], 'name'));
        self::assertSame([
            'com.bbs.plugins.native_document_picker.'.'NativeDocumentPickerFunctions.Pick',
            'com.bbs.plugins.native_document_picker.'.'NativeDocumentPickerFunctions.GetStatus',
        ], array_column($manifest['bridge_functions'], 'android'));

        foreach ($manifest['bridge_functions'] as $function) {
            self::assertArrayNotHasKey('ios', $function);
        }

        self::assertSame([NativeDocumentPickerCompleted::class], $manifest['events']);
        self::assertSame(NativeDocumentPickerServiceProvider::class, $manifest['service_provider']);
    }

    public function test_android_source_set_is_complete_and_android_only(): void
    {
        $androidDirectory = dirname(__DIR__).'/resources/android';
        $files = array_values(array_filter(scandir($androidDirectory) ?: [], static fn (string $file): bool => str_ends_with($file, '.kt')));

        sort($files);

        self::assertSame([
            'NativeDocumentPickerContract.kt',
            'NativeDocumentPickerCoordinator.kt',
            'NativeDocumentPickerCopier.kt',
            'NativeDocumentPickerEventDispatcher.kt',
            'NativeDocumentPickerFilePolicy.kt',
            'NativeDocumentPickerFunctions.kt',
            'NativeDocumentPickerRequest.kt',
            'NativeDocumentPickerResult.kt',
            'NativeDocumentPickerStore.kt',
        ], $files);
        self::assertFalse(is_dir(dirname(__DIR__).'/resources/ios'));
        self::assertFalse(is_dir(dirname(__DIR__).'/resources/js'));
        self::assertFalse(is_dir(dirname(__DIR__).'/src/Commands'));
    }

    public function test_android_bridge_dispatches_only_safe_metadata(): void
    {
        $functions = $this->readPluginFile('resources/android/NativeDocumentPickerFunctions.kt');
        $dispatcher = $this->readPluginFile('resources/android/NativeDocumentPickerEventDispatcher.kt');
        $bridgeSources = $functions."\n".$dispatcher;

        self::assertStringContainsString('class Pick(', $functions);
        self::assertStringContainsString('class GetStatus(', $functions);
        self::assertStringContainsString('NativeDocumentPickerCoordinator.install', $functions);
        self::assertStringContainsString('NativeDocumentPickerEventDispatcher.dispatch', $functions);
        self::assertStringContainsString('NativeElementBridge.sendNativeEvent', $dispatcher);
        self::assertStringContainsString('getWebViewOrNull()', $dispatcher);
        self::assertStringContainsString('MAX_PAYLOAD_CHARACTERS', $dispatcher);

        foreach ([
            'NativeActionCoordinator',
            'android.util.Log',
            'Log.',
            'Base64',
            'ByteArray',
            'InputStream',
            'openInputStream',
            'openFileDescriptor',
            'content://',
            'takePersistableUriPermission',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString($forbiddenToken, $bridgeSources);
        }
    }

    public function test_android_copy_is_bounded_and_private(): void
    {
        $policy = $this->readPluginFile('resources/android/NativeDocumentPickerFilePolicy.kt');
        $copier = $this->readPluginFile('resources/android/NativeDocumentPickerCopier.kt');
        $coordinator = $this->readPluginFile('resources/android/NativeDocumentPickerCoordinator.kt');
        $store = $this->readPluginFile('resources/android/NativeDocumentPickerStore.kt');

        self::assertStringContainsString('context.applicationInfo.dataDir', $policy);
        self::assertStringContainsString('.canonicalFile', $policy);
        self::assertStringContainsString('isStrictlyInside(applicationRoot, requestedDirectory)', $policy);
        self::assertStringContainsString('safeOriginalName(', $policy);
        self::assertStringContainsString('metadata.declaredSize > request.maxSize', $copier);
        self::assertStringContainsString('maximumSize - count.toLong()', $copier);
        self::assertStringContainsString('val buffer = ByteArray(BUFFER_SIZE)', $copier);
        self::assertStringContainsString('const val BUFFER_SIZE = 64 * 1024', $copier);
        self::assertStringContainsString('destination.fd.sync()', $copier);
        self::assertStringContainsString('partialFile.renameTo(finalFile)', $copier);
        self::assertStringContainsString('ActivityResultContracts.OpenDocument()', $coordinator);
        self::assertStringContainsString('Executors.newSingleThreadExecutor()', $coordinator);
        self::assertStringContainsString('store.complete(result)', $coordinator);
        self::assertStringContainsString('.commit()', $store);
    }

    public function test_android_sources_do_not_log_or_request_broad_storage(): void
    {
        $androidSources = implode("\n", array_map(
            fn (string $file): string => $this->readPluginFile('resources/android/'.$file),
            [
                'NativeDocumentPickerContract.kt',
                'NativeDocumentPickerCoordinator.kt',
                'NativeDocumentPickerCopier.kt',
                'NativeDocumentPickerEventDispatcher.kt',
                'NativeDocumentPickerFilePolicy.kt',
                'NativeDocumentPickerFunctions.kt',
                'NativeDocumentPickerRequest.kt',
                'NativeDocumentPickerResult.kt',
                'NativeDocumentPickerStore.kt',
            ],
        ));

        foreach ([
            'android.util.Log',
            'Log.',
            'READ_EXTERNAL_STORAGE',
            'WRITE_EXTERNAL_STORAGE',
            'MANAGE_EXTERNAL_STORAGE',
            'takePersistableUriPermission',
            'Base64',
            'uri.toString()',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString($forbiddenToken, $androidSources);
        }
    }

    public function test_scaffold_runtime_files_are_absent(): void
    {
        $provider = $this->readPluginFile('src/NativeDocumentPickerServiceProvider.php');

        self::assertStringNotContainsString('CopyAssetsCommand', $provider);
        self::assertStringNotContainsString('function boot', $provider);
        self::assertFileDoesNotExist(dirname(__DIR__).'/src/Commands/CopyAssetsCommand.php');
        self::assertFileDoesNotExist(dirname(__DIR__).'/resources/ios/NativeDocumentPickerFunctions.swift');
        self::assertFileDoesNotExist(dirname(__DIR__).'/resources/js/nativeDocumentPicker.js');
    }

    public function test_pick_forwards_only_normalized_safe_parameters(): void
    {
        $bridge = new FakeNativeBridge((object) ['accepted' => true]);
        $picker = $this->picker($bridge);
        $result = $picker->pick([
            'id' => strtoupper(self::REQUEST_ID),
            'mime_types' => [
                ' APPLICATION/PDF ',
                'text/plain',
                'application/pdf',
            ],
            'max_size' => 1_048_576,
            'document_bytes' => 'must-not-cross-the-bridge',
        ]);

        self::assertTrue($result->accepted);
        self::assertSame(self::REQUEST_ID, $result->id);
        self::assertSame('pending', $result->status);
        self::assertSame([
            [
                'method' => 'NativeDocumentPicker.Pick',
                'parameters' => [
                    'id' => self::REQUEST_ID,
                    'mime_types' => ['application/pdf', 'text/plain'],
                    'max_size' => 1_048_576,
                    'destination_path' => self::PRIVATE_PATH,
                ],
            ],
        ], $bridge->calls);
    }

    public function test_pick_uses_secure_defaults_and_generates_a_uuid(): void
    {
        $bridge = new FakeNativeBridge((object) ['accepted' => true]);
        $result = $this->picker($bridge)->pick();
        $parameters = $bridge->calls[0]['parameters'];

        self::assertTrue(Str::isUuid($result->id));
        self::assertSame($result->id, $parameters['id']);
        self::assertSame(NativeDocumentPicker::DEFAULT_MIME_TYPES, $parameters['mime_types']);
        self::assertSame(NativeDocumentPicker::DEFAULT_MAX_SIZE, $parameters['max_size']);
    }

    public function test_invalid_options_never_reach_the_bridge(): void
    {
        $cases = [
            [['id' => 'not-a-uuid'], NativeDocumentPickerErrorCode::INVALID_REQUEST_ID],
            [['mime_types' => []], NativeDocumentPickerErrorCode::INVALID_MIME_TYPES],
            [['mime_types' => 'application/pdf'], NativeDocumentPickerErrorCode::INVALID_MIME_TYPES],
            [['mime_types' => ['application/pdf; charset=utf-8']], NativeDocumentPickerErrorCode::INVALID_MIME_TYPES],
            [['max_size' => 0], NativeDocumentPickerErrorCode::INVALID_MAX_SIZE],
            [['max_size' => '20971520'], NativeDocumentPickerErrorCode::INVALID_MAX_SIZE],
            [['max_size' => NativeDocumentPicker::MAX_CONFIGURABLE_SIZE + 1], NativeDocumentPickerErrorCode::INVALID_MAX_SIZE],
        ];

        foreach ($cases as [$options, $expectedCode]) {
            $bridge = new FakeNativeBridge((object) ['accepted' => true]);
            $result = $this->picker($bridge)->pick($options);

            self::assertFalse($result->accepted);
            self::assertSame($expectedCode, $result->errorCode);
            self::assertSame([], $bridge->calls);
        }
    }

    public function test_pick_failures_expose_only_controlled_messages(): void
    {
        $unavailable = $this->picker(new FakeNativeBridge(null))->pick();

        self::assertFalse($unavailable->accepted);
        self::assertSame(NativeDocumentPickerErrorCode::ACTIVITY_UNAVAILABLE, $unavailable->errorCode);

        $bridge = new FakeNativeBridge((object) [
            'accepted' => false,
            'errorCode' => NativeDocumentPickerErrorCode::PICKER_BUSY,
            'errorMessage' => '/private/path/should/not/be/returned',
        ]);
        $rejected = $this->picker($bridge)->pick();

        self::assertSame(NativeDocumentPickerErrorCode::PICKER_BUSY, $rejected->errorCode);
        self::assertSame(NativeDocumentPickerErrorCode::message(NativeDocumentPickerErrorCode::PICKER_BUSY), $rejected->errorMessage);
        self::assertStringNotContainsString('/private/path', $rejected->errorMessage);
    }

    public function test_status_normalizes_safe_success_metadata(): void
    {
        $path = self::PRIVATE_PATH.'/document with spaces.pdf';
        $bridge = new FakeNativeBridge((object) [
            'status' => 'completed',
            'success' => true,
            'cancelled' => false,
            'path' => $path,
            'originalName' => "  Invoice\r\n  1001.pdf  ",
            'mimeType' => 'APPLICATION/PDF',
            'size' => '4096',
        ]);
        $result = $this->picker($bridge)->getStatus(self::REQUEST_ID);

        self::assertSame('succeeded', $result->status);
        self::assertTrue($result->success);
        self::assertSame($path, $result->path);
        self::assertSame('Invoice 1001.pdf', $result->originalName);
        self::assertSame('application/pdf', $result->mimeType);
        self::assertSame(4096, $result->size);
        self::assertSame([
            ['method' => 'NativeDocumentPicker.GetStatus', 'parameters' => ['id' => self::REQUEST_ID]],
        ], $bridge->calls);
    }

    public function test_status_handles_pending_cancelled_and_missing_results(): void
    {
        $pending = $this->picker(
            new FakeNativeBridge((object) ['status' => 'pending']),
        )->getStatus(self::REQUEST_ID);

        self::assertSame('pending', $pending->status);

        $cancelled = $this->picker(new FakeNativeBridge((object) [
            'success' => false,
            'cancelled' => true,
        ]))->getStatus(self::REQUEST_ID);

        self::assertSame('cancelled', $cancelled->status);
        self::assertTrue($cancelled->cancelled);
        self::assertNull($cancelled->path);

        $missing = $this->picker(new FakeNativeBridge((object) [
            'success' => false,
            'cancelled' => false,
            'errorCode' => NativeDocumentPickerErrorCode::RESULT_NOT_FOUND,
        ]))->getStatus(self::REQUEST_ID);

        self::assertSame('not_found', $missing->status);
        self::assertSame(NativeDocumentPickerErrorCode::RESULT_NOT_FOUND, $missing->errorCode);
    }

    public function test_malformed_success_metadata_is_rejected(): void
    {
        $result = $this->picker(new FakeNativeBridge((object) [
            'success' => true,
            'cancelled' => false,
            'path' => null,
            'originalName' => 'document.pdf',
            'mimeType' => 'application/pdf',
            'size' => 100,
        ]))->getStatus(self::REQUEST_ID);

        self::assertFalse($result->success);
        self::assertSame('failed', $result->status);
        self::assertNull($result->path);
        self::assertSame(NativeDocumentPickerErrorCode::UNKNOWN_ERROR, $result->errorCode);
    }

    public function test_provider_and_facade_resolve_the_same_singleton(): void
    {
        $application = new Application(dirname(__DIR__, 4));
        $provider = new NativeDocumentPickerServiceProvider($application);
        $provider->register();
        $resolved = $application->make(NativeDocumentPicker::class);

        self::assertSame($resolved, $application->make(NativeDocumentPicker::class));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);

        self::assertSame($resolved, PickerFacade::getFacadeRoot());
    }

    public function test_completion_event_has_exact_fields_and_safe_results(): void
    {
        $reflection = new ReflectionClass(NativeDocumentPickerCompleted::class);
        $expectedTypes = [
            'id' => 'string',
            'success' => 'bool',
            'cancelled' => 'bool',
            'path' => '?string',
            'originalName' => '?string',
            'mimeType' => '?string',
            'size' => '?int',
            'errorCode' => '?string',
            'errorMessage' => '?string',
        ];

        foreach ($expectedTypes as $name => $type) {
            $property = $reflection->getProperty($name);

            self::assertTrue($property->isPublic());
            self::assertSame($type, (string) $property->getType());
        }

        $event = new NativeDocumentPickerCompleted(
            id: self::REQUEST_ID,
            success: false,
            cancelled: false,
            path: '/partial/private/path',
            originalName: 'partial.pdf',
            mimeType: 'application/pdf',
            size: 10,
            errorCode: NativeDocumentPickerErrorCode::COPY_FAILED,
            errorMessage: '/private/path/from/exception',
        );
        $result = $event->result();

        self::assertInstanceOf(NativeDocumentPickerResult::class, $result);
        self::assertFalse($result->success);
        self::assertNull($result->path);
        self::assertSame(
            NativeDocumentPickerErrorCode::message(NativeDocumentPickerErrorCode::COPY_FAILED),
            $result->errorMessage,
        );
        self::assertStringNotContainsString('/private/path', $result->errorMessage);
    }

    public function test_error_codes_remain_stable(): void
    {
        self::assertSame([
            'INVALID_REQUEST_ID',
            'INVALID_MIME_TYPES',
            'INVALID_MAX_SIZE',
            'ACTIVITY_UNAVAILABLE',
            'PICKER_UNAVAILABLE',
            'PICKER_BUSY',
            'PICKER_LAUNCH_FAILED',
            'UNSUPPORTED_MIME_TYPE',
            'FILE_TOO_LARGE',
            'SOURCE_UNREADABLE',
            'INVALID_DESTINATION',
            'PRIVATE_STORAGE_FAILED',
            'COPY_FAILED',
            'RESULT_NOT_FOUND',
            'RESULT_PERSISTENCE_FAILED',
            'UNKNOWN_ERROR',
        ], NativeDocumentPickerErrorCode::values());
    }

    private function picker(FakeNativeBridge $bridge): NativeDocumentPicker
    {
        return new NativeDocumentPicker($bridge, self::PRIVATE_PATH);
    }

    private function readPluginFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__).'/'.$relativePath);

        self::assertNotFalse($contents);

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);

        self::assertNotFalse($contents);

        $decoded = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }
}

final class FakeNativeBridge implements NativeBridge
{
    /**
     * @var list<array{
     *     method: string,
     *     parameters: array<string, mixed>
     * }>
     */
    public array $calls = [];

    public function __construct(public ?object $response) {}

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = ['method' => $method, 'parameters' => $parameters];

        return $this->response;
    }
}
