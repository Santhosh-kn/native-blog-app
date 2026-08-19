# BiometricAndroid Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require bbs/plugin-biometric-android
```

## Usage

```php
use Bbs\BiometricAndroid\Facades\BiometricAndroid;

// Execute functionality
$result = BiometricAndroid::execute(['option1' => 'value']);

// Get status
$status = BiometricAndroid::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Bbs\BiometricAndroid\Events\BiometricAndroidCompleted')]
public function handleBiometricAndroidCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT