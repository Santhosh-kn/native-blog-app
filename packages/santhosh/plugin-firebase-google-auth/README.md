# FirebaseGoogleAuth Plugin for NativePHP Mobile

NativePHP Mobile plugin for Google Sign-In with Firebase Authentication

## Installation

```bash
composer require santhosh/plugin-firebase-google-auth
```

## Usage

```php
use Santhosh\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;

// Execute functionality
$result = FirebaseGoogleAuth::execute(['option1' => 'value']);

// Get status
$status = FirebaseGoogleAuth::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Santhosh\FirebaseGoogleAuth\Events\FirebaseGoogleAuthCompleted')]
public function handleFirebaseGoogleAuthCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT