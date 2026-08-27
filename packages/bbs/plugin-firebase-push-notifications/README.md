# Firebase Push Notifications for NativePHP Mobile

A custom BBS Android plugin for Firebase Cloud Messaging permission handling, secure device-token synchronization, notification display, and allow-listed notification-tap routing.

## Scope

The plugin provides five NativePHP bridge functions:

- FirebasePushNotifications.CheckPermission
- FirebasePushNotifications.RequestPermission
- FirebasePushNotifications.GetToken
- FirebasePushNotifications.GetStoredToken
- FirebasePushNotifications.GetPendingNotification

GetToken is asynchronous and later dispatches FirebasePushNotificationsCompleted to Laravel.

GetStoredToken and GetPendingNotification are trusted-PHP operations. Native bridge responses contain only app-private file references. Raw FCM tokens and notification destinations are never returned through those bridge responses or exposed to JavaScript.

The Android implementation provides:

- Notification-permission checking and runtime permission requests.
- Asynchronous FCM token registration.
- Automatic refreshed-token persistence.
- Foreground and background display for data-only messages.
- A high-importance notification channel.
- Versioned and allow-listed notification destinations.
- Private one-time notification-tap files.
- Warm, background and cold-start tap handling through NativePHP.

The plugin does not send FCM messages, contain Firebase service-account credentials, accept arbitrary navigation URLs, or provide an iOS implementation.

## Requirements

- NativePHP Mobile ^3.0 or ^4.0
- Android API 21 or newer
- A Firebase Android app matching the NativePHP application ID
- A valid google-services.json

The application must copy google-services.json from its permanent trusted plugin resource during native generation. Firebase service-account JSON is server-side private-key material and must never be packaged with the mobile app.

## Data-only message requirement

Reliable foreground, background and cold-start routing requires a high-priority FCM data-only message handled by FirebasePushMessagingService.

A message containing a top-level Firebase notification block may be displayed automatically while the app is backgrounded. That path can bypass the custom service and cannot guarantee this routing contract.

The trusted sender may include title and body plus this versioned navigation data:

    navigation_version: 1
    navigation_destination: post_edit
    navigation_resource_id: 42

The mobile application must never contain trusted-sender credentials or send server-side Firebase requests.

## Version 1 navigation contract

Accepted keys:

- navigation_version
- navigation_destination
- navigation_resource_id

navigation_version must be 1.

Allowed destinations:

| Destination | Laravel result |
|---|---|
| home | Home |
| posts | Posts list |
| post_create | Create Post |
| post_edit | Edit an authorized Post |
| push_settings | Push Notifications page |

navigation_resource_id is required only for post_edit and must be a positive integer.

Invalid or unsupported destinations fall back to Home.

The native plugin and Laravel both validate the contract. Laravel additionally requires authentication, biometric unlock, and Post authorization before opening protected destinations.

## Secure notification-tap lifecycle

When a data-only message arrives:

1. The native plugin reads only the navigation contract fields.
2. Missing or invalid contracts are normalized to Home.
3. The sanitized destination is written to an app-private atomic file.
4. A random one-time UUID identifies that file.
5. The Android notification receives only this fixed internal URI:

    nativeblog://push/open?tap=<uuid>

The notification payload is not copied into the Android launch intent.

NativePHP processes the fixed URI through onNewIntent for a running or backgrounded app and through onCreate plus its pending-deep-link mechanism for a terminated app.

Laravel receives only the UUID. Trusted PHP calls GetPendingNotification, receives the private file path, reads the sanitized JSON, and deletes the file. The payload itself never crosses the NativePHP bridge response.

The Native Blog application stores the normalized destination in its session. It resumes only after authentication and biometric unlock. Missing, unavailable, already-consumed, unauthorized, or unsupported destinations fall back to Home.

## Token registration and refresh

CheckPermission reports the current Android permission state. RequestPermission starts the Android 13 or newer permission dialog when required.

GetToken returns immediately. Its final token-free result is dispatched through:

    Bbs\FirebasePushNotifications\Events\FirebasePushNotificationsCompleted

The completion event contains only success, error, and request ID.

GetStoredToken asks native code only for the private token-file path. Trusted PHP reads the raw token directly and must never render, return, or log it.

FirebasePushMessagingService.onNewToken stores refreshed tokens in the same private token file. Authenticated Laravel code may compare the private token with an enrolled user and update it only when changed.

## Trusted PHP API

The Facade provides:

- checkPermission()
- requestPermission()
- getToken(request ID)
- getStoredToken()
- getPendingNotification(tap ID)

getPendingNotification consumes its private file once and returns only the sanitized payload to trusted Laravel code.

Stored-token and pending-notification file methods are intentionally absent from the JavaScript API.

## Validation and tests

Validate the manifest:

    php artisan native:plugin:validate packages/bbs/plugin-firebase-push-notifications

Run all plugin tests:

    vendor/bin/phpunit packages/bbs/plugin-firebase-push-notifications/tests

Run Laravel push tests:

    php artisan test tests/Feature/FirebasePushNotificationsTest.php
    php artisan test tests/Feature/PushNotificationDeepLinkTest.php

## Security

- Never package Firebase service-account credentials.
- Never send trusted Firebase requests from the mobile application.
- Never render or log raw FCM tokens.
- Never copy notification payload data into a launch intent.
- Never accept arbitrary URLs or unrestricted route names.
- Return only private file references through sensitive bridges.
- Consume notification-tap files once.
- Require Laravel authentication and biometric unlock.
- Apply authorization to resource-specific destinations.
- Fall back to Home for invalid, missing, unsupported, or unauthorized destinations.

## License

MIT
