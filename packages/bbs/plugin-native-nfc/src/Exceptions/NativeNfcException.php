<?php

declare(strict_types=1);

namespace Bbs\NativeNfc\Exceptions;

use RuntimeException;

final class NativeNfcException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
