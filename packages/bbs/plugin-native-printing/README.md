# NativePrinting Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require bbs/plugin-native-printing
```

## Usage

```php
use Bbs\NativePrinting\Facades\NativePrinting;

// Execute functionality
$result = NativePrinting::execute(['option1' => 'value']);

// Get status
$status = NativePrinting::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Bbs\NativePrinting\Events\NativePrintingCompleted')]
public function handleNativePrintingCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT