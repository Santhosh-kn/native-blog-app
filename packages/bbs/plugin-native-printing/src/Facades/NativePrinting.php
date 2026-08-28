<?php

declare(strict_types=1);

namespace Bbs\NativePrinting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static object isAvailable()
 * @method static object preview(string $path, string $title = 'PDF Preview', ?string $requestId = null)
 * @method static object print(string $path, string $jobName = 'Document', ?string $requestId = null)
 *
 * @see \Bbs\NativePrinting\NativePrinting
 */
final class NativePrinting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativePrinting\NativePrinting::class;
    }
}
