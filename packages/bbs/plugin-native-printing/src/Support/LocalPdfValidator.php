<?php

declare(strict_types=1);

namespace Bbs\NativePrinting\Support;

use InvalidArgumentException;

final class LocalPdfValidator
{
    private const PDF_SIGNATURE = '%PDF-';

    private const EOF_MARKER = '%%EOF';

    private const TAIL_BYTES = 8192;

    /** @var list<string> */
    private array $allowedRoots;

    /**
     * @param  list<string>  $allowedRoots
     */
    public function __construct(array $allowedRoots)
    {
        $canonicalRoots = [];

        foreach ($allowedRoots as $root) {
            $canonicalRoot = realpath($root);

            if ($canonicalRoot === false || ! is_dir($canonicalRoot)) {
                continue;
            }

            $canonicalRoots[] = $this->normalizeForComparison(
                $canonicalRoot,
            );
        }

        $this->allowedRoots = array_values(
            array_unique($canonicalRoots),
        );

        if ($this->allowedRoots === []) {
            throw new InvalidArgumentException('At least one valid application-local root is required.');
        }
    }

    public function validate(string $path): PdfValidationResult
    {
        $path = trim($path);

        if ($path === '' || ! file_exists($path)) {
            return PdfValidationResult::invalid(NativePrintingErrorCode::FILE_NOT_FOUND, 'The selected PDF could not be found.');
        }

        $canonicalPath = realpath($path);

        if ($canonicalPath === false) {
            return PdfValidationResult::invalid(NativePrintingErrorCode::FILE_NOT_FOUND, 'The selected PDF could not be found.');
        }

        if (! is_file($canonicalPath)) {
            return PdfValidationResult::invalid(NativePrintingErrorCode::INVALID_FILE_TYPE, 'The selected item is not a PDF file.');
        }

        if (! $this->isInsideAllowedRoot($canonicalPath)) {
            return PdfValidationResult::invalid(NativePrintingErrorCode::FILE_OUTSIDE_APP_STORAGE, 'The PDF must be stored in application-local storage.');
        }

        if (! is_readable($canonicalPath)) {
            return PdfValidationResult::invalid(NativePrintingErrorCode::FILE_NOT_READABLE, 'The selected PDF cannot be read.');
        }

        if (strtolower(pathinfo($canonicalPath, PATHINFO_EXTENSION)) !== 'pdf') {
            return PdfValidationResult::invalid(NativePrintingErrorCode::INVALID_FILE_TYPE, 'The selected file must use the PDF file type.');
        }

        if (! $this->containsPdfMarkers($canonicalPath)) {
            return PdfValidationResult::invalid(NativePrintingErrorCode::INVALID_PDF, 'The selected file is not a valid PDF.');
        }

        return PdfValidationResult::valid($canonicalPath);
    }

    private function isInsideAllowedRoot(string $canonicalPath): bool
    {
        $candidate = $this->normalizeForComparison($canonicalPath);

        foreach ($this->allowedRoots as $root) {
            if ($candidate === $root) {
                return true;
            }

            $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            if (str_starts_with($candidate, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForComparison(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $normalized = rtrim($normalized, DIRECTORY_SEPARATOR);

        if (PHP_OS_FAMILY === 'Windows') {
            return strtolower($normalized);
        }

        return $normalized;
    }

    private function containsPdfMarkers(string $path): bool
    {
        $size = filesize($path);

        if ($size === false || $size < strlen(self::PDF_SIGNATURE)) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $signature = fread($handle, strlen(self::PDF_SIGNATURE));

            if ($signature !== self::PDF_SIGNATURE) {
                return false;
            }

            $tailLength = min(self::TAIL_BYTES, $size);

            if (fseek($handle, -$tailLength, SEEK_END) !== 0) {
                return false;
            }

            $tail = stream_get_contents($handle);

            return is_string($tail) && str_contains($tail, self::EOF_MARKER);
        } finally {
            fclose($handle);
        }
    }
}
