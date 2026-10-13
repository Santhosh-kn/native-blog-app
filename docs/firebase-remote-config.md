# Firebase Remote Config demo

The Android NativePHP app reuses the default FirebaseApp for project
`php-native-app`, application ID `com.santhosh.nativeblog`, and Firebase BoM
`33.12.0`. Google authentication continues to copy `google-services.json` into
the generated Android app. There is no replacement Firebase project, asset
hook, Analytics dependency, automatic remote fetch, or automatic activation.

## Settings and sources

| Parameter | Requested type | Local default | Application behavior |
| --- | --- | --- | --- |
| welcome_message | string | Welcome to Native Blog! | Escaped home welcome text |
| show_media_optimizer | boolean | true | Shows/hides home Media Optimizer navigation |
| media_image_quality | integer | 80 | Default for future JPEG/WebP image jobs; range 1–100 |

The flag controls navigation only. All existing media routes retain their
authentication and device-unlock rules, including when the link is hidden.
Remote Config is client-visible configuration, not an authorization mechanism
or secret store.

Native values report `remote`, `default`, or `static` using the exact SDK value
that was converted. PHP reports its own fallback as `local`, never as remote
or Firebase default. Parameters are read independently so an invalid image
quality does not hide a valid welcome or boolean. A quality outside 1–100 or a
failed native conversion uses local 80. Native unavailability, initialization,
or a temporarily blocked read also leaves usable local defaults.

## Startup and operations

Home and the dedicated `/firebase-remote-config` page render immediately with
cached native values where ready, otherwise local defaults. Browser startup
polls cached SDK initialization for at most 25 seconds and submits the app's
typed SetDefaults once for a ready process. It never fetches or activates.
Registering defaults does not overwrite activated remote values. A restarted
process needs a fresh defaults request ID; replaying an old success cannot set
that new process's defaults-ready flag.

The demo offers explicit Fetch, Activate, Fetch and activate, Register local
defaults, and Apply fetch settings actions. Configure accepts actual JSON
integers: interval 0–604800 and timeout 1–120 seconds. Empty configure settings
use the package's production defaults, 43200 seconds and 60 seconds. The UI
offers normal 12-hour and explicit development 0-second intervals. Merely
changing the interval does not fetch or activate. Restore 12 hours after a
development test. Firebase can still throttle zero-interval requests.

Fetch success is separate from activation. Activation returning false is a
successful unchanged result. The page shows effective values, sources,
fallback reasons, local defaults, fetch status, fetch timestamp, operation
start/finish, known fetch/activation outcomes and throttle retry time.

The browser generates a secure lowercase UUID before submission. PHP validates
it and saves bounded session ownership, bound to the authenticated user,
before calling native code. Repeating the same ID/payload reconciles that
request. Changed payloads conflict. Status for an unowned ID returns 404 before
calling native code. Session history retains at most 32 operations, pruning
terminal entries and preserving unconfirmed/active ownership.

Responses use private no-store headers. Malformed acknowledgements and narrow
correlated native control failures remain unconfirmed with the same ID.
Automatic operation polling lasts at most 180 seconds, with a 10-second bound
per HTTP response, and pauses after three failed status responses. Refresh
values and status checks the same request. No new fetch/activation is started
automatically to replace an uncertain acknowledgement. Reloading the page
recovers owned nonterminal requests. A native timeout can leave an uncancellable
SDK task settling; new operation controls remain disabled while GetInfo reports
sdkBusy. A late SDK activation may have changed actual values, so read them
again after settlement rather than infer an outcome from the timed-out record.

## Media jobs

The media page displays the validated quality sampled on page load and defaults
to “Use the current app default for each new image job.” With that option on,
the browser omits quality and PHP samples the current validated default when
submitting that new JPEG/WebP request. Unchecking it permits an explicit manual
quality; invalid manual values are rejected instead of being silently replaced.
PNG receives no automatic quality option; an explicitly invalid PNG quality
remains rejected by the existing optimizer contract.

Only the new request gets the sampled integer. Status, cancellation, and output
operations do not reread Remote Config or rewrite an existing job. Submitted
quality is retained in bounded owned job metadata and shown beside each image
job; historical jobs with no such metadata remain unknown. Thumbnail
and video options keep their existing behavior. Navigation hiding does not
cancel or hide access to existing owned media outputs.

## Automated validation

Verified results for the registered plugin and installed demo:

- Remote Config package: 429 tests / 1091 assertions.
- Android instrumentation: 351 tests, no failures/errors/skips.
- NativePHP discovery: Android only, all nine bridge targets registered.
- Normal NativePHP Android debug build succeeded, including firebase-config
  alongside unchanged BoM 33.12.0, Firebase Auth and Messaging.
- Application suite including the demo: 297 tests / 1865 assertions.
- Executable browser client checks: 10 tests passed.
- Demo APK installed and launched successfully on emulator-5554 (Android 16).

The demo adds HTTP tests for protection, defaults, sources, invalid types/ranges,
operation recovery, request conflicts, ownership, retention, controlled
failures, escaping, home flags and per-job quality. These use deterministic
bridge doubles; they do not contact Firebase. Executable Node client tests
exercise the actual embedded browser script with a controlled DOM, requests
and clock. They cover startup, acknowledgement loss, bounded recovery,
fetch/activation separation and safe text updates. The installer runs the Node
checks, existing package tests, and full application suite before keeping its
changes. PHP and Android execution occur on the Windows laptop, not the
artifact preparation environment.

