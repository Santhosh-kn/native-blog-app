<?php

namespace Bbs\FirebasePushNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FirebasePushNotificationsCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public bool $success,
        public ?string $error = null,
        public ?string $id = null,
    ) {}
}
