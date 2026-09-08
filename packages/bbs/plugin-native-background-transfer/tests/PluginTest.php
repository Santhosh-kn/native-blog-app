<?php

declare(strict_types=1);

namespace Bbs\NativeBackgroundTransfer\Tests;

use Bbs\NativeBackgroundTransfer\Contracts\NativeBridge;
use Bbs\NativeBackgroundTransfer\NativeBackgroundTransfer;
use Bbs\NativeBackgroundTransfer\NativeBackgroundTransferServiceProvider;
use Bbs\NativeBackgroundTransfer\Support\NativeBackgroundTransferErrorCode;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    private const REQUEST_ID =
        '550e8400-e29b-41d4-a716-446655440000';

    private const FILE_ID =
        '660e8400-e29b-41d4-a716-446655440000';

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_composer_configuration_matches_contract(): void
    {
        $composer = $this->readJson(
            dirname(__DIR__).'/composer.json',
        );

        self::assertSame(
            'bbs/plugin-native-background-transfer',
            $composer['name'],
        );

        self::assertSame(
            '1.0.0',
            $composer['version'],
        );

        self::assertSame(
            'nativephp-plugin',
            $composer['type'],
        );

        self::assertSame(
            '^8.4',
            $composer['require']['php'],
        );

        self::assertSame(
            '^4.2',
            $composer['require']['nativephp/mobile'],
        );

        self::assertSame(
            'src/',
            $composer['autoload']['psr-4']
                ['Bbs\\NativeBackgroundTransfer\\'],
        );

        self::assertSame(
            'phpunit --bootstrap tests/bootstrap.php tests/PluginTest.php',
            $composer['scripts']['test'],
        );
    }

    public function test_manifest_declares_android_download_first_contract(): void
    {
        $manifest = $this->readJson(
            dirname(__DIR__).'/nativephp.json',
        );

        self::assertSame(
            'bbs/plugin-native-background-transfer',
            $manifest['name'],
        );

        self::assertSame(
            'NativeBackgroundTransfer',
            $manifest['namespace'],
        );

        self::assertSame(
            ['android'],
            $manifest['platforms'],
        );

        self::assertSame(
            [
                'android.permission.INTERNET',
                'android.permission.ACCESS_NETWORK_STATE',
                'android.permission.POST_NOTIFICATIONS',
                'android.permission.FOREGROUND_SERVICE',
                'android.permission.FOREGROUND_SERVICE_DATA_SYNC',
            ],
            $manifest['android']['permissions'],
        );

        self::assertSame(
            [
                'androidx.work:work-runtime:2.11.2',
            ],
            $manifest['android']['dependencies']['implementation'],
        );

        self::assertSame(
            33,
            $manifest['android']['min_version'],
        );

        self::assertArrayNotHasKey(
            'hooks',
            $manifest,
        );

        self::assertSame(
            [
                'NativeBackgroundTransfer.StartDownload',
                'NativeBackgroundTransfer.GetStatus',
                'NativeBackgroundTransfer.ListTransfers',
                'NativeBackgroundTransfer.Cancel',
                'NativeBackgroundTransfer.ConsumeResult',
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

        self::assertNotContains(
            'NativeBackgroundTransfer.StartUpload',
            array_column(
                $manifest['bridge_functions'],
                'name',
            ),
        );
    }

    public function test_android_bridge_matches_manifest_and_remains_scheduler_free(): void
    {
        $manifest = $this->readJson(
            dirname(__DIR__).'/nativephp.json',
        );

        $functions = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferFunctions.kt',
        );

        foreach ($manifest['bridge_functions'] as $function) {
            $parts = explode(
                '.',
                $function['android'],
            );

            $className = end($parts);

            self::assertStringContainsString(
                "class {$className}",
                $functions,
            );
        }

        self::assertStringContainsString(
            'SCHEDULER_UNAVAILABLE',
            $functions,
        );

        foreach ([
            'androidx.work',
            'WorkManager',
            'Worker',
            'HttpURLConnection',
            'OkHttp',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $functions,
            );
        }
    }
    public function test_android_scheduler_is_durable_network_constrained_and_private(): void
    {
        $scheduler = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferScheduler.kt',
        );

        $worker = $this->readPluginFile(
            'resources/android/NativeBackgroundDownloadWorker.kt',
        );

        self::assertStringContainsString(
            'NetworkType.CONNECTED',
            $scheduler,
        );

        self::assertStringContainsString(
            'enqueueUniqueWork',
            $scheduler,
        );

        self::assertStringContainsString(
            'ExistingWorkPolicy.KEEP',
            $scheduler,
        );

        self::assertStringContainsString(
            'cancelUniqueWork',
            $scheduler,
        );

        self::assertStringContainsString(
            'BackoffPolicy.EXPONENTIAL',
            $scheduler,
        );

        self::assertStringContainsString(
            'INPUT_TRANSFER_ID',
            $scheduler,
        );

        foreach ([
            '"url"',
            'Authorization',
            'Bearer',
            'destinationPath',
            'privatePath',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $scheduler,
            );
        }

        self::assertStringContainsString(
            'class NativeBackgroundDownloadWorker',
            $worker,
        );

        self::assertStringContainsString(
            'SCHEDULER_UNAVAILABLE',
            $worker,
        );

        self::assertStringContainsString(
            'store.update(failed)',
            $worker,
        );

        foreach ([
            'HttpURLConnection',
            'OkHttp',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $worker,
            );
        }
    }
    public function test_android_download_file_policy_is_private_and_path_safe(): void
    {
        $policy = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferFilePolicy.kt',
        );

        self::assertStringContainsString(
            'applicationContext.filesDir',
            $policy,
        );

        self::assertStringContainsString(
            'native_background_transfer_downloads',
            $policy,
        );

        self::assertStringContainsString(
            'canonicalFile',
            $policy,
        );

        self::assertStringContainsString(
            '".$transferId.part"',
            $policy,
        );

        self::assertStringContainsString(
            '"$transferId.$extension"',
            $policy,
        );

        self::assertStringContainsString(
            'finalFile.exists()',
            $policy,
        );

        self::assertStringContainsString(
            'partialFile.renameTo',
            $policy,
        );

        foreach ([
            'Environment.getExternalStorage',
            'getExternalFilesDir',
            'DownloadsContract',
            'MediaStore',
            'destinationPath',
            'destination_path',
            'Content-Disposition',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $policy,
            );
        }
    }
    public function test_android_download_engine_is_bounded_https_only_and_private(): void
    {
        $engine = $this->readPluginFile(
            'resources/android/NativeBackgroundDownloadEngine.kt',
        );

        self::assertStringContainsString(
            'HttpsURLConnection',
            $engine,
        );

        self::assertStringContainsString(
            'validateHttpsUrl',
            $engine,
        );

        self::assertStringContainsString(
            'instanceFollowRedirects',
            $engine,
        );

        self::assertStringContainsString(
            'MAX_REDIRECTS = 5',
            $engine,
        );

        self::assertStringContainsString(
            'CONNECT_TIMEOUT_MILLISECONDS',
            $engine,
        );

        self::assertStringContainsString(
            'READ_TIMEOUT_MILLISECONDS',
            $engine,
        );

        self::assertStringContainsString(
            'contentLengthLong',
            $engine,
        );

        self::assertStringContainsString(
            'normalizeConcreteMimeType',
            $engine,
        );

        self::assertStringContainsString(
            'isMimeTypeAllowed',
            $engine,
        );

        self::assertStringContainsString(
            'request.maxSize -',
            $engine,
        );

        self::assertStringContainsString(
            'BUFFER_SIZE =',
            $engine,
        );

        self::assertStringContainsString(
            '64 * 1024',
            $engine,
        );

        self::assertStringContainsString(
            'output.fd.sync()',
            $engine,
        );

        self::assertStringContainsString(
            'filePolicy.preparePartial',
            $engine,
        );

        self::assertStringContainsString(
            'filePolicy.deletePartial',
            $engine,
        );

        self::assertStringContainsString(
            'filePolicy.promote',
            $engine,
        );

        self::assertStringContainsString(
            '"Accept-Encoding"',
            $engine,
        );

        self::assertStringContainsString(
            '"identity"',
            $engine,
        );

        foreach ([
            'Authorization',
            'Bearer',
            'Cookie',
            'Content-Disposition',
            'destinationPath',
            'destination_path',
            'getExternalFilesDir',
            'MediaStore',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $engine,
            );
        }
    }
    public function test_android_download_content_is_validated_before_promotion(): void
    {
        $validator = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferContentValidator.kt',
        );

        $engine = $this->readPluginFile(
            'resources/android/NativeBackgroundDownloadEngine.kt',
        );

        $policy = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferFilePolicy.kt',
        );

        foreach ([
            '"application/pdf"',
            '"image/jpeg"',
            '"image/png"',
            '"image/webp"',
            '"application/zip"',
            '"application/msword"',
            '"application/vnd.openxmlformats-officedocument.wordprocessingml.document"',
            '"text/plain"',
            '"text/csv"',
        ] as $mimeType) {
            self::assertStringContainsString(
                $mimeType,
                $validator,
            );
        }

        self::assertStringContainsString(
            'ZipFile(file)',
            $validator,
        );

        self::assertStringContainsString(
            '"[Content_Types].xml"',
            $validator,
        );

        self::assertStringContainsString(
            '"word/document.xml"',
            $validator,
        );

        self::assertStringContainsString(
            'CodingErrorAction.REPORT',
            $validator,
        );

        self::assertStringContainsString(
            'contentValidator.validate',
            $engine,
        );

        self::assertStringContainsString(
            'file = files.partialFile',
            $engine,
        );

        self::assertStringContainsString(
            'INVALID_CONTENT_TYPE',
            $engine,
        );

        self::assertStringContainsString(
            'fun deleteFinal(',
            $policy,
        );

        self::assertStringContainsString(
            'filePolicy.deleteFinal(files)',
            $engine,
        );

        $validationPosition =
            strpos(
                $engine,
                'contentValidator.validate',
            );

        $promotionPosition =
            strpos(
                $engine,
                'filePolicy.promote',
            );

        self::assertNotFalse(
            $validationPosition,
        );

        self::assertNotFalse(
            $promotionPosition,
        );

        self::assertLessThan(
            $promotionPosition,
            $validationPosition,
        );

        foreach ([
            'MimeTypeMap.getFileExtensionFromUrl',
            'Files.probeContentType',
            'Content-Disposition',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $validator,
            );
        }
    }
    public function test_android_long_running_download_has_safe_foreground_configuration(): void
    {
        $manifest = $this->readJson(
            dirname(__DIR__).'/nativephp.json',
        );

        $notifications = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferNotifications.kt',
        );

        self::assertContains(
            'android.permission.FOREGROUND_SERVICE',
            $manifest['android']['permissions'],
        );

        self::assertContains(
            'android.permission.FOREGROUND_SERVICE_DATA_SYNC',
            $manifest['android']['permissions'],
        );

        self::assertContains(
            'android.permission.POST_NOTIFICATIONS',
            $manifest['android']['permissions'],
        );

        self::assertSame(
            'androidx.work.impl.foreground.SystemForegroundService',
            $manifest['android']['services'][0]['name'],
        );

        self::assertFalse(
            $manifest['android']['services'][0]['exported'],
        );

        self::assertSame(
            'dataSync',
            $manifest['android']['services'][0]['foregroundServiceType'],
        );

        self::assertStringContainsString(
            'ForegroundInfo(',
            $notifications,
        );

        self::assertStringContainsString(
            'FOREGROUND_SERVICE_TYPE_DATA_SYNC',
            $notifications,
        );

        self::assertStringContainsString(
            'NotificationChannel',
            $notifications,
        );

        self::assertStringContainsString(
            'IMPORTANCE_LOW',
            $notifications,
        );

        self::assertStringContainsString(
            'VISIBILITY_PRIVATE',
            $notifications,
        );

        self::assertStringContainsString(
            'setOnlyAlertOnce(true)',
            $notifications,
        );

        self::assertStringContainsString(
            'setOngoing(true)',
            $notifications,
        );

        foreach ([
            'Authorization',
            'Bearer',
            'request.url',
            'privatePath',
            'destinationPath',
            'filesDir',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $notifications,
            );
        }
    }
    public function test_android_worker_executes_engine_with_progress_retry_and_cancellation(): void
    {
        $worker = $this->readPluginFile(
            'resources/android/NativeBackgroundDownloadWorker.kt',
        );

        $engine = $this->readPluginFile(
            'resources/android/NativeBackgroundDownloadEngine.kt',
        );

        $policy = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferFilePolicy.kt',
        );

        self::assertStringContainsString(
            'NativeBackgroundDownloadEngine(',
            $worker,
        );

        self::assertStringContainsString(
            'engine.download(',
            $worker,
        );

        self::assertStringContainsString(
            'setForegroundAsync(',
            $worker,
        );

        self::assertStringContainsString(
            ').get()',
            $worker,
        );

        self::assertStringContainsString(
            'STATUS_RUNNING',
            $worker,
        );

        self::assertStringContainsString(
            'PROGRESS_BYTE_INTERVAL',
            $worker,
        );

        self::assertStringContainsString(
            'NETWORK_ERROR',
            $worker,
        );

        self::assertStringContainsString(
            'runAttemptCount',
            $worker,
        );

        self::assertStringContainsString(
            'Result.retry()',
            $worker,
        );

        self::assertStringContainsString(
            'STATUS_CANCELLED',
            $worker,
        );

        self::assertStringContainsString(
            'isPersistedCancelled',
            $worker,
        );

        self::assertStringContainsString(
            'deleteFinalFor',
            $worker,
        );

        self::assertStringContainsString(
            'fun deleteFinalFor(',
            $policy,
        );

        self::assertStringContainsString(
            'Cancellation immediately before content validation.',
            $engine,
        );

        self::assertStringContainsString(
            'check cancellation once more before promotion.',
            $engine,
        );

        foreach ([
            'Authorization',
            'Bearer',
            'request.url',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $worker,
            );
        }
    }
    public function test_android_bridge_persists_before_scheduling_and_supports_cancellation(): void
    {
        $functions = $this->readPluginFile(
            'resources/android/NativeBackgroundTransferFunctions.kt',
        );

        self::assertStringContainsString(
            'store.begin(request)',
            $functions,
        );

        self::assertStringContainsString(
            'scheduler.enqueueDownload(',
            $functions,
        );

        self::assertStringContainsString(
            'NativeBackgroundTransferBeginResult.Stored',
            $functions,
        );

        self::assertStringContainsString(
            'NativeBackgroundTransferBeginResult.Duplicate',
            $functions,
        );

        self::assertStringContainsString(
            'DUPLICATE_TRANSFER_ID',
            $functions,
        );

        self::assertStringContainsString(
            '"accepted" to true',
            $functions,
        );

        self::assertStringContainsString(
            'STATUS_QUEUED',
            $functions,
        );

        self::assertStringContainsString(
            'scheduler.cancel(id)',
            $functions,
        );

        self::assertStringContainsString(
            'STATUS_CANCELLED',
            $functions,
        );

        self::assertStringContainsString(
            'store.update(cancelled)',
            $functions,
        );

        self::assertStringContainsString(
            'RESULT_PERSISTENCE_FAILED',
            $functions,
        );

        $beginPosition =
            strpos(
                $functions,
                'store.begin(request)',
            );

        $enqueuePosition =
            strpos(
                $functions,
                'scheduler.enqueueDownload(',
            );

        self::assertNotFalse(
            $beginPosition,
        );

        self::assertNotFalse(
            $enqueuePosition,
        );

        self::assertLessThan(
            $enqueuePosition,
            $beginPosition,
        );

        foreach ([
            'Authorization',
            'Bearer',
            'destinationPath',
            'privatePath',
            'request.url',
            'android.util.Log',
            'Log.',
        ] as $forbiddenToken) {
            self::assertStringNotContainsString(
                $forbiddenToken,
                $functions,
            );
        }
    }
    public function test_unused_scaffold_runtime_files_are_absent(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__).'/tests/Pest.php',
        );

        self::assertDirectoryDoesNotExist(
            dirname(__DIR__).'/resources/ios',
        );

        self::assertDirectoryDoesNotExist(
            dirname(__DIR__).'/resources/js',
        );

        self::assertDirectoryDoesNotExist(
            dirname(__DIR__).'/src/Commands',
        );

        $provider = $this->readPluginFile(
            'src/NativeBackgroundTransferServiceProvider.php',
        );

        self::assertStringNotContainsString(
            'CopyAssetsCommand',
            $provider,
        );

        self::assertStringNotContainsString(
            'function boot',
            $provider,
        );
    }

    public function test_start_download_forwards_only_normalized_safe_parameters(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'accepted' => true,
                'id' => self::REQUEST_ID,
                'status' => 'queued',
            ],
        );

        $transfer = $this->transfer($bridge);

        $result = $transfer->startDownload([
            'id' => strtoupper(self::REQUEST_ID),
            'url' => 'https://example.com/files/report.pdf?token=temporary',
            'mime_types' => [
                ' APPLICATION/PDF ',
                'text/plain',
                'application/pdf',
            ],
            'max_size' => 1_048_576,

            // Must never cross the bridge.
            'destination_path' => '/unsafe/path',
            'authorization' => 'Bearer secret',
            'private_path' => '/data/private',
        ]);

        self::assertTrue($result->accepted);
        self::assertSame(
            self::REQUEST_ID,
            $result->id,
        );

        self::assertSame(
            'download',
            $result->type,
        );

        self::assertSame(
            'queued',
            $result->status,
        );

        self::assertSame(
            [
                [
                    'method' =>
                        'NativeBackgroundTransfer.StartDownload',
                    'parameters' => [
                        'id' => self::REQUEST_ID,
                        'url' =>
                            'https://example.com/files/report.pdf?token=temporary',
                        'mime_types' => [
                            'application/pdf',
                            'text/plain',
                        ],
                        'max_size' => 1_048_576,
                    ],
                ],
            ],
            $bridge->calls,
        );
    }

    public function test_start_download_generates_uuid_and_uses_secure_defaults(): void
    {
        $bridge = new FakeNativeBridge(
            static function (
                string $method,
                array $parameters,
            ): object {
                return (object) [
                    'accepted' => true,
                    'id' => $parameters['id'],
                    'status' => 'queued',
                ];
            },
        );

        $result = $this->transfer($bridge)
            ->startDownload([
                'url' =>
                    'https://example.com/file.pdf',
            ]);

        $parameters =
            $bridge->calls[0]['parameters'];

        self::assertTrue(
            Str::isUuid($result->id),
        );

        self::assertSame(
            $result->id,
            $parameters['id'],
        );

        self::assertSame(
            NativeBackgroundTransfer::DEFAULT_MIME_TYPES,
            $parameters['mime_types'],
        );

        self::assertSame(
            NativeBackgroundTransfer::DEFAULT_MAX_SIZE,
            $parameters['max_size'],
        );
    }

    public function test_invalid_download_requests_never_reach_bridge(): void
    {
        $cases = [
            [
                [
                    'id' => 'not-a-uuid',
                    'url' => 'https://example.com/file.pdf',
                ],
                NativeBackgroundTransferErrorCode::INVALID_REQUEST_ID,
            ],
            [
                [],
                NativeBackgroundTransferErrorCode::INVALID_URL,
            ],
            [
                [
                    'url' => 123,
                ],
                NativeBackgroundTransferErrorCode::INVALID_URL,
            ],
            [
                [
                    'url' =>
                        'http://example.com/file.pdf',
                ],
                NativeBackgroundTransferErrorCode::HTTPS_REQUIRED,
            ],
            [
                [
                    'url' =>
                        'https://user:password@example.com/file.pdf',
                ],
                NativeBackgroundTransferErrorCode::INVALID_URL,
            ],
            [
                [
                    'url' =>
                        'https://example.com/file.pdf#fragment',
                ],
                NativeBackgroundTransferErrorCode::INVALID_URL,
            ],
            [
                [
                    'url' =>
                        'https://exa mple.com/file.pdf',
                ],
                NativeBackgroundTransferErrorCode::INVALID_URL,
            ],
            [
                [
                    'url' => 'https://example.com/file.pdf',
                    'mime_types' => [],
                ],
                NativeBackgroundTransferErrorCode::INVALID_MIME_TYPE,
            ],
            [
                [
                    'url' => 'https://example.com/file.pdf',
                    'mime_types' =>
                        'application/pdf',
                ],
                NativeBackgroundTransferErrorCode::INVALID_MIME_TYPE,
            ],
            [
                [
                    'url' => 'https://example.com/file.pdf',
                    'mime_types' => [
                        'application/pdf; charset=utf-8',
                    ],
                ],
                NativeBackgroundTransferErrorCode::INVALID_MIME_TYPE,
            ],
            [
                [
                    'url' => 'https://example.com/file.pdf',
                    'max_size' => 0,
                ],
                NativeBackgroundTransferErrorCode::INVALID_MAX_SIZE,
            ],
            [
                [
                    'url' => 'https://example.com/file.pdf',
                    'max_size' => '1048576',
                ],
                NativeBackgroundTransferErrorCode::INVALID_MAX_SIZE,
            ],
            [
                [
                    'url' => 'https://example.com/file.pdf',
                    'max_size' =>
                        NativeBackgroundTransfer::MAX_CONFIGURABLE_SIZE + 1,
                ],
                NativeBackgroundTransferErrorCode::INVALID_MAX_SIZE,
            ],
        ];

        foreach ($cases as [$options, $expectedErrorCode]) {
            $bridge = new FakeNativeBridge(
                (object) [
                    'accepted' => true,
                    'id' => self::REQUEST_ID,
                    'status' => 'queued',
                ],
            );

            $result = $this->transfer($bridge)
                ->startDownload($options);

            self::assertFalse(
                $result->accepted,
            );

            self::assertSame(
                $expectedErrorCode,
                $result->errorCode,
            );

            self::assertSame(
                [],
                $bridge->calls,
            );
        }
    }

    public function test_bridge_unavailable_returns_controlled_failure(): void
    {
        $bridge = new FakeNativeBridge(null);

        $result = $this->transfer($bridge)
            ->startDownload([
                'id' => self::REQUEST_ID,
                'url' =>
                    'https://example.com/file.pdf',
            ]);

        self::assertFalse($result->accepted);

        self::assertSame(
            NativeBackgroundTransferErrorCode::SCHEDULER_UNAVAILABLE,
            $result->errorCode,
        );
    }

    public function test_native_rejection_does_not_expose_native_error_message(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'accepted' => false,
                'id' => self::REQUEST_ID,
                'status' => 'failed',
                'errorCode' =>
                    NativeBackgroundTransferErrorCode::HTTP_ERROR,

                'errorMessage' =>
                    '/data/user/0/private/file Authorization: Bearer secret',
            ],
        );

        $result = $this->transfer($bridge)
            ->startDownload([
                'id' => self::REQUEST_ID,
                'url' =>
                    'https://example.com/file.pdf',
            ]);

        self::assertFalse($result->accepted);

        self::assertSame(
            NativeBackgroundTransferErrorCode::HTTP_ERROR,
            $result->errorCode,
        );

        self::assertSame(
            NativeBackgroundTransferErrorCode::message(
                NativeBackgroundTransferErrorCode::HTTP_ERROR,
            ),
            $result->errorMessage,
        );

        self::assertStringNotContainsString(
            '/data/user',
            $result->errorMessage,
        );

        self::assertStringNotContainsString(
            'Bearer secret',
            $result->errorMessage,
        );
    }

    public function test_start_download_rejects_mismatched_native_result_id(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'accepted' => true,
                'id' => self::FILE_ID,
                'status' => 'queued',
            ],
        );

        $result = $this->transfer($bridge)
            ->startDownload([
                'id' => self::REQUEST_ID,
                'url' =>
                    'https://example.com/file.pdf',
            ]);

        self::assertFalse($result->accepted);

        self::assertSame(
            NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
            $result->errorCode,
        );
    }

    public function test_status_normalizes_safe_success_metadata(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'type' => 'download',
                'status' => 'succeeded',
                'transferredBytes' => 4096,
                'totalBytes' => 4096,
                'progress' => 100,
                'fileId' => self::FILE_ID,
                'displayName' =>
                    "  Invoice/2026\r\n.pdf  ",
                'mimeType' => 'APPLICATION/PDF',
                'size' => 4096,
                'consumed' => false,

                // These must be ignored.
                'path' =>
                    '/data/user/0/com.santhosh.nativeblog/private.pdf',
                'url' =>
                    'https://example.com/private.pdf?token=secret',
                'authorization' =>
                    'Bearer secret',
            ],
        );

        $result = $this->transfer($bridge)
            ->getStatus(self::REQUEST_ID);

        self::assertSame(
            'succeeded',
            $result->status,
        );

        self::assertSame(
            4096,
            $result->transferredBytes,
        );

        self::assertSame(
            4096,
            $result->totalBytes,
        );

        self::assertSame(
            100,
            $result->progress,
        );

        self::assertSame(
            self::FILE_ID,
            $result->fileId,
        );

        self::assertSame(
            'Invoice_2026 .pdf',
            $result->displayName,
        );

        self::assertSame(
            'application/pdf',
            $result->mimeType,
        );

        self::assertSame(
            4096,
            $result->size,
        );

        $safe = $result->toArray();

        self::assertArrayNotHasKey(
            'path',
            $safe,
        );

        self::assertArrayNotHasKey(
            'url',
            $safe,
        );

        self::assertArrayNotHasKey(
            'authorization',
            $safe,
        );

        self::assertArrayNotHasKey(
            'headers',
            $safe,
        );
    }

    public function test_running_progress_is_normalized(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'type' => 'download',
                'status' => 'running',
                'transferred_bytes' => 512,
                'total_bytes' => 1024,
                'progress' => 50,
                'consumed' => false,
            ],
        );

        $result = $this->transfer($bridge)
            ->getStatus(self::REQUEST_ID);

        self::assertSame(
            'running',
            $result->status,
        );

        self::assertSame(
            512,
            $result->transferredBytes,
        );

        self::assertSame(
            1024,
            $result->totalBytes,
        );

        self::assertSame(
            50,
            $result->progress,
        );

        self::assertNull($result->fileId);
        self::assertNull($result->size);
    }

    public function test_inconsistent_progress_payload_is_rejected(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'type' => 'download',
                'status' => 'running',
                'transferredBytes' => 500,
                'totalBytes' => 1000,
                'progress' => 49,
            ],
        );

        $result = $this->transfer($bridge)
            ->getStatus(self::REQUEST_ID);

        self::assertSame(
            'failed',
            $result->status,
        );

        self::assertSame(
            NativeBackgroundTransferErrorCode::UNKNOWN_ERROR,
            $result->errorCode,
        );
    }

    public function test_failed_status_uses_controlled_error_message(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'type' => 'download',
                'status' => 'failed',
                'transferredBytes' => 128,
                'totalBytes' => 1024,
                'progress' => 12,
                'errorCode' =>
                    NativeBackgroundTransferErrorCode::NETWORK_ERROR,
                'errorMessage' =>
                    'https://secret.example/token /data/private',
            ],
        );

        $result = $this->transfer($bridge)
            ->getStatus(self::REQUEST_ID);

        self::assertSame(
            'failed',
            $result->status,
        );

        self::assertSame(
            NativeBackgroundTransferErrorCode::NETWORK_ERROR,
            $result->errorCode,
        );

        self::assertSame(
            NativeBackgroundTransferErrorCode::message(
                NativeBackgroundTransferErrorCode::NETWORK_ERROR,
            ),
            $result->errorMessage,
        );

        self::assertStringNotContainsString(
            'secret.example',
            $result->errorMessage,
        );
    }

    public function test_cancel_calls_only_cancel_bridge_function(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'type' => 'download',
                'status' => 'cancelled',
                'transferredBytes' => 512,
                'totalBytes' => 1024,
                'progress' => 50,
                'consumed' => false,
            ],
        );

        $result = $this->transfer($bridge)
            ->cancel(self::REQUEST_ID);

        self::assertSame(
            'cancelled',
            $result->status,
        );

        self::assertSame(
            [
                [
                    'method' =>
                        'NativeBackgroundTransfer.Cancel',
                    'parameters' => [
                        'id' => self::REQUEST_ID,
                    ],
                ],
            ],
            $bridge->calls,
        );
    }

    public function test_consume_result_supports_atomic_consumed_state(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'type' => 'download',
                'status' => 'succeeded',
                'transferredBytes' => 2048,
                'totalBytes' => 2048,
                'progress' => 100,
                'fileId' => self::FILE_ID,
                'displayName' => 'report.pdf',
                'mimeType' => 'application/pdf',
                'size' => 2048,
                'consumed' => true,
            ],
        );

        $result = $this->transfer($bridge)
            ->consumeResult(self::REQUEST_ID);

        self::assertTrue(
            $result->consumed,
        );

        self::assertSame(
            [
                [
                    'method' =>
                        'NativeBackgroundTransfer.ConsumeResult',
                    'parameters' => [
                        'id' => self::REQUEST_ID,
                    ],
                ],
            ],
            $bridge->calls,
        );
    }

    public function test_already_consumed_result_is_controlled(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'id' => self::REQUEST_ID,
                'type' => 'download',
                'status' => 'failed',
                'transferredBytes' => 2048,
                'totalBytes' => 2048,
                'progress' => 100,
                'errorCode' =>
                    NativeBackgroundTransferErrorCode::RESULT_ALREADY_CONSUMED,
                'consumed' => true,
            ],
        );

        $result = $this->transfer($bridge)
            ->consumeResult(self::REQUEST_ID);

        self::assertSame(
            'failed',
            $result->status,
        );

        self::assertTrue(
            $result->consumed,
        );

        self::assertSame(
            NativeBackgroundTransferErrorCode::RESULT_ALREADY_CONSUMED,
            $result->errorCode,
        );
    }

    public function test_list_transfers_filters_malformed_native_items(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [
                'transfers' => [
                    [
                        'id' => self::REQUEST_ID,
                        'type' => 'download',
                        'status' => 'running',
                        'transferredBytes' => 250,
                        'totalBytes' => 1000,
                        'progress' => 25,
                    ],
                    [
                        'id' => 'not-a-uuid',
                        'type' => 'download',
                        'status' => 'running',
                    ],
                    [
                        'id' => self::FILE_ID,
                        'type' => 'upload',
                        'status' => 'queued',
                    ],
                    'invalid-item',
                ],
            ],
        );

        $results = $this->transfer($bridge)
            ->listTransfers();

        self::assertCount(
            1,
            $results,
        );

        self::assertSame(
            self::REQUEST_ID,
            $results[0]->id,
        );

        self::assertSame(
            'running',
            $results[0]->status,
        );
    }

    public function test_invalid_status_request_id_never_reaches_bridge(): void
    {
        $bridge = new FakeNativeBridge(
            (object) [],
        );

        $result = $this->transfer($bridge)
            ->getStatus('not-a-uuid');

        self::assertSame(
            'failed',
            $result->status,
        );

        self::assertSame(
            NativeBackgroundTransferErrorCode::INVALID_REQUEST_ID,
            $result->errorCode,
        );

        self::assertSame(
            [],
            $bridge->calls,
        );
    }

    public function test_provider_resolves_same_transfer_singleton(): void
    {
        $application = new Application(
            dirname(__DIR__, 4),
        );

        $provider =
            new NativeBackgroundTransferServiceProvider(
                $application,
            );

        $provider->register();

        $first = $application->make(
            NativeBackgroundTransfer::class,
        );

        $second = $application->make(
            NativeBackgroundTransfer::class,
        );

        self::assertSame(
            $first,
            $second,
        );

        self::assertInstanceOf(
            NativeBridge::class,
            $application->make(
                NativeBridge::class,
            ),
        );
    }

    public function test_public_result_contract_has_no_private_fields(): void
    {
        $result = $this->transfer(
            new FakeNativeBridge(
                (object) [
                    'id' => self::REQUEST_ID,
                    'type' => 'download',
                    'status' => 'queued',
                    'transferredBytes' => 0,
                    'totalBytes' => null,
                    'progress' => null,
                    'consumed' => false,

                    'path' => '/private/path',
                    'url' =>
                        'https://example.com/file?secret=yes',
                    'headers' => [
                        'Authorization' =>
                            'Bearer secret',
                    ],
                ],
            ),
        )->getStatus(self::REQUEST_ID);

        self::assertSame(
            [
                'id',
                'type',
                'status',
                'transferred_bytes',
                'total_bytes',
                'progress',
                'file_id',
                'display_name',
                'mime_type',
                'size',
                'error_code',
                'error_message',
                'consumed',
            ],
            array_keys(
                $result->toArray(),
            ),
        );
    }

    private function transfer(
        FakeNativeBridge $bridge,
    ): NativeBackgroundTransfer {
        return new NativeBackgroundTransfer(
            bridge: $bridge,
        );
    }

    private function readPluginFile(
        string $relativePath,
    ): string {
        $contents = file_get_contents(
            dirname(__DIR__).'/'.$relativePath,
        );

        self::assertNotFalse(
            $contents,
        );

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(
        string $path,
    ): array {
        $contents = file_get_contents($path);

        self::assertNotFalse(
            $contents,
        );

        $decoded = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray(
            $decoded,
        );

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

    public function __construct(
        public mixed $response,
    ) {}

    public function call(
        string $method,
        array $parameters = [],
    ): ?object {
        $this->calls[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        if (is_callable($this->response)) {
            $response = ($this->response)(
                $method,
                $parameters,
            );

            return is_object($response)
                ? $response
                : null;
        }

        return is_object($this->response)
            ? $this->response
            : null;
    }
}
