## bbs/plugin-biometric-android

A NativePHP Mobile plugin

### Installation

```bash
composer require bbs/plugin-biometric-android
```

### PHP Usage (Livewire/Blade)

Use the `BiometricAndroid` facade:

@verbatim
<code-snippet name="Using BiometricAndroid Facade" lang="php">
use Bbs\BiometricAndroid\Facades\BiometricAndroid;

// Execute the plugin functionality
$result = BiometricAndroid::execute(['option1' => 'value']);

// Get the current status
$status = BiometricAndroid::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `BiometricAndroid::execute()`: Execute the plugin functionality
- `BiometricAndroid::getStatus()`: Get the current status

### Events

- `BiometricAndroidCompleted`: Listen with `#[OnNative(BiometricAndroidCompleted::class)]`

@verbatim
<code-snippet name="Listening for BiometricAndroid Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Bbs\BiometricAndroid\Events\BiometricAndroidCompleted;

#[OnNative(BiometricAndroidCompleted::class)]
public function handleBiometricAndroidCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using BiometricAndroid in JavaScript" lang="javascript">
import { biometricAndroid } from '@bbs/plugin-biometric-android';

// Execute the plugin functionality
const result = await biometricAndroid.execute({ option1: 'value' });

// Get the current status
const status = await biometricAndroid.getStatus();
</code-snippet>
@endverbatim