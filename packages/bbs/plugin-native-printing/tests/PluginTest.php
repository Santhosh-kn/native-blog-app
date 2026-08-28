<?php

declare(strict_types=1);

namespace Bbs\NativePrinting\Tests;

use Bbs\NativePrinting\Contracts\NativeBridge;
use Bbs\NativePrinting\Events\NativePrintingStateChanged;
use Bbs\NativePrinting\Facades\NativePrinting as NativePrintingFacade;
use Bbs\NativePrinting\NativePrinting;
use Bbs\NativePrinting\NativePrintingServiceProvider;
use Bbs\NativePrinting\Support\LocalPdfValidator;
use Bbs\NativePrinting\Support\NativePrintingErrorCode;
use FilesystemIterator;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class PluginTest extends TestCase
{
    private string $pluginPath;

    private string $sandboxRoot;

    private string $allowedRoot;

    private string $outsideRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginPath = dirname(__DIR__);
        $this->sandboxRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'native-printing-tests-'.bin2hex(random_bytes(8));
        $this->allowedRoot = $this->sandboxRoot.DIRECTORY_SEPARATOR.'allowed';
        $this->outsideRoot = $this->sandboxRoot.DIRECTORY_SEPARATOR.'outside';
        $this->makeDirectory($this->allowedRoot);
        $this->makeDirectory($this->outsideRoot);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        $this->deleteDirectory($this->sandboxRoot);

        parent::tearDown();
    }

    public function test_manifest_declares_the_android_only_contract(): void
    {
        $manifest = $this->readJson($this->pluginPath.DIRECTORY_SEPARATOR.'nativephp.json');
        self::assertSame('bbs/plugin-native-printing', $manifest['name']);
        self::assertSame('NativePrinting', $manifest['namespace']);
        self::assertSame(['android'], $manifest['platforms']);
        self::assertSame(
            ['NativePrinting.IsAvailable', 'NativePrinting.Preview', 'NativePrinting.Print'],
            array_column($manifest['bridge_functions'], 'name'),
        );

        foreach ($manifest['bridge_functions'] as $function) {
            self::assertArrayHasKey('android', $function);
            self::assertArrayNotHasKey('ios', $function);
        }

        self::assertSame([], $manifest['android']['permissions']);
        self::assertSame(29, $manifest['android']['min_version']);
        self::assertContains('androidx.appcompat:appcompat:1.7.1', $manifest['android']['dependencies']['implementation']);
        self::assertContains('androidx.recyclerview:recyclerview:1.4.0', $manifest['android']['dependencies']['implementation']);

        $activity = $manifest['android']['activities'][0];

        self::assertSame('com.bbs.plugins.native_printing.NativePdfPreviewActivity', $activity['name']);
        self::assertFalse($activity['exported']);
        self::assertSame(['Bbs\\NativePrinting\\Events\\NativePrintingStateChanged'], $manifest['events']);
    }

    public function test_composer_configuration_matches_the_plugin_contract(): void
    {
        $composer = $this->readJson($this->pluginPath.DIRECTORY_SEPARATOR.'composer.json');

        self::assertSame('bbs/plugin-native-printing', $composer['name']);
        self::assertSame('nativephp-plugin', $composer['type']);
        self::assertSame('^8.4', $composer['require']['php']);
        self::assertSame('^4.2', $composer['require']['nativephp/mobile']);
        self::assertSame('src/', $composer['autoload']['psr-4']['Bbs\\NativePrinting\\']);
        self::assertSame('nativephp.json', $composer['extra']['nativephp']['manifest']);
        self::assertSame('phpunit tests/PluginTest.php', $composer['scripts']['test']);
    }

    public function test_valid_application_local_pdf_is_accepted(): void
    {
        $pdfPath = $this->allowedRoot.DIRECTORY_SEPARATOR.'Invoice café 1001.pdf';
        $this->writePdf($pdfPath);
        $result = $this->validator()->validate($pdfPath);
        $canonicalPath = realpath($pdfPath);

        self::assertNotFalse($canonicalPath);
        self::assertTrue($result->valid);
        self::assertSame($canonicalPath, $result->canonicalPath);
        self::assertNull($result->errorCode);
        self::assertNull($result->errorMessage);
    }

    public function test_validator_rejects_unsafe_and_invalid_inputs(): void
    {
        $validator = $this->validator();
        $missing = $validator->validate($this->allowedRoot.DIRECTORY_SEPARATOR.'missing.pdf');

        self::assertFalse($missing->valid);
        self::assertSame(NativePrintingErrorCode::FILE_NOT_FOUND, $missing->errorCode);

        $directory = $validator->validate($this->allowedRoot);

        self::assertFalse($directory->valid);
        self::assertSame(NativePrintingErrorCode::INVALID_FILE_TYPE, $directory->errorCode);

        $wrongType = $this->allowedRoot.DIRECTORY_SEPARATOR.'document.txt';
        $this->writePdf($wrongType);
        $wrongTypeResult = $validator->validate($wrongType);

        self::assertFalse($wrongTypeResult->valid);
        self::assertSame(NativePrintingErrorCode::INVALID_FILE_TYPE, $wrongTypeResult->errorCode);

        $invalidPdf = $this->allowedRoot.DIRECTORY_SEPARATOR.'corrupt.pdf';
        file_put_contents($invalidPdf, 'This is not a PDF.');
        $invalidResult = $validator->validate($invalidPdf);

        self::assertFalse($invalidResult->valid);
        self::assertSame(NativePrintingErrorCode::INVALID_PDF, $invalidResult->errorCode);

        $outsidePdf = $this->outsideRoot.DIRECTORY_SEPARATOR.'outside.pdf';
        $this->writePdf($outsidePdf);
        $outsideResult = $validator->validate($outsidePdf);

        self::assertFalse($outsideResult->valid);
        self::assertSame(NativePrintingErrorCode::FILE_OUTSIDE_APP_STORAGE, $outsideResult->errorCode);
    }

    public function test_preview_propagates_canonical_path_and_request_id(): void
    {
        $pdfPath = $this->allowedRoot.DIRECTORY_SEPARATOR.'preview.pdf';
        $this->writePdf($pdfPath);
        $requestId = '550E8400-E29B-41D4-A716-446655440000';
        $expectedRequestId = strtolower($requestId);
        $bridge = new FakeNativeBridge((object) ['job_id' => 'preview-1']);
        $printing = new NativePrinting($this->validator(), $bridge);
        $result = $printing->preview(path: $pdfPath, title: "  Invoice\r\n  #1001  ", requestId: $requestId);
        $canonicalPath = realpath($pdfPath);

        self::assertNotFalse($canonicalPath);
        self::assertTrue($result->accepted);
        self::assertSame('preview', $result->action);
        self::assertSame('accepted', $result->status);
        self::assertSame($expectedRequestId, $result->request_id);
        self::assertSame('preview-1', $result->job_id);

        self::assertSame(
            [
                [
                    'method' => 'NativePrinting.Preview',
                    'parameters' => ['path' => $canonicalPath, 'title' => 'Invoice #1001', 'request_id' => $expectedRequestId],
                ],
            ],
            $bridge->calls,
        );
    }

    public function test_print_generates_uuid_and_normalizes_empty_job_name(): void
    {
        $pdfPath = $this->allowedRoot.DIRECTORY_SEPARATOR.'print.pdf';
        $this->writePdf($pdfPath);
        $bridge = new FakeNativeBridge((object) []);
        $printing = new NativePrinting($this->validator(), $bridge);
        $result = $printing->print(path: $pdfPath, jobName: " \t\r\n ");

        self::assertTrue($result->accepted);
        self::assertTrue(Str::isUuid($result->request_id));
        self::assertSame($result->request_id, $bridge->calls[0]['parameters']['request_id']);
        self::assertSame('Document', $bridge->calls[0]['parameters']['job_name']);
    }

    public function test_invalid_request_id_never_calls_native_bridge(): void
    {
        $pdfPath = $this->allowedRoot.DIRECTORY_SEPARATOR.'invalid-request.pdf';
        $this->writePdf($pdfPath);
        $bridge = new FakeNativeBridge((object) []);
        $printing = new NativePrinting($this->validator(), $bridge);
        $result = $printing->preview(path: $pdfPath, requestId: ' not-a-uuid ');

        self::assertFalse($result->accepted);
        self::assertSame('not-a-uuid', $result->request_id);
        self::assertSame('failed', $result->status);
        self::assertSame(NativePrintingErrorCode::INVALID_REQUEST_ID, $result->error_code);
        self::assertSame([], $bridge->calls);
    }

    public function test_invalid_pdf_never_calls_native_bridge(): void
    {
        $bridge = new FakeNativeBridge((object) []);
        $printing = new NativePrinting($this->validator(), $bridge);
        $result = $printing->print($this->allowedRoot.DIRECTORY_SEPARATOR.'missing.pdf');

        self::assertFalse($result->accepted);
        self::assertSame(NativePrintingErrorCode::FILE_NOT_FOUND, $result->error_code);
        self::assertSame([], $bridge->calls);
    }

    public function test_unavailable_native_activity_returns_structured_failure(): void
    {
        $pdfPath = $this->allowedRoot.DIRECTORY_SEPARATOR.'activity.pdf';
        $this->writePdf($pdfPath);
        $printing = new NativePrinting($this->validator(), new FakeNativeBridge(null));
        $result = $printing->preview($pdfPath);

        self::assertFalse($result->accepted);
        self::assertSame('preview', $result->action);
        self::assertSame('failed', $result->status);
        self::assertSame(NativePrintingErrorCode::ACTIVITY_UNAVAILABLE, $result->error_code);
    }

    public function test_availability_has_safe_fallback(): void
    {
        $printing = new NativePrinting($this->validator(), new FakeNativeBridge(null));
        $result = $printing->isAvailable();

        self::assertFalse($result->available);
        self::assertFalse($result->preview_supported);
        self::assertFalse($result->printing_supported);
        self::assertSame(NativePrintingErrorCode::PRINTING_UNAVAILABLE, $result->error_code);
    }

    public function test_provider_and_facade_resolve_the_same_singleton(): void
    {
        $storagePath = $this->sandboxRoot.DIRECTORY_SEPARATOR.'laravel-storage';
        $this->makeDirectory($storagePath.DIRECTORY_SEPARATOR.'app');
        $application = new Application(dirname(__DIR__, 4));
        $application->useStoragePath($storagePath);
        $provider = new NativePrintingServiceProvider($application);
        $provider->register();
        $resolved = $application->make(NativePrinting::class);

        self::assertInstanceOf(NativePrinting::class, $resolved);
        self::assertSame($resolved, $application->make(NativePrinting::class));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);

        self::assertSame($resolved, NativePrintingFacade::getFacadeRoot());
    }

    public function test_event_exposes_stable_typed_payload_fields(): void
    {
        $reflection = new ReflectionClass(NativePrintingStateChanged::class);

        $expectedTypes = [
            'request_id' => 'string',
            'action' => 'string',
            'status' => 'string',
            'job_id' => '?string',
            'error_code' => '?string',
            'error_message' => '?string',
        ];

        foreach ($expectedTypes as $name => $expectedType) {
            self::assertTrue($reflection->hasProperty($name), "Missing event property: {$name}");

            $property = $reflection->getProperty($name);

            self::assertTrue($property->isPublic());
            self::assertNotNull($property->getType());
            self::assertSame($expectedType, (string) $property->getType());
        }
    }

    public function test_error_codes_remain_stable(): void
    {
        $expected = [
            'PRINTING_UNAVAILABLE',
            'FILE_NOT_FOUND',
            'FILE_NOT_READABLE',
            'FILE_OUTSIDE_APP_STORAGE',
            'INVALID_FILE_TYPE',
            'INVALID_PDF',
            'INVALID_REQUEST_ID',
            'PREVIEW_UNAVAILABLE',
            'PREVIEW_FAILED',
            'PRINT_DIALOG_FAILED',
            'PRINT_CANCELLED',
            'PRINT_JOB_FAILED',
            'ACTIVITY_UNAVAILABLE',
            'UNKNOWN_ERROR',
        ];

        foreach ($expected as $value) {
            self::assertSame($value, constant(NativePrintingErrorCode::class.'::'.$value));
        }
    }

    public function test_android_bridge_source_matches_manifest_contract(): void
    {
        $source = $this->readText(
            $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'resources'.
            DIRECTORY_SEPARATOR.
            'android'.
            DIRECTORY_SEPARATOR.
            'NativePrintingFunctions.kt',
        );

        foreach (
            [
                'IsAvailable',
                'Preview',
                'Print',
            ] as $class
        ) {
            self::assertStringContainsString(
                "class {$class}",
                $source,
            );
        }

        self::assertStringNotContainsString(
            'class Execute',
            $source,
        );
        self::assertStringNotContainsString(
            'class GetStatus',
            $source,
        );
    }

    public function test_preview_activity_remains_internal_and_lifecycle_aware(): void
    {
        $manifest = $this->readJson(
            $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'nativephp.json',
        );

        $activities = array_values(
            array_filter(
                $manifest['android']['activities'],
                static fn (array $activity): bool => $activity['name'] ===
                    'com.bbs.plugins.native_printing.'.
                    'NativePdfPreviewActivity',
            ),
        );

        self::assertCount(1, $activities);
        self::assertFalse($activities[0]['exported']);

        $source = $this->readText(
            $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'resources'.
            DIRECTORY_SEPARATOR.
            'android'.
            DIRECTORY_SEPARATOR.
            'NativePdfPreviewActivity.kt',
        );

        self::assertStringContainsString(
            'class NativePdfPreviewActivity : AppCompatActivity()',
            $source,
        );
        self::assertStringContainsString(
            'NativePdfValidator.validate',
            $source,
        );
        self::assertStringContainsString(
            'NativePdfPageAdapter',
            $source,
        );
        self::assertStringContainsString(
            'OnBackPressedCallback',
            $source,
        );
        self::assertStringContainsString(
            'STATUS_PRESENTED',
            $source,
        );
        self::assertStringContainsString(
            'STATUS_CLOSED',
            $source,
        );
        self::assertStringContainsString(
            'NativePrintController.enqueue',
            $source,
        );
    }

    public function test_android_sources_enforce_storage_and_logging_rules(): void
    {
        $files = glob(
            $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'resources'.
            DIRECTORY_SEPARATOR.
            'android'.
            DIRECTORY_SEPARATOR.
            '*.kt',
        );

        self::assertIsArray($files);
        self::assertGreaterThanOrEqual(9, count($files));

        sort($files);

        $combined = implode(
            "\n",
            array_map(
                fn (string $path): string => $this->readText($path),
                $files,
            ),
        );

        foreach (
            [
                'READ_EXTERNAL_STORAGE',
                'WRITE_EXTERNAL_STORAGE',
                'MANAGE_EXTERNAL_STORAGE',
                'Log.',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $combined,
            );
        }

        $validator = $this->readText(
            $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'resources'.
            DIRECTORY_SEPARATOR.
            'android'.
            DIRECTORY_SEPARATOR.
            'NativePdfValidator.kt',
        );

        self::assertStringContainsString(
            'canonicalFile',
            $validator,
        );
        self::assertStringContainsString(
            'FILE_OUTSIDE_APP_STORAGE',
            $validator,
        );
        self::assertStringContainsString(
            'PdfRenderer',
            $validator,
        );

        $events = $this->readText(
            $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'resources'.
            DIRECTORY_SEPARATOR.
            'android'.
            DIRECTORY_SEPARATOR.
            'NativePrintingEvents.kt',
        );

        self::assertStringNotContainsString(
            '"path"',
            $events,
        );
        self::assertStringNotContainsString(
            'absolutePath',
            $events,
        );
    }

    public function test_print_completion_only_comes_from_platform_job_state(): void
    {
        $androidPath = $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'resources'.
            DIRECTORY_SEPARATOR.
            'android'.
            DIRECTORY_SEPARATOR;

        $adapter = $this->readText(
            $androidPath.
            'NativePdfPrintDocumentAdapter.kt',
        );

        $controller = $this->readText(
            $androidPath.
            'NativePrintController.kt',
        );

        self::assertStringNotContainsString(
            'STATUS_COMPLETED',
            $adapter,
        );
        self::assertStringNotContainsString(
            'dispatchState',
            $adapter,
        );

        foreach (
            [
                'PrintJobInfo.STATE_QUEUED',
                'PrintJobInfo.STATE_STARTED',
                'PrintJobInfo.STATE_BLOCKED',
                'PrintJobInfo.STATE_COMPLETED',
                'PrintJobInfo.STATE_FAILED',
                'PrintJobInfo.STATE_CANCELED',
                'STATUS_SUBMITTED',
                'STATUS_BLOCKED',
                'STATUS_COMPLETED',
                'STATUS_CANCELLED',
            ] as $required
        ) {
            self::assertStringContainsString(
                $required,
                $controller,
            );
        }
    }

    public function test_preview_rendering_is_lazy_bounded_and_zoomable(): void
    {
        $androidPath = $this->pluginPath.
            DIRECTORY_SEPARATOR.
            'resources'.
            DIRECTORY_SEPARATOR.
            'android'.
            DIRECTORY_SEPARATOR;

        $adapter = $this->readText(
            $androidPath.'NativePdfPageAdapter.kt',
        );

        self::assertStringContainsString(
            'Executors.newSingleThreadExecutor()',
            $adapter,
        );
        self::assertStringContainsString(
            'renderer.openPage(position)',
            $adapter,
        );
        self::assertStringContainsString(
            'LruCache<Int, Bitmap>',
            $adapter,
        );
        self::assertStringContainsString(
            'MAXIMUM_BITMAP_PIXELS',
            $adapter,
        );

        $zoom = $this->readText(
            $androidPath.'ZoomablePdfImageView.kt',
        );

        self::assertStringContainsString(
            'ScaleGestureDetector',
            $zoom,
        );
        self::assertStringContainsString(
            'MAXIMUM_SCALE = 4.0f',
            $zoom,
        );
        self::assertStringContainsString(
            'requestDisallowInterceptTouchEvent',
            $zoom,
        );
    }

    public function test_preview_toolbar_respects_system_bars_and_display_cutouts(): void
    {
        $sourcePath = $this->pluginPath.
            '/resources/android/NativePdfPreviewActivity.kt';

        $source = file_get_contents($sourcePath);

        self::assertNotFalse($source);
        self::assertStringContainsString(
            'ViewCompat.setOnApplyWindowInsetsListener(root)',
            $source,
        );
        self::assertStringContainsString(
            'WindowInsetsCompat.Type.systemBars()',
            $source,
        );
        self::assertStringContainsString(
            'WindowInsetsCompat.Type.displayCutout()',
            $source,
        );
        self::assertStringContainsString(
            'verticalPadding + safeInsets.top',
            $source,
        );
        self::assertStringContainsString(
            'densityPixels(12) + safeInsets.bottom',
            $source,
        );
    }

    public function test_android_print_job_uses_stable_plugin_identifier(): void
    {
        $sourcePath = $this->pluginPath.
            '/resources/android/NativePrintController.kt';

        $source = file_get_contents($sourcePath);

        self::assertNotFalse($source);
        self::assertStringContainsString(
            'import java.util.UUID',
            $source,
        );
        self::assertStringContainsString(
            'jobId = UUID.randomUUID().toString()',
            $source,
        );
        self::assertStringNotContainsString(
            'job.id.toString()',
            $source,
        );
        self::assertStringNotContainsString(
            'job.id.flattenToString()',
            $source,
        );
    }

    private function validator(): LocalPdfValidator
    {
        return new LocalPdfValidator([$this->allowedRoot]);
    }

    private function readText(string $path): string
    {
        $contents = file_get_contents($path);

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

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function writePdf(string $path): void
    {
        $written = file_put_contents($path, "%PDF-1.4\n"."1 0 obj\n"."<<>>\n"."endobj\n"."trailer\n"."<<>>\n"."%%EOF\n");

        self::assertNotFalse($written);
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
            self::fail("Could not create test directory: {$path}");
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
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

    public function __construct(
        public ?object $response,
    ) {}

    public function call(string $method, array $parameters = []): ?object
    {
        $this->calls[] = ['method' => $method, 'parameters' => $parameters];

        return $this->response;
    }
}
