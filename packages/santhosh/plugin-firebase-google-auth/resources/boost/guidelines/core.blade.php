## santhosh/plugin-firebase-google-auth

NativePHP Mobile plugin for Google Sign-In with Firebase Authentication

### Installation

```bash
composer require santhosh/plugin-firebase-google-auth
```

### PHP Usage (Livewire/Blade)

Use the `FirebaseGoogleAuth` facade:

@verbatim
<code-snippet name="Using FirebaseGoogleAuth Facade" lang="php">
use Santhosh\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;

// Execute the plugin functionality
$result = FirebaseGoogleAuth::execute(['option1' => 'value']);

// Get the current status
$status = FirebaseGoogleAuth::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `FirebaseGoogleAuth::execute()`: Execute the plugin functionality
- `FirebaseGoogleAuth::getStatus()`: Get the current status

### Events

- `FirebaseGoogleAuthCompleted`: Listen with `#[OnNative(FirebaseGoogleAuthCompleted::class)]`

@verbatim
<code-snippet name="Listening for FirebaseGoogleAuth Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Santhosh\FirebaseGoogleAuth\Events\FirebaseGoogleAuthCompleted;

#[OnNative(FirebaseGoogleAuthCompleted::class)]
public function handleFirebaseGoogleAuthCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using FirebaseGoogleAuth in JavaScript" lang="javascript">
import { firebaseGoogleAuth } from '@santhosh/plugin-firebase-google-auth';

// Execute the plugin functionality
const result = await firebaseGoogleAuth.execute({ option1: 'value' });

// Get the current status
const status = await firebaseGoogleAuth.getStatus();
</code-snippet>
@endverbatim