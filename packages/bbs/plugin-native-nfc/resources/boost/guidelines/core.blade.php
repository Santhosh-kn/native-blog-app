## bbs/plugin-native-nfc

An Android-only NFC reader and writer plugin for NativePHP Mobile. It reads and writes NDEF plain-text records using Android NFC Reader Mode.

### Requirements

- PHP `^8.4`
- NativePHP Mobile `^4.2`
- Android API 33 or later
- An Android device with NFC hardware

### Installation

```bash
composer require bbs/plugin-native-nfc
```

### PHP API

Use the facade:

```php
use Bbs\NativeNfc\Facades\NativeNfc;
```

#### NFC availability

```php
$availability = NativeNfc::isAvailable();
```

Response fields: `supported`, `enabled`, and `availability`. Availability can be `available`, `unavailable`, or `disabled`.

#### Start a scan

```php
$result = NativeNfc::startScan();
// Optionally supply a UUID v4 request ID.
$result = NativeNfc::startScan($requestId);
```

The scan is asynchronous. The user must bring an NFC tag near the device after the session starts.

#### Start a write

```php
$result = NativeNfc::startWrite('Hello from NativePHP');
// Optionally supply a UUID v4 request ID.
$result = NativeNfc::startWrite('Hello from NativePHP', $requestId);
```

Writing is asynchronous. The user must bring a writable NDEF-compatible tag near the device. A successful start does not confirm that the tag was written.

#### Get status

```php
$status = NativeNfc::getStatus($requestId);
```

Status metadata does not contain the scanned text. Statuses: `pending`, `read`, `written`, `cancelled`, `failed`, `unknown`, `not_found`.

#### Stop a session

```php
$status = NativeNfc::stopSession($requestId);
```

Stop cancels a session that is still waiting for a tag. Once tag processing has begun, cancellation may no longer be possible.

#### Consume a scan result

```php
$text = NativeNfc::consumeResult($requestId);
```

The result is delivered once. Subsequent consumption attempts are rejected.

### Events

Completion event class: `Bbs\NativeNfc\Events\NativeNfcCompleted`.

The event contains metadata only: `id`, `operation`, `status`, `consumed`, `createdAtMs`, `completedAtMs`, and optional `errorCode` when present. It does not contain the scanned text. Use `consumeResult($id)` to retrieve the text.

### Limitations

- Only NDEF plain-text records are supported.
- Input text limit: 4 KB in UTF-8.
- Tag capacity must suffice for the encoded message.
- Read-only and non-NDEF tags cannot be written.
- Tag UIDs are not exposed.
- Only one operation can be active at a time.

### Error handling

PHP errors are thrown as `Bbs\NativeNfc\Exceptions\NativeNfcException`. The exception's `errorCode` property contains the error code.

### Reliability and privacy

- Completion metadata is persisted.
- Scanned text is kept temporarily in memory and not in status metadata.
- Scanned text is returned only through explicit one-time consumption.
- An interrupted operation can have an `unknown` outcome.
