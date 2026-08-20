<?php

namespace Bbs\FirebaseGoogleAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FirebaseGoogleAuthCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public bool $success,
        public ?string $idToken = null,
        public ?string $uid = null,
        public ?string $email = null,
        public ?string $name = null,
        public ?string $photoUrl = null,
        public ?string $error = null,
        public bool $cancelled = false,
        public ?string $id = null,
    ) {}
}