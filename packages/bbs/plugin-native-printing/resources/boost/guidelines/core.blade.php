## bbs/plugin-native-printing

A NativePHP Mobile plugin

### Installation

```bash
composer require bbs/plugin-native-printing
```

### PHP Usage (Livewire/Blade)

Use the `NativePrinting` facade:

@verbatim
<code-snippet name="Using NativePrinting Facade" lang="php">
use Bbs\NativePrinting\Facades\NativePrinting;

// Execute the plugin functionality
$result = NativePrinting::execute(['option1' => 'value']);

// Get the current status
$status = NativePrinting::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `NativePrinting::execute()`: Execute the plugin functionality
- `NativePrinting::getStatus()`: Get the current status

### Events

- `NativePrintingCompleted`: Listen with `#[OnNative(NativePrintingCompleted::class)]`

@verbatim
<code-snippet name="Listening for NativePrinting Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Bbs\NativePrinting\Events\NativePrintingCompleted;

#[OnNative(NativePrintingCompleted::class)]
public function handleNativePrintingCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using NativePrinting in JavaScript" lang="javascript">
import { nativePrinting } from '@bbs/plugin-native-printing';

// Execute the plugin functionality
const result = await nativePrinting.execute({ option1: 'value' });

// Get the current status
const status = await nativePrinting.getStatus();
</code-snippet>
@endverbatim