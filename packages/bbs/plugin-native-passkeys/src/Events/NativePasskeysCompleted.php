<?php

declare(strict_types=1);

namespace Bbs\NativePasskeys\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NativePasskeysCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $id,
        public string $operation,
        public string $status,
        public bool $success,
        public bool $cancelled,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
