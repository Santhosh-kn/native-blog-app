# Native NFC Reader & Writer

An Android-only NFC reader and writer plugin for NativePHP Mobile.

The plugin reads and writes NDEF plain-text records using Android NFC Reader Mode.

## Requirements

- PHP `^8.4`
- NativePHP Mobile `^4.2`
- Android API 33 or later
- An Android device with NFC hardware

NFC hardware is optional at installation time. Check availability before starting an operation.

## Installation

```bash
composer require bbs/plugin-native-nfc
```

## PHP API

Use the facade:

```php
use Bbs\NativeNfc\Facades\NativeNfc;
```

### Check NFC availability

```php
$availability = NativeNfc::isAvailable();
```

The response contains `supported`, `enabled`, and `availability`. Availability is `available`, `unavailable`, or `disabled`.

### Start scanning

```php
$result = NativeNfc::startScan();

// Or provide a UUID v4 request ID.
$result = NativeNfc::startScan($requestId);
```

Scanning is asynchronous. After starting the session, bring an NFC tag near the device.

### Start writing

```php
$result = NativeNfc::startWrite('Hello from NativePHP');

// Or provide a UUID v4 request ID.
$result = NativeNfc::startWrite('Hello from NativePHP', $requestId);
```

Writing is asynchronous. Bring a writable NDEF-compatible tag near the device after starting the session. A successful start means only that the operation was accepted; it does not mean the tag has been written.

### Get operation status

```php
$status = NativeNfc::getStatus($requestId);
```

Status metadata does not contain scanned text.

Possible statuses are `pending`, `read`, `written`, `cancelled`, `failed`, `unknown`, and `not_found`.

### Stop an operation

```php
$status = NativeNfc::stopSession($requestId);
```

A session can be cancelled while waiting for a tag. Once tag processing has started, cancellation may no longer be possible.

### Consume a scanned result

```php
$text = NativeNfc::consumeResult($requestId);
```

Only a completed scan result can be consumed. The scanned text is returned once; subsequent consumption attempts are rejected.

## Completion events

The plugin emits `Bbs\NativeNfc\Events\NativeNfcCompleted`.

The event contains completion metadata: `id`, `operation`, `status`, `consumed`, `createdAtMs`, `completedAtMs`, and `errorCode`. It does not contain scanned text. Retrieve the text explicitly with `consumeResult($id)`.

Use the NativePHP event-listening mechanism supported by the application to handle this event. The event class exposes the completion metadata as public readonly properties.

## Text and tag limitations

- Only NDEF plain-text records are supported.
- Input text is limited to 4 KB in UTF-8.
- The tag must have enough capacity for the encoded NDEF message.
- Read-only, unsupported, and non-NDEF tags cannot be written.
- Tag UIDs are not exposed.

## Errors

PHP API errors are reported using `Bbs\NativeNfc\Exceptions\NativeNfcException`. Its `errorCode` property contains the plugin error code.

Possible error codes include `nfc_unavailable`, `nfc_disabled`, `activity_unavailable`, `operation_busy`, `unsupported_tag`, `tag_not_ndef`, `tag_read_only`, `tag_too_small`, `text_too_large`, `tag_lost`, `io_error`, `result_not_ready`, `result_already_consumed`, `result_expired`, `operation_interrupted`, and `unknown_error`.

## Reliability

- Only one NFC operation can be active at a time.
- Request IDs must be UUID v4 values.
- Completion metadata is persisted.
- Scanned text is held temporarily and delivered only through explicit one-time consumption.
- An interrupted operation can have an `unknown` outcome.
- An uncertain write is never automatically retried or reported as successful.

## License

MIT
