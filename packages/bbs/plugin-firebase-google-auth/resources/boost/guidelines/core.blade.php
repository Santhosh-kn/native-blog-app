## bbs/plugin-firebase-google-auth

Custom NativePHP Mobile plugin for Google Sign-In with Firebase Authentication.

### Platform Status

- Android: Implemented and tested on a physical device.
- iOS: Bridge contract exists, but native authentication is not implemented yet.

### Installation

```bash
composer require bbs/plugin-firebase-google-auth
```

Register the plugin with NativePHP:

```bash
php artisan native:plugin:register bbs/plugin-firebase-google-auth
```

### PHP Usage

Use the `FirebaseGoogleAuth` facade to start native Google authentication.

@verbatim
<code-snippet name="Start Firebase Google Authentication" lang="php">
use Bbs\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;
use Illuminate\Support\Str;

$requestId = (string) Str::uuid();

FirebaseGoogleAuth::signIn([
    'id' => $requestId,
]);
</code-snippet>
@endverbatim

Google authentication is asynchronous. The `signIn()` method only starts the
native process. The final result is delivered through the
`FirebaseGoogleAuthCompleted` event.

The optional request ID correlates the native result with the PHP login request.

### Available Methods

- `FirebaseGoogleAuth::signIn(array $options = [])`: Start native Google authentication.
- `FirebaseGoogleAuth::signOut()`: Sign out from Firebase and clear native credential state.

### Completed Event

@verbatim
<code-snippet name="Listen for Firebase Google Authentication" lang="php">
use Bbs\FirebaseGoogleAuth\Events\FirebaseGoogleAuthCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(
    FirebaseGoogleAuthCompleted::class,
    function (FirebaseGoogleAuthCompleted $event) {
        if (! $event->success) {
            // Handle cancellation or authentication failure.
            return;
        }

        $firebaseUid = $event->uid;
        $firebaseIdToken = $event->idToken;
        $email = $event->email;
        $name = $event->name;
        $photoUrl = $event->photoUrl;

        // Create or log in the corresponding local Laravel user.
        // Never write the Firebase ID token to application logs.
    },
);
</code-snippet>
@endverbatim

The completed event provides:

- `success`
- `idToken`
- `uid`
- `email`
- `name`
- `photoUrl`
- `error`
- `cancelled`
- `id`

If the Firebase ID token is sent to an API, the API must verify it using the
Firebase Admin SDK before trusting the user information.

### Sign Out

@verbatim
<code-snippet name="Sign Out from Firebase Google Authentication" lang="php">
use Bbs\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;

FirebaseGoogleAuth::signOut();
</code-snippet>
@endverbatim

### JavaScript Usage

@verbatim
<code-snippet name="Use FirebaseGoogleAuth from JavaScript" lang="javascript">
import {
    firebaseGoogleAuth
} from '@bbs/plugin-firebase-google-auth';

const result = await firebaseGoogleAuth.signIn({
    id: 'login-request-id'
});

await firebaseGoogleAuth.signOut();
</code-snippet>
@endverbatim