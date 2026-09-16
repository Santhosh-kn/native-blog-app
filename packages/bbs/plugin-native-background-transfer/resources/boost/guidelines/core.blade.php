## bbs/plugin-native-background-transfer

A NativePHP Mobile plugin

### Installation

```bash
composer require bbs/plugin-native-background-transfer
```

### PHP Usage (Livewire/Blade)

Use the `NativeBackgroundTransfer` facade:

@verbatim
<code-snippet name="Using NativeBackgroundTransfer Facade" lang="php">
use Bbs\NativeBackgroundTransfer\Facades\NativeBackgroundTransfer;

// Execute the plugin functionality
$result = NativeBackgroundTransfer::execute(['option1' => 'value']);

// Get the current status
$status = NativeBackgroundTransfer::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `NativeBackgroundTransfer::execute()`: Execute the plugin functionality
- `NativeBackgroundTransfer::getStatus()`: Get the current status

### Events

- `NativeBackgroundTransferCompleted`: Listen with `#[OnNative(NativeBackgroundTransferCompleted::class)]`

@verbatim
<code-snippet name="Listening for NativeBackgroundTransfer Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Bbs\NativeBackgroundTransfer\Events\NativeBackgroundTransferCompleted;

#[OnNative(NativeBackgroundTransferCompleted::class)]
public function handleNativeBackgroundTransferCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using NativeBackgroundTransfer in JavaScript" lang="javascript">
import { nativeBackgroundTransfer } from '@bbs/plugin-native-background-transfer';

// Execute the plugin functionality
const result = await nativeBackgroundTransfer.execute({ option1: 'value' });

// Get the current status
const status = await nativeBackgroundTransfer.getStatus();
</code-snippet>
@endverbatim