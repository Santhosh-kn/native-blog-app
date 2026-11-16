<?php
declare(strict_types=1);

namespace Bbs\NativeNfc\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static object isAvailable()
 * @method static \Bbs\NativeNfc\NativeNfcResult startScan(?string $id = null)
 * @method static \Bbs\NativeNfc\NativeNfcResult startWrite(string $text, ?string $id = null)
 * @method static \Bbs\NativeNfc\NativeNfcResult getStatus(string $id)
 * @method static \Bbs\NativeNfc\NativeNfcResult stopSession(string $id)
 * @method static string consumeResult(string $id)
 * @see \Bbs\NativeNfc\NativeNfc
 */
final class NativeNfc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativeNfc\NativeNfc::class;
    }
}
