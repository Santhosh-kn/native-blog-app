# Native Document Picker for NativePHP Mobile

`bbs/plugin-native-document-picker` provides secure, single-document selection for Android NativePHP Mobile applications.

It opens Android's Storage Access Framework picker, immediately copies the selected `content://` document into application-private storage, and returns only UTF-8-safe metadata to Laravel.

## Requirements

- PHP 8.4 or later
- NativePHP Mobile 4.2 or later
- Android API level 21 or later
- Android is the only supported runtime in version 1.0.0

## Installation

    composer require bbs/plugin-native-document-picker

For local development, register the package directory as a Composer path repository before requiring it.

## Start a picker request

    use Bbs\NativeDocumentPicker\Facades\NativeDocumentPicker;
    use Illuminate\Support\Str;

    $requestId = (string) Str::uuid();

    $start = NativeDocumentPicker::pick([
        'id' => $requestId,
        'mime_types' => [
            'application/pdf',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'max_size' => 20 * 1024 * 1024,
    ]);

    if (! $start->accepted) {
        $errorCode = $start->errorCode;
        $errorMessage = $start->errorMessage;
    }

The `id` option is optional. If it is omitted, the plugin generates a UUID and returns it as `$start->id`. Preserve this ID because native selection completes asynchronously.

The default maximum size is 20 MiB. The configurable range is 1 byte through 1 GiB.

## Listen for completion

    use Bbs\NativeDocumentPicker\Events\NativeDocumentPickerCompleted;
    use Native\Mobile\Attributes\OnNative;

    #[OnNative(NativeDocumentPickerCompleted::class)]
    public function handleDocumentPickerCompleted(
        string $id,
        bool $success,
        bool $cancelled,
        ?string $path = null,
        ?string $originalName = null,
        ?string $mimeType = null,
        ?int $size = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        // Correlate the ID with a request owned by the current session.
    }

A successful event contains the private path, sanitized original filename, MIME type, and byte size. Cancellation and failure events contain only safe error metadata.

## Recover request status

    $result = NativeDocumentPicker::getStatus($requestId);

    if ($result->status === 'succeeded') {
        $privatePath = $result->path;
    }

Status values are:

- `pending`
- `succeeded`
- `cancelled`
- `failed`
- `not_found`

The recovered result exposes:

- `id`
- `status`
- `success`
- `cancelled`
- `path`
- `originalName`
- `mimeType`
- `size`
- `errorCode`
- `errorMessage`

## Error codes

Stable validation and native error codes include:

- `INVALID_REQUEST_ID`
- `INVALID_MIME_TYPES`
- `INVALID_MAX_SIZE`
- `ACTIVITY_UNAVAILABLE`
- `PICKER_UNAVAILABLE`
- `PICKER_BUSY`
- `PICKER_LAUNCH_FAILED`
- `UNSUPPORTED_MIME_TYPE`
- `FILE_TOO_LARGE`
- `SOURCE_UNREADABLE`
- `INVALID_DESTINATION`
- `PRIVATE_STORAGE_FAILED`
- `COPY_FAILED`
- `RESULT_NOT_FOUND`
- `RESULT_PERSISTENCE_FAILED`
- `UNKNOWN_ERROR`

## Security guarantees

- No broad Android storage permission is requested.
- Documents are copied into application-private storage immediately.
- Source document URIs are not persisted or returned to Laravel.
- Sanitized filenames cannot control the private destination.
- Both declared and copied byte sizes are validated.
- Partial files are removed when copying or persistence fails.
- Document bytes and encoded document content never cross the NativePHP string bridge.
- Private paths and document contents must never be logged.
- Applications must authorize later access through their own policies.

Private PDF paths can be passed to a compatible native PDF preview or printing plugin. Other private documents can be shared through an authorized native file-sharing flow.

## License

MIT
