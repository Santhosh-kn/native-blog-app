# Firebase Push Notifications for NativePHP Mobile

A custom Android NativePHP Mobile plugin for Firebase Cloud Messaging notification permission and device-token registration.

## Current Scope

The plugin provides four NativePHP bridge functions:

- `FirebasePushNotifications.CheckPermission`
- `FirebasePushNotifications.RequestPermission`
- `FirebasePushNotifications.GetToken`
- `FirebasePushNotifications.GetStoredToken`

`GetToken` is asynchronous. It returns immediately and later dispatches `FirebasePushNotificationsCompleted` back to Laravel.

The Android implementation also:

- Registers a custom `FirebaseMessagingService`.
- Disables Google Play Services notification delegation so the plugin owns message handling.
- Displays notification messages while the app is in the foreground.
- Supports Firebase’s system-tray presentation while the app is in the background.
- Creates a high-importance Android notification channel when required.
- Opens the application when a notification is tapped.
- Stores refreshed FCM tokens in an app-private atomic file.

The plugin does not:

- Send FCM messages from the mobile device.
- Include Firebase service-account credentials.
- Route notification taps to a specific application page yet.
- Provide an iOS implementation.

## Requirements

- NativePHP Mobile 3.x
- Android API 21 or newer
- A Firebase Android application matching the NativePHP application ID
- A valid `google-services.json`

Android 13 and newer require the runtime `POST_NOTIFICATIONS` permission.

## Installation

```bash
composer require bbs/plugin-firebase-push-notifications
php artisan native:plugin:register bbs/plugin-firebase-push-notifications
```

Verify registration:

```bash
php artisan native:plugin:list --all
```

## Firebase Client Configuration

The application must copy `google-services.json` into the generated Android app during the NativePHP build lifecycle.

This plugin intentionally does not own or copy that file because the application may already have another Firebase plugin managing the shared configuration.

Firebase service-account JSON is server-side private-key material. It must never be included in a mobile build.

## PHP Usage

```php
use Bbs\FirebasePushNotifications\Facades\FirebasePushNotifications;
use Illuminate\Support\Str;

$permission =
    FirebasePushNotifications::checkPermission();

FirebasePushNotifications::requestPermission();

$requestId = (string) Str::uuid();

$started =
    FirebasePushNotifications::getToken($requestId);
```

### Check Permission

```php
$result =
    FirebasePushNotifications::checkPermission();
```

The result includes:

- `status`
- `granted`
- `sdkInt`
- `requiresRuntimePermission`

Possible status values include `granted`, `not_determined`, and `denied`.

### Request Permission

```php
$result =
    FirebasePushNotifications::requestPermission();
```

On Android 13 and newer, this starts the native runtime permission dialog when permission has not already been granted.

### Retrieve the FCM Token

```php
$result =
    FirebasePushNotifications::getToken($requestId);
```

This returns immediately with a `started` response. Firebase retrieves the token asynchronously.

### Retrieve the Stored FCM Token

```php
$result =
    FirebasePushNotifications::getStoredToken();
```
The PHP result includes `available` and, when present, `token`. Internally, the native bridge returns only a reference to an app-private token file, and the trusted PHP wrapper reads that file directly. The raw token is not returned through JNI or exposed to JavaScript. Do not render or log the returned PHP token.

## Completion Event

The final token result is dispatched through:

```php
Bbs\FirebasePushNotifications\Events\FirebasePushNotificationsCompleted
```

Event properties:

- `success`
- `error`
- `id`
A successful event confirms that Firebase retrieved the token and the plugin stored it privately. The raw token is not included in the native event. Trusted Laravel code can retrieve it through `FirebasePushNotifications::getStoredToken()`.

Example listener:

```php
use Bbs\FirebasePushNotifications\Events\FirebasePushNotificationsCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(
    FirebasePushNotificationsCompleted::class,
    function (FirebasePushNotificationsCompleted $event) {
        if (! $event->id) {
            return;
        }

        // Match the event ID with the pending application request.
    },
);
```

A request UUID should be stored in the Laravel session and checked before consuming the asynchronous result.

## Receiving Notifications

The plugin registers `FirebasePushMessagingService` for `com.google.firebase.MESSAGING_EVENT`.

### Foreground

When the application is visible, `onMessageReceived()` creates and displays a native Android notification. It reads the title and body from either the Firebase notification payload or these data keys:

- `title`
- `body`
- `message`

### Background

Firebase notification messages received while the application is in the background are displayed in Android’s system notification tray.

### Notification Tap

Tapping a notification opens the application launcher, which currently loads the Home page. Custom deep-link routing is not implemented yet.

### Token Refresh

Firebase may rotate an FCM token.

`FirebasePushMessagingService.onNewToken()` saves the refreshed token in an app-private atomic file. The internal `GetStoredToken` bridge returns only the private file reference, and `FirebasePushNotifications::getStoredToken()` reads it from trusted PHP code inside the application sandbox.

In the Native Blog application, an authenticated background request runs when an enrolled user opens or resumes the app. Laravel compares the private stored token with the user’s current `push_token` and updates the database only when the token changed.

Users who never enabled push notifications are not enrolled automatically, and the synchronization response never exposes the token to JavaScript.

## JavaScript API

```javascript
import {
    firebasePushNotifications
} from '@bbs/plugin-firebase-push-notifications';

const permission =
    await firebasePushNotifications.checkPermission();

await firebasePushNotifications.requestPermission();

const started =
    await firebasePushNotifications.getToken(requestId);
```

## Architecture

### Token Registration

```text
Laravel controller
    -> NativePHP GetToken bridge
    -> FirebaseMessaging.getToken()
    -> App-private atomic token file
    -> Token-free NativePHP completion event
    -> Laravel completion cache
    -> Browser polling
    -> Trusted PHP reads private token file
    -> Authenticated user push_token
```

### Incoming Notification

```text
Firebase Cloud Messaging
    -> FirebasePushMessagingService
    -> Android notification channel
    -> System notification tray
    -> User taps notification
    -> Native Blog launcher
    -> Home page
```

### Application Initialization

```text
NativePHP plugin init_function
    -> FirebaseMessaging
    -> Disable Google Play Services notification delegation
    -> Custom plugin service owns message handling
```

### Refreshed Token Synchronization

```text
Firebase rotates token
    -> FirebasePushMessagingService.onNewToken()
    -> App-private atomic token file
    -> Authenticated app foreground request
    -> Internal GetStoredToken file reference
    -> Trusted PHP reads private token file
    -> Laravel compares current user token
    -> Update push_token only when changed
```
## Validation and Tests

Validate the NativePHP manifest:

```bash
php artisan native:plugin:validate packages/bbs/plugin-firebase-push-notifications
```

Run the plugin tests:

```bash
vendor/bin/phpunit packages/bbs/plugin-firebase-push-notifications/tests/PluginTest.php
```

## Security

- Never place Firebase service-account credentials in this plugin or the application `.env` packaged for Android.
- Do not log FCM registration tokens.
- Associate each asynchronous request UUID with the current authenticated Laravel session.
- Consume cached token results only once.

## License

MIT