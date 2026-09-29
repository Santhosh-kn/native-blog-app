## bbs/plugin-native-passkeys

Android-only NativePHP Mobile passkey bridge using AndroidX Credential Manager.

### Scope

This package provides the native client contract only. It does not generate or verify WebAuthn challenges.

A real flow requires a public HTTPS backend, a valid relying-party ID, server-side verification, and Android Digital Asset Links.

Do not invent a domain or claim end-to-end passkey support in a local-only application.

### Available methods

- `NativePasskeys::isAvailable()`
- `NativePasskeys::create(string $requestJson, ?string $id = null)`
- `NativePasskeys::authenticate(string $requestJson, ?string $id = null)`
- `NativePasskeys::getStatus(string $id)`
- `NativePasskeys::consumeResult(string $id)`
- `NativePasskeys::cancel(string $id)`

### PHP usage

@verbatim
<code-snippet name="Starting a Passkey Request" lang="php">
use Bbs\NativePasskeys\Facades\NativePasskeys;

$start = NativePasskeys::create(
    requestJson: $serverGeneratedCreationOptionsJson,
);

$status = NativePasskeys::getStatus($start->id);
$result = NativePasskeys::consumeResult($start->id);
</code-snippet>
@endverbatim

### Result handling

`GetStatus` returns status metadata without credential JSON.

`ConsumeResult` exposes credential response JSON once and redacts the persisted copy.

Send successful credential responses to the HTTPS backend for verification. Never authenticate a user from the native response alone.

### Events

@verbatim
<code-snippet name="Listening for Passkey Completion" lang="php">
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
</code-snippet>
@endverbatim

Events contain status metadata only. Credential responses are not included.

### Security requirements

- Accept only server-generated WebAuthn JSON.
- Never hard-code a relying-party domain.
- Never log challenge or credential payloads.
- Verify every credential response on the backend.
- Use SHA-256 fingerprints for Digital Asset Links.
- Keep release and debug signing fingerprints separate.
