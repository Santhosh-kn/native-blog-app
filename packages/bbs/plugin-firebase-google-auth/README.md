# Firebase Google Auth for NativePHP Mobile

A custom NativePHP Mobile plugin that authenticates Android users with Google,
exchanges the Google credential with Firebase Authentication, and returns the
Firebase result to PHP through a NativePHP event.

## Platform status

- Android: implemented and tested on a physical device.
- iOS: bridge contract exists, but native Firebase Google Sign-In is not implemented yet.

## Requirements

- NativePHP Mobile 3.x
- A Firebase project
- An Android application registered in Firebase
- Google authentication enabled in Firebase Authentication
- The Android signing certificate SHA-1 registered in Firebase
- An updated `google-services.json`

## Installation

```bash
composer require bbs/plugin-firebase-google-auth
```

Register the plugin with NativePHP:

```bash
php artisan native:plugin:register bbs/plugin-firebase-google-auth
```

## Start Google Sign-In

```php
use Bbs\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;
use Illuminate\Support\Str;

$requestId = (string) Str::uuid();

FirebaseGoogleAuth::signIn([
    'id' => $requestId,
]);
```

Google Sign-In is asynchronous. The facade starts the native Android flow and
returns immediately. The final result is delivered through the
`FirebaseGoogleAuthCompleted` event.

The optional `id` value is returned with the event so the PHP application can
connect the result to the correct login request.

## Listen for the completed event

```php
use Bbs\FirebaseGoogleAuth\Events\FirebaseGoogleAuthCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(
    FirebaseGoogleAuthCompleted::class,
    function (FirebaseGoogleAuthCompleted $event) {
        if (! $event->success) {
            // Handle cancellation or failure.
            return;
        }

        $firebaseUid = $event->uid;
        $email = $event->email;
        $name = $event->name;
        $photoUrl = $event->photoUrl;
        $firebaseIdToken = $event->idToken;

        // Create or log in the corresponding local user.
        // Do not write the Firebase ID token to application logs.
    },
);
```

The event provides:

- `success`
- `idToken`
- `uid`
- `email`
- `name`
- `photoUrl`
- `error`
- `cancelled`
- `id`

If the ID token is sent to a Laravel API, the API must verify it with the
Firebase Admin SDK before trusting the user information.

## Sign out

```php
use Bbs\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;

FirebaseGoogleAuth::signOut();
```

On Android, sign-out clears both Firebase Authentication and Credential Manager
state.

## Android bridge

The Android implementation uses:

- Android Credential Manager
- `GetSignInWithGoogleOption`
- Google ID-token credentials
- Firebase Authentication
- NativePHP asynchronous events

## License

MIT