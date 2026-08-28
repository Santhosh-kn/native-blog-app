<?php

declare(strict_types=1);

namespace Bbs\NativePrinting\Support;

final readonly class PdfValidationResult
{
    private function __construct(
        public bool $valid,
        public ?string $canonicalPath,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {
    }

    public static function valid(string $canonicalPath): self
    {
        return new self(
            valid: true,
            canonicalPath: $canonicalPath,
            errorCode: null,
            errorMessage: null,
        );
    }

    public static function invalid(
        string $errorCode,
        string $errorMessage,
    ): self {
        return new self(
            valid: false,
            canonicalPath: null,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
