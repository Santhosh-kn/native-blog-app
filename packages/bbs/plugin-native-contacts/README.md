# Native Contacts for NativePHP Mobile

Android system contact selection, contact editor launch, and contact viewing.

Version 1.0.0 requires PHP 8.4+, NativePHP Mobile 4.2+, and Android API 33+.
Android is the only supported platform. Calendar integration is not included.

## Installation

For a local checkout, register the package's Composer path repository:

```powershell
composer config repositories.native-contacts path packages/bbs/plugin-native-contacts
composer require bbs/plugin-native-contacts:1.0.0
```

Enable this provider in the application's `NativeServiceProvider::plugins()` array:

```php
\Bbs\NativeContacts\NativeContactsServiceProvider::class,
```

Verify registration and rebuild the Android application:

```powershell
php artisan native:plugin:list
php artisan native:plugin:validate packages/bbs/plugin-native-contacts
php artisan native:run android
```

## PHP API

```php
use Bbs\NativeContacts\Facades\NativeContacts;
```

| Method | Purpose |
| --- | --- |
| `isAvailable()` | Check operation availability without reading contact data. |
| `pick(array $options = [])` | Select a contact, phone number, or email through system UI. |
| `create(array $options = [])` | Launch a contact editor with optional prefills. |
| `open($uri, array $options = [])` | Launch contact viewing UI for a validated contact URI. |
| `getStatus($id)` | Retrieve request metadata without selected contact data. |
| `consumeResult($id)` | Retrieve selected data once and redact its stored result. |

### Start a picker request

```php
$request = NativeContacts::pick(['mode' => 'contact']);

if ($request->accepted) {
    // Associate $request->id with the authorized initiating user.
    // Retrieve status later using this owned request ID.
}
```

Supported modes are `contact`, `phone`, and `email`. The default is `contact`.

An accepted request is asynchronous and initially pending. Acceptance does not
mean that a contact has been selected.

Request IDs are generated automatically when omitted. An explicitly supplied
`id` must be a unique lowercase UUID v4. Only one native operation can be pending
at a time.

### Retrieve status

```php
// Check ownership before using an ID supplied by a client.
$status = NativeContacts::getStatus($ownedRequestId);
$metadata = $status->toMetadata();
```

Statuses are `pending`, `selected`, `launched`, `cancelled`, `failed`, `unknown`,
and `not_found`.

`launched` means that Android accepted an editor or viewer launch. It does not
confirm that the user saved a contact.

`unknown` can indicate an interrupted operation whose outcome cannot be recovered.

### Consume selected data once

```php
$status = NativeContacts::getStatus($ownedRequestId);

if (
    $status->status === 'selected'
    && ! $status->consumed
    && $status->errorCode === null
) {
    $result = NativeContacts::consumeResult($ownedRequestId);
    $selection = $result->selectedData();

    if ($result->errorCode === null && $selection !== null) {
        // Use privately for the authorized initiating user.
        // Do not log or expose the selection in diagnostic output.
    }

    unset($selection);
}
```

Inspect `errorCode` and `selectedData()` to establish whether data was delivered.
The `success` flag alone does not establish delivery.

| Picker mode | Selected fields |
| --- | --- |
| `contact` | `contactUri`, optional `displayName` |
| `phone` | `phoneNumber`, optional `displayName` |
| `email` | `emailAddress`, optional `displayName` |

Phone and email selections do not promise a contact URI.

Status responses, JSON serialization, and `toMetadata()` exclude selected fields.
Selected data is available only through `selectedData()` on the consume response.

Consumption is at most once. The native store verifies durable redaction before
returning selected data. A crash after redaction can lose delivery; the result
cannot be replayed. Repeated consumption returns `RESULT_ALREADY_CONSUMED`.
Missing request IDs return `RESULT_NOT_FOUND`.

### Launch a contact editor

```php
$request = NativeContacts::create([
    'name' => 'Native Contacts Demo',
    'phone' => '+1 202-555-0100',
    'email' => 'native.contacts@example.invalid',
]);
```

All three prefills are optional. The user controls saving or discarding changes
in the Android editor. Editor prefills are not persisted in the request store.

### Open a selected contact

After consuming a contact-mode result, pass its validated `contactUri` to:

```php
$request = NativeContacts::open($selection['contactUri']);
```

Check that the selection exists before accessing this field. Opening creates a
new request. Failure to launch the viewer does not undo the earlier consumption.

Supported URI forms use the `content` scheme and `com.android.contacts` authority:

- `/contacts/{positive-id}`
- `/contacts/lookup/{lookup-key}`
- `/contacts/lookup/{lookup-key}/{positive-id}`

Credentials, ports, queries, fragments, and control characters are rejected.
Arbitrary content-provider URIs are not supported.

## Completion events

The plugin emits `Bbs\NativeContacts\Events\NativeContactsCompleted`.

Events contain request and outcome metadata only. They never contain selected
contact details. Authorize and correlate the request ID, then retrieve status.

Delivery is best effort. Events can be delayed, absent, or duplicated. Event
handlers should be idempotent and should not automatically consume selections.

## Privacy and lifecycle

The plugin does not request `READ_CONTACTS`, `WRITE_CONTACTS`, or
`QUERY_ALL_PACKAGES`.

A post-compile hook adds five targeted Android package-visibility queries for
picker, editor, and viewer intents. Verify the packaged manifest after building;
a framework hook warning does not prove that configuration succeeded.

The selection reader queries only the URI returned by the picker. It performs
no collection queries, secondary lookups, or persisted URI grants. Provider reads
run off the main thread with a bounded deadline.

Private request state uses Android's no-backup directory:

- Selected data expires after 10 minutes.
- Request metadata expires after 24 hours.
- Storage is capped at 100 results.
- Expiry is enforced during access and lifecycle reconciliation.

Cleanup cannot run while the application process is absent. Redaction removes
the selection from logical stored state; it is not a forensic secure-erase promise.

Picker restoration requires a matching saved Android request binding.
Unrecoverable requests become `unknown`; they are not automatically relaunched.
An interrupted provider read is not retried using a persisted selection URI.

There is no public JavaScript SDK, direct contact-write API, or cancel operation
in version 1.

## Application diagnostics

This repository includes `/native-contacts`, protected by authentication and
device-unlock middleware.

The diagnostics page stores owned request IDs and displays metadata only.
Responses use `no-store`. Its consume action reports receipt and discards selected
details. Its open-selection action uses a consumed contact URI internally.

## Verification

From the application root:

```powershell
php vendor/bin/phpunit --configuration packages/bbs/plugin-native-contacts/phpunit.xml
php vendor/bin/phpunit --filter NativeContactsHttpTest
```

Android instrumentation sources and their Gradle init script are in
`tests/android`. Rebuild the native application before running instrumentation.

Validation recorded on 2026-10-05:

- Package PHP tests: 95 tests, 337 assertions.
- HTTP integration tests: 44 tests, 361 assertions.
- Android instrumentation: 59 tests, no failures, errors, or skips.
- Instrumentation covers request storage, atomic files, selection reading, and read-completion races.
- API 36 emulator checks cover availability, missing results, cancellation, all three selection modes, consumption, replay rejection, editor launch, and viewer launch.
- Picker recovery after process death and replay rejection after a subsequent cold restart were verified.
- The packaged Android manifest contains no broad contact or package-query permissions.

Other Android versions and contact-provider implementations require additional
device coverage.

## License

MIT
