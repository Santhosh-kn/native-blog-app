# NativeBackgroundTransfer Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require bbs/plugin-native-background-transfer
```

## Usage

```php
use Bbs\NativeBackgroundTransfer\Facades\NativeBackgroundTransfer;

// Execute functionality
$result = NativeBackgroundTransfer::execute(['option1' => 'value']);

// Get status
$status = NativeBackgroundTransfer::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Bbs\NativeBackgroundTransfer\Events\NativeBackgroundTransferCompleted')]
public function handleNativeBackgroundTransferCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT