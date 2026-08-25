## bbs/plugin-firebase-push-notifications

An Android-only NativePHP Mobile plugin for Firebase Cloud Messaging permission, device-token registration, and incoming notification presentation.

### Responsibilities

- Check Android notification permission.
- Request `POST_NOTIFICATIONS` on Android 13 and newer.
- Retrieve the Firebase Cloud Messaging registration token.
- Dispatch asynchronous token results back to Laravel.
- Register a custom `FirebaseMessagingService`.
- Display notifications received while the application is in the foreground.
- Disable Google Play Services notification delegation during application initialization.
- Store refreshed FCM tokens in an app-private atomic file.
- Never contain Firebase service-account credentials or send server-side FCM requests.
- Expose only a private token-file reference through the internal `GetStoredToken` bridge.
- Allow trusted Laravel code to synchronize rotated tokens for previously enrolled users.

### PHP Usage

Use the `FirebasePushNotifications` facade:

@verbatim
<code-snippet name="Using FirebasePushNotifications Facade" lang="php">
use Bbs\FirebasePushNotifications\Facades\FirebasePushNotifications;
use Illuminate\Support\Str;

$permission = FirebasePushNotifications::checkPermission();

FirebasePushNotifications::requestPermission();

$requestId = (string) Str::uuid();

$started = FirebasePushNotifications::getToken($requestId);

</code-snippet>
@endverbatim

### Available Methods

- `checkPermission()`: Return the current Android notification-permission status.
- `requestPermission()`: Start the Android runtime-permission request when required.
- `getToken(?string $id = null)`: Start asynchronous FCM token retrieval.
- `getStoredToken()`: Read the latest token from the app-private file through trusted PHP code.

### Asynchronous Completion Event

`getToken()` returns immediately. Its final result is dispatched through `FirebasePushNotificationsCompleted`.

Event properties:

- `success`: Whether Firebase retrieved and privately stored a token.
- `error`: A safe failure message, or `null`.
- `id`: The request UUID supplied to `getToken()`.
The raw FCM token must not be included in the native completion event. Trusted PHP code retrieves it from the app-private file through `getStoredToken()`.

@verbatim
<code-snippet name="Listening for Firebase Token Completion" lang="php">
use Bbs\FirebasePushNotifications\Events\FirebasePushNotificationsCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(
    FirebasePushNotificationsCompleted::class,
    function (FirebasePushNotificationsCompleted $event) {
        // Match $event->id to the pending application request.
    },
);
</code-snippet>
@endverbatim

### Incoming Notifications

The Android plugin registers `FirebasePushMessagingService` for the `com.google.firebase.MESSAGING_EVENT` intent.

When a message reaches `onMessageReceived()`, the service:

- Reads the title and body from the Firebase notification payload.
- Falls back to the `title`, `body`, or `message` data fields.
- Creates the high-importance Android notification channel when needed.
- Displays the notification through `NotificationManagerCompat`.
- Opens the application launcher when the notification is tapped.

Firebase notification messages received while the application is in the background can be displayed in Android’s system notification tray.

Notification taps currently open the application Home page. Custom deep-link routing is not implemented.

### Application Initialization

The plugin manifest registers:

- `FirebasePushMessagingService`
- `initializeFirebasePushNotifications` as the Android `init_function`
- `firebase_messaging_notification_delegation_enabled` with a value of `false`

The initializer also calls:

`FirebaseMessaging.setNotificationDelegationEnabled(false)`

This runtime call ensures Google Play Services notification delegation is disabled, including when delegation was persisted by a previous installation.

Native Android changes must be made inside the plugin’s `resources/android` directory. Do not modify generated files under `nativephp/android`.

### Token Refresh

Firebase may rotate an FCM registration token.

`FirebasePushMessagingService.onNewToken()` saves the rotated token through `FirebasePushTokenStore`, which uses an app-private atomic file.

The internal `GetStoredToken` bridge returns only the private file reference. The PHP facade reads the file inside the application sandbox and returns the token to trusted Laravel code. The raw token must never be returned through JNI, exposed to JavaScript, rendered, or logged.

The Native Blog application calls its authenticated `push.sync` endpoint when a previously enrolled user opens or resumes the app. The controller updates the user only when the stored token differs from the current `push_token`.

Users without an existing `push_token` must not be enrolled automatically.

### JavaScript Usage

@verbatim
<code-snippet name="Using FirebasePushNotifications in JavaScript" lang="javascript">
import {
    firebasePushNotifications
} from '@bbs/plugin-firebase-push-notifications';

const permission =
    await firebasePushNotifications.checkPermission();

await firebasePushNotifications.requestPermission();

const started =
    await firebasePushNotifications.getToken(requestId);

</code-snippet>
@endverbatim

### Firebase Configuration

The application must provide a matching `google-services.json`.

This plugin intentionally does not own or copy that file when another Firebase plugin already manages the shared client configuration.

Firebase service-account JSON belongs only on a trusted backend and must never be included in a NativePHP mobile build.