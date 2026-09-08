<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker\Facades;

use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object pick(array $options = [])
 * @method static NativeDocumentPickerResult getStatus(string $id)
 *
 * @see \Bbs\NativeDocumentPicker\NativeDocumentPicker
 */
final class NativeDocumentPicker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativeDocumentPicker\NativeDocumentPicker::class;
    }
}
