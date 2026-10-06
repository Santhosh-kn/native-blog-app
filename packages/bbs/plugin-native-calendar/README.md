# Native Calendar for NativePHP Mobile

Android event editor and calendar viewer integration. Version 1.0.0 requires
PHP 8.4+, NativePHP Mobile 4.2+, and Android API 33+.

## Installation

From the application root, register the local Composer path repository:

```powershell
composer config repositories.native-calendar path packages/bbs/plugin-native-calendar
composer require bbs/plugin-native-calendar:1.0.0
```

Enable the provider in `NativeServiceProvider::plugins()`:

```php
\Bbs\NativeCalendar\NativeCalendarServiceProvider::class,
```

Verify registration and rebuild:

```powershell
php artisan native:plugin:list
php artisan native:plugin:validate packages/bbs/plugin-native-calendar
php artisan native:run android --build=debug --no-tty
```

## PHP API

```php
use Bbs\NativeCalendar\Facades\NativeCalendar;
```

| Method | Purpose |
| --- | --- |
| `isAvailable()` | Check editor, date viewer, and event viewer availability. |
| `createEvent(array $options = [])` | Launch an event editor with validated prefills. |
| `open(array $options = [])` | Open a date or an existing event ID. |
| `getStatus($id)` | Retrieve request outcome metadata. |

The supported bridge functions are `NativeCalendar.IsAvailable`,
`NativeCalendar.CreateEvent`, `NativeCalendar.Open`, and `NativeCalendar.GetStatus`.
There is no public JavaScript SDK, `execute()`, cancel, consume, calendar search,
or direct event-write API. iOS is not implemented.

### Timed event editor

```php
$start = new DateTimeImmutable('tomorrow 10:00:00', new DateTimeZone('Asia/Kolkata'));
$startMs = $start->getTimestamp() * 1000;

$request = NativeCalendar::createEvent([
    'title' => 'Native Calendar Demo',
    'startTimeMs' => $startMs,
    'endTimeMs' => $startMs + 3_600_000,
    'description' => 'Review this event before saving.',
    'location' => 'Demo location',
    'timeZone' => 'Asia/Kolkata',
    'recurrence' => 'FREQ=WEEKLY;COUNT=3',
]);

// Associate $request->id with the authorized initiating user.
// An accepted request may still be pending.
```

`title`, `startTimeMs`, and `endTimeMs` are required. `id`, `allDay`,
`description`, `location`, `timeZone`, and `recurrence` are optional.
Omitted IDs are generated; supplied IDs must be unique lowercase UUID v4.
Only one Calendar request can be pending at a time.

Timestamps must be integer epoch milliseconds between 0 and 253402300799999.
The end must be later than the start. Strings, floats, and explicit nulls are
not coerced into valid values. Unknown option keys are rejected.

Title, description, and location limits are 120, 2048, and 240 Unicode code
points. The complete encoded request is limited to 8192 bytes. Time zones must
be recognized identifiers. Time-zone and recurrence hints can be ignored by
the receiving calendar application.

Recurrence accepts the canonical subset `FREQ=DAILY|WEEKLY|MONTHLY|YEARLY`,
optionally followed by `;INTERVAL=1..365` and then `;COUNT=1..1000`.
For example, `FREQ=WEEKLY;COUNT=3` is valid; arbitrary recurrence rules are not.

### All-day event editor

```php
$day = new DateTimeImmutable('tomorrow 00:00:00', new DateTimeZone('UTC'));
$startMs = $day->getTimestamp() * 1000;

$request = NativeCalendar::createEvent([
    'title' => 'All-day demo',
    'startTimeMs' => $startMs,
    'endTimeMs' => $startMs + 86_400_000,
    'allDay' => true,
]);
```

The public all-day contract uses UTC midnight date boundaries and an exclusive
end. A one-day request for October 7 therefore ends at UTC midnight on October 8.
The PHP service defaults the all-day time zone to `UTC`; any supplied all-day
time zone must be `UTC`.

For the Android editor handoff only, the intent builder converts each UTC date
to midnight in the device time zone and omits the event time-zone hint. The
exclusive end is converted separately to preserve requested dates across
daylight-saving changes. The public request values remain unchanged.

Google Calendar can display both start and end as October 7 for this one-day
request. Review the editor before saving, especially with other calendar apps.

### Date and event viewers

