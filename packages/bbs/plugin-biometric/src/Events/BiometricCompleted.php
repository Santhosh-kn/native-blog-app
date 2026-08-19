<?php

namespace Bbs\Biometric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BiometricCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public bool $success,
        public ?string $id = null
    ) {}
}