NativePHP 4.2.0's `native:plugin:validate` unconditionally demands BOTH Android
and iOS minimum versions, even when platforms is Android only. The observed
failure was solely “Missing ios.min_version”. Vendor validation code was
inspected; it does not condition that check on platforms. No false iOS support
or vendor patch is added. Plugin manifest tests, NativePHP list, generated
registration and normal Android compilation verify the Android integration.

For post-registration instrumentation, omit remoteConfigMainSourceDir from
the init-script properties: production sources now come from NativePHP's
plugin compiler. Continue supplying remoteConfigTestSourceDir for the tests.

## Manual acceptance record — 13 October 2026

The following results were observed on the existing Android emulator and
Firebase project. Times below are IST. No registration token or credential is
included in this record.

| Check | Observed result |
| --- | --- |
| Initial defaults | Local welcome, visible media navigation and image quality 80 usable before remote activation. |
| Fetch separate from Activate | Fetch at 11:22 succeeded while values stayed at defaults; Activate at 11:25 applied Hello from Firebase / true / 72 with remote sources. |
| Unchanged activation | A second Activate at 11:36 succeeded with changed=false. |
| Fetch and activate | Published false / 63 applied together; a repeat succeeded unchanged. |
| Navigation flag | Media Optimizer link disappeared from Home for false. Browser history still reached the protected media screen and existing jobs. Restoring true restored navigation. |
| Per-job JPEG quality | First job completed with submitted quality 72 (467254 output bytes). A later job completed at quality 63 (395019 bytes); the older completed job still displayed 72. |
| PNG options | PNG job completed with 3863245 output bytes and imageQuality=null; no submitted quality line was shown. |
| Invalid remote quality | Published 999 produced a labelled local fallback of 80 while the valid welcome and boolean retained remote sources. Valid 63 was restored afterwards. |
| Cold restart | Activated Hello from Firebase / true / 63 survived process restart. Fresh defaults registration succeeded; last successful fetch timestamp stayed unchanged. |
| Offline restart and Fetch | Airplane mode restart retained all three activated remote values. Explicit Fetch at 13:40 failed cleanly while cached values stayed usable. |
| Network recovery | After restoring connectivity, explicit Fetch at 13:46 succeeded. Normal 43200-second interval and 60-second timeout were reapplied at 13:48. |
| Google authentication | User confirmed Google sign-in after logout; unlock and Home were observed. No independent authentication trace was collected. |
| Push regression | Notification permission granted and device registered. A test addressed to the current emulator token arrived at 14:26; tapping it opened Home. A second test at 14:28 arrived with the app in the background. |

Final Firebase Client configuration is version 4: welcome_message is the
string `Hello from Firebase`, show_media_optimizer is boolean true, and
media_image_quality is the integer representation `63`. Temporary invalid
quality and hidden-navigation tests were restored. Development fetching was
turned off by restoring the normal 12-hour interval.

The first push attempts had no logged receipt. After the current emulator token
was copied again and selected in the console, delivery succeeded. This is an
observed recovery, not a proven diagnosis of the earlier missing delivery.

Real server throttling, forced timeout/late SDK completion, process death
during an active Remote Config operation, desktop-browser behavior, and direct
unauthenticated navigation were not manually exercised. Deterministic native
and HTTP tests cover the applicable fault and protection behavior. The JPEG
history check compared completed jobs; it does not establish a manual race
with a media job still running. This Android validation establishes no iOS
support. No merge or branch deletion has been performed.

## Manual acceptance procedure

1. Build the demo, install the new APK and launch it on the existing emulator.
   Sign in and unlock. Open Remote Config. Confirm defaults/cache become ready
   with no remote fetch. A desktop browser should remain usable with local
   defaults and native controls unavailable.
2. In the existing Firebase project, add the three parameters with values such
   as `Hello from Firebase`, `true`, and `72`, then publish. Use the matching
   string/boolean/number parameter types where available. The native integer
   reader requires an integer representation, not a decimal such as `72.0`.
3. Apply the explicit development interval, then Fetch. Check success and the
   timestamp while current values remain unchanged. Activate separately and
   confirm the welcome, sources and quality. A second Activate should report
   successful unchanged.
4. Publish `show_media_optimizer=false`; fetch and activate. Check the hidden
   home link and verify the media page still requires sign-in and unlock when
   opened directly. Publish true to restore navigation.
5. Start an image job using the app default. Change/publish/activate quality
   for a later job. Verify the existing job continues with its original
   options. Verify a new job uses the new quality and PNG receives no quality.
6. Publish out-of-range quality and confirm the app labels local fallback 80
   without losing other valid values. Restore a valid parameter afterwards.
7. Restart the app process. Confirm fresh default registration and retained
   activated values/sources without fetching. Test offline startup with cache
   and an explicit offline Fetch failure; restore network afterwards.
8. Exercise explicit Fetch and activate. Test timeout/throttle states where
   practical, without repeatedly fetching merely to provoke server throttling.
   Deterministic automated failure tests do not establish real network results.
9. Verify Google login/logout, push permission/token synchronization and a
   delivered notification tap still work with this build. Existing HTTP
   regression tests do not replace these real-device checks.

Use the dated record above for checks actually performed; this procedure is
reproducible guidance, not evidence that every optional case was exercised.
No merge or branch deletion is part of installation or validation.
