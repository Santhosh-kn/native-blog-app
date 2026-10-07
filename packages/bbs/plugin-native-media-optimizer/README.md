# Native Media Optimizer

Android image resizing and encoding, video transcoding and trimming, media inspection, and video thumbnails for NativePHP Mobile. Processing uses private copies created by `bbs/plugin-native-document-picker`; bridge requests accept document IDs, never caller paths or URLs. Originals are preserved.

## Requirements and registration

- PHP 8.4, NativePHP Mobile 4.2.x, and Android API 33 or newer.
- Register both the Native Document Picker and this plugin. This package has no iOS implementation and requests no additional Android permissions.
- Video export uses AndroidX Media3 Transformer, Effect, and Common 1.11.1, declared in `nativephp.json`. Actual processing depends on the device's codecs, available memory, and storage.

The application installs this package through its local Composer path repository. For a project with that repository configured:

```powershell
composer require bbs/plugin-native-media-optimizer:1.0.0
php artisan native:plugin:register bbs/plugin-native-media-optimizer
```

Rebuild the NativePHP Android application after registration or Kotlin changes.

## PHP API

```php
use Bbs\NativeMediaOptimizer\Facades\NativeMediaOptimizer;

$capabilities = NativeMediaOptimizer::isAvailable();

// Use a successful Native Document Picker result's document ID.
$job = NativeMediaOptimizer::optimizeImage($documentId, [
    'max_width' => 1920,
    'max_height' => 1920,
    'format' => 'jpeg',
    'quality' => 80,
]);

// Acceptance is a persisted job acknowledgement, not completed processing.
if ($job->accepted) {
    $status = NativeMediaOptimizer::getStatus($job->id);
}
```

| PHP method | Bridge function | Behavior |
| --- | --- | --- |
| `isAvailable()` | `NativeMediaOptimizer.IsAvailable` | Reports `platform`, `available`, `images`, `video`, and `thumbnails` capability hints. |
| `inspectMedia($documentId, $options = [])` | `NativeMediaOptimizer.InspectMedia` | Starts inspection; successful input metadata has no output file. |
| `optimizeImage($documentId, $options = [])` | `NativeMediaOptimizer.OptimizeImage` | Fits an image within a bounding box and encodes JPEG, PNG, or WebP. |
| `optimizeVideo($documentId, $options = [])` | `NativeMediaOptimizer.OptimizeVideo` | Encodes H.264/AAC MP4 with optional trimming or audio removal. |
| `generateThumbnail($documentId, $options = [])` | `NativeMediaOptimizer.GenerateThumbnail` | Extracts a nearby decoded video frame and encodes an image. |
| `getStatus($id)` | `NativeMediaOptimizer.GetStatus` | Reads persisted status and reconciles output availability. |
| `cancel($id)` | `NativeMediaOptimizer.Cancel` | Requests cancellation; query until a terminal state. |
| `getResult($id)` | `NativeMediaOptimizer.GetResult` | Reads the current record without consuming or deleting it. |
| `deleteOutput($id)` | `NativeMediaOptimizer.DeleteOutput` | Deletes a retained terminal output while keeping result metadata. |

The four start methods generate lowercase UUID v4 job IDs. An explicit `id` option is supported for callers that persist ownership before starting a request. Use a fresh ID for each start; duplicate starts are rejected. If a bridge acknowledgement is unavailable, query that same ID before starting another job. Unknown options, string-valued numbers, and explicit null settings are rejected.

### Image and thumbnail options

`OptimizeImage` defaults to `max_width=1920`, `max_height=1920`, `format=jpeg`, `quality=80`. Dimensions range from 1 to 4096; output area is at most 16,777,216 pixels. Aspect ratio is preserved, images are never enlarged, and all eight EXIF orientations are applied once. JPEG flattens transparency onto white; PNG and WebP retain alpha. Re-encoding does not promise to retain source EXIF or other metadata.

`GenerateThumbnail` defaults to a 512 by 512 bounding box, JPEG quality 80, and `timestamp_ms=0`. Each requested edge is at most 2048. The timestamp must be before the inspected video duration. Android chooses the closest decoded frame; the timestamp does not promise an exact frame boundary.

For either image operation, `format` is `jpeg`, `png`, or `webp`. JPEG/WebP quality is an integer from 1 to 100. Omit `quality` for PNG.

### Video options

```php
$job = NativeMediaOptimizer::optimizeVideo($documentId, [
    'max_width' => 1920,
    'max_height' => 1080,
    'video_bitrate' => 2_500_000,
    'audio_bitrate' => 128_000,
    'start_ms' => 1000,
    'end_ms' => 6000,
    'remove_audio' => false,
]);
```

These are the default dimensions and bitrates. Defaults for the remaining options are `start_ms=0`, no end time, and `remove_audio=false`. Omit `end_ms` to process through the end. Requested times must fit within the source duration, and end must exceed start. Container duration has frame/audio-packet granularity.

Requested dimensions must be even integers from 16 to 1920, with area at most 2,073,600 pixels. The fitted dimensions are rounded down to even values without enlarging the source. Video bitrate ranges from 128,000 to 20,000,000 bits/s; audio bitrate ranges from 32,000 to 320,000 bits/s. Retained audio is transcoded to AAC. Silent input remains silent.

