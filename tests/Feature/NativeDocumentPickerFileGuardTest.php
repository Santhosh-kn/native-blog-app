<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\NativeDocumentPickerFileGuard;
use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NativeDocumentPickerFileGuardTest extends TestCase
{
    public function test_it_accepts_a_valid_private_document_without_exposing_its_path(): void
    {
        $requestId = (string) Str::uuid();
        $contents = "%PDF-1.4\nprivate fixture";
        $path = $this->fixture(
            $this->privateRoot(),
            $contents,
        );

        try {
            $selection = (new NativeDocumentPickerFileGuard)
                ->verifiedSelection(
                    $this->pickerResult(
                        id: $requestId,
                        path: $path,
                        originalName: 'report.pdf',
                        mimeType: 'application/pdf',
                        size: strlen($contents),
                    ),
                    $requestId,
                    [
                        'mime_types' => ['application/pdf'],
                        'max_size' => 1024,
                    ],
                );

            $this->assertSame([
                'request_id' => $requestId,
                'original_name' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'size' => strlen($contents),
            ], $selection);
            $this->assertArrayNotHasKey('path', $selection);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_rejects_a_document_outside_its_private_root(): void
    {
        $requestId = (string) Str::uuid();
        $contents = "%PDF-1.4\noutside fixture";

        $this->privateRoot();

        $path = $this->fixture(
            storage_path('app'),
            $contents,
        );

        try {
            $selection = (new NativeDocumentPickerFileGuard)
                ->verifiedSelection(
                    $this->pickerResult(
                        id: $requestId,
                        path: $path,
                        originalName: 'outside.pdf',
                        mimeType: 'application/pdf',
                        size: strlen($contents),
                    ),
                    $requestId,
                    [
                        'mime_types' => ['application/pdf'],
                        'max_size' => 1024,
                    ],
                );

            $this->assertNull($selection);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_rejects_an_incorrect_native_byte_size(): void
    {
        $requestId = (string) Str::uuid();
        $contents = "%PDF-1.4\nsize fixture";
        $path = $this->fixture(
            $this->privateRoot(),
            $contents,
        );

        try {
            $selection = (new NativeDocumentPickerFileGuard)
                ->verifiedSelection(
                    $this->pickerResult(
                        id: $requestId,
                        path: $path,
                        originalName: 'report.pdf',
                        mimeType: 'application/pdf',
                        size: strlen($contents) + 1,
                    ),
                    $requestId,
                    [
                        'mime_types' => ['application/pdf'],
                        'max_size' => 1024,
                    ],
                );

            $this->assertNull($selection);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_enforces_the_requested_mime_allowlist(): void
    {
        $requestId = (string) Str::uuid();
        $contents = 'plain text fixture';
        $path = $this->fixture(
            $this->privateRoot(),
            $contents,
        );

        try {
            $selection = (new NativeDocumentPickerFileGuard)
                ->verifiedSelection(
                    $this->pickerResult(
                        id: $requestId,
                        path: $path,
                        originalName: 'notes.txt',
                        mimeType: 'text/plain',
                        size: strlen($contents),
                    ),
                    $requestId,
                    [
                        'mime_types' => ['application/pdf'],
                        'max_size' => 1024,
                    ],
                );

            $this->assertNull($selection);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_enforces_the_selected_maximum_size(): void
    {
        $requestId = (string) Str::uuid();
        $contents = "%PDF-1.4\noversized fixture";
        $path = $this->fixture(
            $this->privateRoot(),
            $contents,
        );

        try {
            $selection = (new NativeDocumentPickerFileGuard)
                ->verifiedSelection(
                    $this->pickerResult(
                        id: $requestId,
                        path: $path,
                        originalName: 'report.pdf',
                        mimeType: 'application/pdf',
                        size: strlen($contents),
                    ),
                    $requestId,
                    [
                        'mime_types' => ['application/pdf'],
                        'max_size' => 5,
                    ],
                );

            $this->assertNull($selection);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_rejects_unsafe_original_filenames(): void
    {
        $requestId = (string) Str::uuid();
        $contents = "%PDF-1.4\nfilename fixture";
        $path = $this->fixture(
            $this->privateRoot(),
            $contents,
        );
        $unsafeNames = [
            '../secret.pdf',
            'folder/secret.pdf',
            'folder\\secret.pdf',
            "secret\nfile.pdf",
            '.',
            '..',
        ];

        try {
            foreach ($unsafeNames as $unsafeName) {
                $selection = (new NativeDocumentPickerFileGuard)
                    ->verifiedSelection(
                        $this->pickerResult(
                            id: $requestId,
                            path: $path,
                            originalName: $unsafeName,
                            mimeType: 'application/pdf',
                            size: strlen($contents),
                        ),
                        $requestId,
                        [
                            'mime_types' => ['application/pdf'],
                            'max_size' => 1024,
                        ],
                    );

                $this->assertNull($selection);
            }
        } finally {
            File::delete($path);
        }
    }

    public function test_it_rejects_malformed_request_options(): void
    {
        $requestId = (string) Str::uuid();
        $contents = "%PDF-1.4\noptions fixture";
        $path = $this->fixture(
            $this->privateRoot(),
            $contents,
        );
        $invalidOptions = [
            [
                'mime_types' => [],
                'max_size' => 1024,
            ],
            [
                'mime_types' => ['application/octet-stream'],
                'max_size' => 1024,
            ],
            [
                'mime_types' => ['application/pdf'],
                'max_size' => 0,
            ],
            [
                'mime_types' => ['application/pdf', 123],
                'max_size' => 1024,
            ],
        ];

        try {
            foreach ($invalidOptions as $options) {
                $selection = (new NativeDocumentPickerFileGuard)
                    ->verifiedSelection(
                        $this->pickerResult(
                            id: $requestId,
                            path: $path,
                            originalName: 'report.pdf',
                            mimeType: 'application/pdf',
                            size: strlen($contents),
                        ),
                        $requestId,
                        $options,
                    );

                $this->assertNull($selection);
            }
        } finally {
            File::delete($path);
        }
    }

    private function privateRoot(): string
    {
        $root = storage_path('app/native-document-picker');

        File::ensureDirectoryExists($root);

        return $root;
    }

    private function fixture(
        string $directory,
        string $contents,
    ): string {
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'guard-'.Str::uuid().'.pdf';

        File::put($path, $contents);

        return $path;
    }

    private function pickerResult(
        string $id,
        string $path,
        string $originalName,
        string $mimeType,
        int $size,
    ): NativeDocumentPickerResult {
        return new NativeDocumentPickerResult(
            id: $id,
            status: 'succeeded',
            success: true,
            cancelled: false,
            path: $path,
            originalName: $originalName,
            mimeType: $mimeType,
            size: $size,
        );
    }
}
