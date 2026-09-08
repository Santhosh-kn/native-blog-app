<?php

declare(strict_types=1);

namespace Tests\Feature;

use Bbs\NativeDocumentPicker\Events\NativeDocumentPickerCompleted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NativeDocumentPickerTest extends TestCase
{
    public function test_completion_event_is_cached_without_private_metadata(): void
    {
        $requestId = (string) Str::uuid();
        $privatePath = storage_path(
            'app/native-document-picker/private-document.pdf',
        );

        event(new NativeDocumentPickerCompleted(
            id: $requestId,
            success: true,
            cancelled: false,
            path: $privatePath,
            originalName: 'private-document.pdf',
            mimeType: 'application/pdf',
            size: 1234,
        ));

        $cached = Cache::get(
            "native_document_picker_result:{$requestId}",
        );

        $this->assertSame([
            'id' => $requestId,
            'status' => 'succeeded',
            'success' => true,
            'cancelled' => false,
            'error_code' => null,
            'error_message' => null,
        ], $cached);
        $this->assertIsArray($cached);
        $this->assertArrayNotHasKey('path', $cached);
        $this->assertArrayNotHasKey('original_name', $cached);
        $this->assertArrayNotHasKey('mime_type', $cached);
        $this->assertArrayNotHasKey('size', $cached);
        $this->assertFalse(in_array($privatePath, $cached, true));

        Cache::forget(
            "native_document_picker_result:{$requestId}",
        );
    }

    public function test_completion_event_with_invalid_request_id_is_not_cached(): void
    {
        $invalidRequestId = 'not-a-valid-request-id';

        event(new NativeDocumentPickerCompleted(
            id: $invalidRequestId,
            success: false,
            cancelled: true,
        ));

        $this->assertNull(Cache::get(
            "native_document_picker_result:{$invalidRequestId}",
        ));
    }
}