Export supports SDR, unencrypted, square-pixel sources with one video track and at most one audio track. HDR, encrypted media, unsupported codecs, and unsuitable track layouts are rejected. Capability flags indicate codec presence; they do not guarantee that every file or requested configuration can be processed. The output is a real transcode, and a smaller file is not guaranteed.

## Limits and known input limitation

Image inputs are limited to 100 MiB; video inputs and encoded outputs to 512 MiB. Inspection bounds source area to 100,000,000 pixels and video duration to one hour. Video encoding and thumbnails additionally bound source frame area to 16,777,216 pixels. Memory and disk checks can reject an input within these outer limits. Requests are limited to 8 KiB and bridge responses to 16 KiB.

**Video duration must be reported as a positive value by Android's media retriever.** Some valid fragmented MP4 files contain zero-duration headers and do not expose a usable duration on the device. Those inputs currently fail inspection (`LIMIT_EXCEEDED` for zero duration, or `DECODE_FAILED` for missing/unreadable duration). The plugin does not infer a duration from frame count. A compatible regular MP4 remux can work, but fragmented-MP4 duration recovery is not implemented.

## Job and output lifecycle

One job runs at a time. Other starts return `BUSY`; callers should queue requests in their application. Accepted jobs move through `pending`, `running`, and optionally `cancelling`, then finish as `succeeded`, `failed`, `cancelled`, or `interrupted`. A missing record reports `not_found`. Progress is monotonic, may be unknown during processing, and reaches 100 only on success.

`accepted` means the native record was persisted. `success` means accepted and succeeded. `isTerminal()` describes a result state; a local bridge failure can still leave native acceptance uncertain, so reconcile its ID. Errors expose controlled codes and messages rather than native exception details.

`Bbs\NativeMediaOptimizer\Events\NativeMediaOptimizerCompleted` carries job ID, operation, terminal status, and controlled error metadata. Events are best effort; persisted status is authoritative. Poll after reopening the page or when an event is missed.

Jobs run inside the application process, independently of the activity. This is not a foreground service or a resumable Android background task. After process death, unfinished records become `interrupted` with `PROCESS_INTERRUPTED`; owned incomplete files are cleaned up. Retry using a new job ID.

Outputs use fixed UUID filenames in private `storage/app/native-media-optimizer`. Source files remain in the picker's separate private directory. `getResult()` does not consume outputs. A successful output can later have `outputAvailable=false` after deletion or reconciliation. Terminal metadata remains available until eligible history eviction. Native history holds at most 50 records and preserves records with live outputs; delete unneeded outputs to free capacity.

PHP results retain a private path for trusted server-side guards. `toMetadata()` and JSON serialization omit that path. Do not send raw result properties, file paths, source names, or native exception text to the UI or logs. A job ID alone is not authorization: applications must bind picker and job IDs to their authenticated user/session before entering native code.

Optimizer output IDs are not picker document IDs. They cannot be passed directly to the existing picker-based Background Transfer upload API; an explicit owned-output integration would be required.

## Included application demo

The app's authenticated `/native-media-optimizer` page provides selection, settings, status, cancellation, preview, Android sharing, and output deletion. Ownership is bound to both the user and session. Its 50-job history preserves active jobs and jobs with retained outputs; deleted, failed, or inspection-only entries can make room for new jobs. Older sessions retain potentially live outputs until a status query reconciles them.

Inline previews use bounded base64 JSON transport because NativePHP's local HTTP response is string-based. The preview limit is 12 MiB; larger outputs use Android Open / Share. Preview and share paths are derived from guarded owned results. Sharing submits an Android chooser request; it does not prove that a recipient received the file.

## Validation

From the repository root:

```powershell
php .\vendor\phpunit\phpunit\phpunit --configuration .\packages\bbs\plugin-native-media-optimizer\phpunit.xml
php .\vendor\phpunit\phpunit\phpunit --configuration .\phpunit.xml --display-skipped
```

Android instrumentation sources belong to this package. After plugin registration, run from the repository root with an attached emulator/device:

```powershell
& {
    $project = (Get-Location).Path
    $package = Join-Path $project 'packages\bbs\plugin-native-media-optimizer'
    $init = Join-Path $package 'tests\android\media-optimizer-tests.init.gradle'
    $testSources = Join-Path $package 'tests\android\instrumentation'
    Push-Location (Join-Path $project 'nativephp\android')
    try {
        .\gradlew.bat -I $init `
            "-PmediaOptimizerTestSourceDir=$testSources" `
            "-Pandroid.testInstrumentationRunnerArguments.package=com.bbs.plugins.native_media_optimizer" `
            :app:connectedDebugAndroidTest --console=plain
        if ($LASTEXITCODE -ne 0) { throw 'Optimizer Android tests failed.' }
    } finally { Pop-Location }
}
```

Omit `mediaOptimizerMainSourceDir` after registration; NativePHP already includes the plugin sources. The isolated foundation checks used that property before registration. On Windows, the HTTP symlink-rejection test is skipped when the machine cannot create symlinks.

## License

MIT. See [LICENSE](LICENSE).
