# Native Calendar

Package: `bbs/plugin-native-calendar`.
PHP namespace: `Bbs\NativeCalendar`.
Bridge namespace: `NativeCalendar`.

Version 1 supports Android API 33+, PHP 8.4+, and NativePHP Mobile 4.2+.
iOS and a public JavaScript SDK are not implemented.

## Public PHP API

Use `Bbs\NativeCalendar\Facades\NativeCalendar`:

- `isAvailable()` checks `createEvent`, `openDate`, and `openEvent` capabilities.
- `createEvent(array $options = [])` launches an editor with validated prefills.
- `open(array $options = [])` opens exactly one date or existing event target.
- `getStatus($id)` retrieves metadata for an owned request ID.

There is no `execute()`, cancel, consume, search, or direct event-write method.

@verbatim
<code-snippet name="Open a native Calendar event editor" lang="php">
use Bbs\NativeCalendar\Facades\NativeCalendar;

$start = new DateTimeImmutable('tomorrow 10:00:00', new DateTimeZone('Asia/Kolkata'));
$startMs = $start->getTimestamp() * 1000;
$request = NativeCalendar::createEvent([
    'title' => 'Calendar demo',
    'startTimeMs' => $startMs,
    'endTimeMs' => $startMs + 3_600_000,
    'timeZone' => 'Asia/Kolkata',
]);

// Associate $request->id with the authorized initiating user.
// Check owned status later; acceptance does not confirm a saved event.
</code-snippet>
@endverbatim

## Contract

- Request IDs are generated when omitted. Supplied IDs must be unique lowercase UUID v4.
- Only one Calendar request can be pending at a time.
- Required editor fields are `title`, `startTimeMs`, and `endTimeMs`.
- Optional editor fields are `id`, `allDay`, `description`, `location`, `timeZone`, and `recurrence`.
- Timestamps must be integer epoch milliseconds; the end must be later than the start.
- All-day requests use UTC midnight boundaries, an exclusive end, and the `UTC` time zone.
- The Android editor handoff converts each all-day date to device-local midnight and omits the time-zone hint. Public request values stay unchanged.
- Timed time-zone and recurrence hints depend on the receiving calendar app.
- Recurrence accepts DAILY, WEEKLY, MONTHLY, or YEARLY frequency with optional ordered INTERVAL and COUNT fields; see the package README for limits.
- Viewer requests contain exactly one of `dateMs` or `eventId`.
- Known event IDs must be positive integers no greater than 9007199254740991. Only event targets can include both occurrence start/end times.
- Unknown keys, explicit nulls for optional fields, and oversized requests are rejected.

## Outcomes, privacy, and lifecycle

`launched` confirms an Android UI handoff, not a saved event. Calendar controls
saving and discarding. `unknown` is an interrupted outcome; never replay it
automatically. Authorize ownership before any status lookup.

`Bbs\NativeCalendar\Events\NativeCalendarCompleted` and `toMetadata()` contain
outcome metadata only. Completion events are best effort; correlate IDs and
handle duplicates. Event inputs are excluded from stored state, events, status,
and diagnostics. The store uses the no-backup directory, retains metadata for
24 hours, and caps it at 100 results.

The plugin adds three targeted intent visibility queries and requests no broad
calendar or package-query permissions. Verify the built manifest after changes.
Use the package `phpunit.xml` and Android Gradle init script for testing.
