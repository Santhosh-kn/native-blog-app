## bbs/plugin-native-document-picker

Use this plugin for secure, single-document selection on Android through the Storage Access Framework.

The selected `content://` document is copied immediately into application-private storage. Only UTF-8-safe metadata is returned to Laravel.

### Installation

    composer require bbs/plugin-native-document-picker

### Start a request

Always use the PHP facade. Do not call the native bridge directly because the service provider supplies and validates the private destination.

@verbatim
<code-snippet name="Starting a document-picker request" lang="php">
use Bbs\NativeDocumentPicker\Facades\NativeDocumentPicker;
use Illuminate\Support\Str;

$requestId = (string) Str::uuid();

$start = NativeDocumentPicker::pick([
    'id' => $requestId,
    'mime_types' => [
        'application/pdf',
        'text/plain',
    ],
    'max_size' => 20 * 1024 * 1024,
]);

if (! $start->accepted) {
    $errorCode = $start->errorCode;
    $errorMessage = $start->errorMessage;
}
</code-snippet>
@endverbatim

The `id` option may be omitted. In that case, preserve the generated ID returned as `$start->id`.

The default maximum size is 20 MiB. The supported configurable range is 1 byte through 1 GiB.

### Completion event

Listen for the typed `NativeDocumentPickerCompleted` event using `#[OnNative]`.

@verbatim
<code-snippet name="Listening for document-picker completion" lang="php">
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
    // Confirm that the request ID belongs to the current session.
}
</code-snippet>
@endverbatim

The event carries only:

- Request ID
- Success and cancellation flags
- Application-private path
- Sanitized original filename
- MIME type
- Byte size
- Safe error code and message

### Status recovery

Use `getStatus(string $id)` after navigation, activity recreation, or application relaunch.

@verbatim
<code-snippet name="Recovering document-picker status" lang="php">
$result = NativeDocumentPicker::getStatus($requestId);

if ($result->status === 'succeeded') {
    $privatePath = $result->path;
}
</code-snippet>
@endverbatim

Valid result statuses are:

- `pending`
- `succeeded`
- `cancelled`
- `failed`
- `not_found`

### Security requirements

- Treat the private path as sensitive metadata.
- Never display or log a complete private path.
- Never return document bytes or encoded document content through the NativePHP string bridge.
- Never persist or expose the source `content://` URI.
- Never add broad Android storage permissions.
- Enforce both declared-size and copied-byte limits.
- Keep filename sanitization and private-path validation enabled.
- Remove partial files after copy or persistence failures.
- Correlate every result with a request owned by the current user or session.
- Authorize preview, printing, download, or sharing through the owning application resource.
- Do not move selected files into public storage merely to make them accessible.
- Do not add a client-side wrapper that accepts a caller-controlled destination path.

Version 1.0.0 is Android-only and selects one document per request.