```php
$dateRequest = NativeCalendar::open(['dateMs' => $startMs]);

// Use an event ID already known to the authorized caller.
$eventRequest = NativeCalendar::open(['eventId' => $knownEventId]);
```

Provide exactly one of `dateMs` and `eventId`. Event IDs must be positive PHP
integers no greater than 9007199254740991. An event request may also include
both `startTimeMs` and `endTimeMs` for a specific occurrence. Date requests
cannot include occurrence times.

Availability confirms that a handler exists; it does not establish that an
event exists. The plugin does not query the calendar provider to find IDs.

## Status and completion events

```php
// Verify ownership before using a client-supplied request ID.
$status = NativeCalendar::getStatus($ownedRequestId);
$metadata = $status->toMetadata();
```

Statuses are `pending`, `launched`, `failed`, `unknown`, and `not_found`.
`launched` means Android accepted the editor or viewer handoff. It does not
confirm that an event was saved, edited, or deleted. The receiving app controls
saving and discarding.

An interrupted handoff becomes `unknown` and is never automatically replayed.
Check Calendar before deciding whether to start another request.

`Bbs\NativeCalendar\Events\NativeCalendarCompleted` contains validated outcome
metadata only. Delivery is best effort and can be absent, delayed, or duplicated.
Correlate owned request IDs and keep handlers idempotent; use `getStatus()` for
recovery.

## Privacy and storage

The plugin does not request `READ_CALENDAR`, `WRITE_CALENDAR`, or
`QUERY_ALL_PACKAGES`. A post-compile hook adds three targeted intent queries.
Verify the packaged Android manifest after building.

Private state in Android's no-backup directory stores request metadata only.
It never stores event titles, descriptions, locations, requested dates, event
IDs, recurrence rules, or time zones. Events and status responses also exclude
these fields. Underlying native exception text is not exposed.

Metadata expires after 24 hours and the store is capped at 100 results.
Cleanup runs during transactions and lifecycle reconciliation; it cannot run
while the app process is absent. Launch decisions use bounded waits and verified
atomic writes. Process death does not replay editor or viewer launches.

## Application page

This repository includes `/native-calendar`, protected by authentication and
device-unlock middleware. It tracks up to 32 request IDs per session and returns
`no-store` responses. Availability runs automatically when the page opens.

The page offers timed, all-day, and weekly sample editors, a date viewer, a known
event-ID viewer, metadata refresh, and a safe unused-request diagnostic.

## Verification

From the application root:

```powershell
php vendor/bin/phpunit --configuration packages/bbs/plugin-native-calendar/phpunit.xml
php vendor/bin/phpunit --filter NativeCalendarHttpTest
```

Run Android tests directly from the package using the Gradle init script.
Use these package sources once; move any older copies of the same Calendar test
classes out of the generated project's `app/src/androidTest` directory first.

```powershell
$calendarInit = (Resolve-Path 'packages/bbs/plugin-native-calendar/tests/android/calendar-tests.init.gradle').Path
$calendarSources = (Resolve-Path 'packages/bbs/plugin-native-calendar/tests/android/instrumentation').Path
$previousSerial = $env:ANDROID_SERIAL
$env:ANDROID_SERIAL = 'emulator-5554'
Push-Location 'nativephp/android'
try {
    .\gradlew.bat :app:connectedDebugAndroidTest `
        --init-script $calendarInit `
        "-PcalendarTestSourceDir=$calendarSources" `
        '-Pandroid.testInstrumentationRunnerArguments.package=com.bbs.plugins.native_calendar' `
        --console=plain
    if ($LASTEXITCODE -ne 0) { throw 'Calendar Android tests failed' }
}
finally {
    Pop-Location
    $env:ANDROID_SERIAL = $previousSerial
}
```

Validation recorded on 2026-10-06:

- Package PHP tests: 295 tests, 972 assertions.
- Calendar HTTP tests: 44 tests, 588 assertions.
- Android instrumentation: 78 tests, no failures, errors, or skips.
- Android coverage includes 20 request, 10 intent, 13 launch-gate, 25 store, and 10 atomic-file tests.
- API 36 emulator checks verified automatic availability, timed and weekly editor prefills, one-day all-day dates, date viewing, and unused-request diagnostics.
- Existing event viewer URI construction is covered by intent tests; a manual viewer check requires a known test event ID.

Other Android versions, time zones on physical devices, and calendar application
implementations require further device coverage.

## License

MIT
