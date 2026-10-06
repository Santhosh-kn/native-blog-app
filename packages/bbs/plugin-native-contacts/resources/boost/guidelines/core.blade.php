# Native Contacts

Package: `bbs/plugin-native-contacts`.
PHP namespace: `Bbs\NativeContacts`.
Bridge namespace: `NativeContacts`.

Version 1 supports Android API 33+, PHP 8.4+, and NativePHP Mobile 4.2+.
Calendar and iOS support are not implemented.

## Public PHP API

Use `Bbs\NativeContacts\Facades\NativeContacts`:

- `isAvailable()` checks operation availability without reading contact data.
- `pick(array $options = [])` accepts mode `contact`, `phone`, or `email`.
- `create(array $options = [])` accepts optional `name`, `phone`, and `email`.
- `open($uri, array $options = [])` launches a validated contact URI.
- `getStatus($id)` returns metadata without selected contact fields.
- `consumeResult($id)` provides selected data at most once.

There is no `execute()` method, public JavaScript SDK, or cancel operation.

@verbatim
<code-snippet name="Start a native contact picker" lang="php">
use Bbs\NativeContacts\Facades\NativeContacts;

$request = NativeContacts::pick(['mode' => 'contact']);

if ($request->accepted) {
    // Associate $request->id with the authorized initiating user.
    // Retrieve status later; acceptance does not mean selection succeeded.
}
</code-snippet>
@endverbatim

## Requests and results

- Requests are asynchronous. Only one native operation can be pending.
- Omitted IDs are generated. Explicit IDs must be unique lowercase UUID v4.
- Authorize request ownership before status, consume, or open-selection actions.
- Statuses: `pending`, `selected`, `launched`, `cancelled`, `failed`, `unknown`, `not_found`.
- `launched` confirms an editor or viewer launch, not that a contact was saved.
- Inspect `errorCode` and `selectedData()` to establish selected-data delivery.
- The `success` flag alone does not establish delivery.
- Only the consume response can expose selected fields through `selectedData()`.
- Contact mode returns `contactUri` and optional `displayName`.
- Phone mode returns `phoneNumber` and optional `displayName`.
- Email mode returns `emailAddress` and optional `displayName`.
- Phone and email selections do not promise a contact URI.
- JSON serialization and `toMetadata()` exclude selected contact fields.
- Repeated consumption returns `RESULT_ALREADY_CONSUMED`.
- Missing request IDs return `RESULT_NOT_FOUND`.

Consumption verifies durable redaction before returning data. A crash after
redaction can lose delivery. Never promise replay or exactly-once delivery.

Opening a consumed contact URI creates a new request. Viewer launch failure
does not undo consumption. Editor prefills are not persisted in request state.

## Privacy and lifecycle

Never log selected names, phone numbers, email addresses, contact URIs, or raw
provider exceptions. Keep selected data out of diagnostics and public state.

No `READ_CONTACTS`, `WRITE_CONTACTS`, or `QUERY_ALL_PACKAGES` permission is used.
The post-compile hook adds five targeted package-visibility queries.
Verify the packaged manifest after building.

The reader queries only the picker-returned URI, off the main thread with a
bounded deadline. Do not add collection queries, secondary lookups, persisted
URI grants, or direct contact writes.

Contact URIs must use `content://com.android.contacts/contacts/...` and pass the
plugin validator. Arbitrary providers, queries, fragments, credentials, ports,
and control characters are rejected.

Private no-backup storage retains selections for 10 minutes and metadata for
24 hours, with a maximum of 100 results. Expiry is enforced on access and
lifecycle reconciliation; cleanup cannot run while the process is absent.

Picker recovery requires a matching saved Android request binding.
Unrecoverable operations become `unknown` without automatic relaunch or replay.

## Events and verification

`Bbs\NativeContacts\Events\NativeContactsCompleted` contains metadata only.
Delivery is best effort and may be absent, delayed, or duplicated.
Use idempotent handlers, correlate owned IDs, and refresh status.
Do not automatically consume selections from completion events.

Use the package PHPUnit configuration and `NativeContactsHttpTest` for PHP
verification. Android instrumentation sources and the Gradle init script are
under `tests/android`. Rebuild native sources before instrumentation.

See the package README for examples, limitations, and recorded validation.
