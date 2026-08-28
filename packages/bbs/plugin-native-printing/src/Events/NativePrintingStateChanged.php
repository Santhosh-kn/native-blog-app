<?php

declare(strict_types=1);

namespace Bbs\NativePrinting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NativePrintingStateChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $request_id,
        public string $action,
        public string $status,
        public ?string $job_id = null,
        public ?string $error_code = null,
        public ?string $error_message = null,
    ) {}
}
