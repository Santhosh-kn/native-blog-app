# Native Passkeys for NativePHP Mobile

Android-first NativePHP Mobile bridge for passkey registration and authentication through AndroidX Credential Manager.

## Current scope

This package provides the Android native contract, payload validation, request lifecycle, cancellation, app-private result persistence, one-time result consumption, completion events, and mocked PHP tests.

It does not provide a WebAuthn server. A local-only application cannot complete real passkey registration or authentication.

Production passkeys require:

- Android API level 28 or newer
- A public HTTPS backend
- A valid WebAuthn relying-party ID
- Server-generated challenges and WebAuthn options
- Server-side registration and authentication verification
- Android Digital Asset Links using SHA-256 fingerprints
- A separate production release signing fingerprint

The manifest contains an inert iOS schema section only for NativePHP validation. The plugin remains Android-only.

## Bridge methods

- `NativePasskeys.IsAvailable`
- `NativePasskeys.Create`
- `NativePasskeys.Authenticate`
- `NativePasskeys.GetStatus`
- `NativePasskeys.ConsumeResult`
- `NativePasskeys.Cancel`

## PHP usage

~~~php
use Bbs\NativePasskeys\Facades\NativePasskeys;

$availability = NativePasskeys::isAvailable();

$start = NativePasskeys::create(
    requestJson: $serverGeneratedCreationOptionsJson,
);

$status = NativePasskeys::getStatus($start->id);

$result = NativePasskeys::consumeResult($start->id);

if ($result->success) {
    $credentialResponseJson = $result->responseJson;

    // Send the response to the HTTPS backend for verification.
}
~~~

Authentication uses the same lifecycle:

~~~php
$start = NativePasskeys::authenticate(
    requestJson: $serverGeneratedAuthenticationOptionsJson,
);
~~~

The optional request ID must be a UUID. If omitted, the PHP layer creates one.

## Result lifecycle

A request progresses through:

- `pending`
- `succeeded`
- `cancelled`
- `failed`

An unknown request returns `not_found`.

`GetStatus` never exposes credential response JSON. `ConsumeResult` returns it only once and then redacts it from app-private persistence.

Completion events contain status metadata only:

~~~php
use Bbs\NativePasskeys\Events\NativePasskeysCompleted;
use Native\Mobile\Attributes\OnNative;

#[OnNative(NativePasskeysCompleted::class)]
public function handlePasskeyCompletion(
    string $id,
    string $operation,
    string $status,
    bool $success,
    bool $cancelled,
    ?string $errorCode = null,
    ?string $errorMessage = null,
): void {
    $result = NativePasskeys::consumeResult($id);
}
~~~

## Security rules

- Never generate or verify WebAuthn challenges in the mobile client.
- Never invent or hard-code a relying-party domain.
- Never treat a Credential Manager response as authenticated until the backend verifies it.
- Never log request options, credential responses, signatures, challenges, tokens, or user handles.
- Never use SHA-1 fingerprints for Digital Asset Links.
- Always transmit credential responses through authenticated HTTPS.

## Testing

~~~bash
composer test
~~~

The current tests use mocked native bridge responses. End-to-end testing remains blocked until an HTTPS relying-party backend and Digital Asset Links are available.

## License

MIT
