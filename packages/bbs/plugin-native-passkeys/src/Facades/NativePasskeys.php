<?php

declare(strict_types=1);

namespace Bbs\NativePasskeys\Facades;

use Bbs\NativePasskeys\Support\NativePasskeysResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object isAvailable()
 * @method static object create(string $requestJson, ?string $id = null)
 * @method static object authenticate(string $requestJson, ?string $id = null)
 * @method static NativePasskeysResult getStatus(string $id)
 * @method static NativePasskeysResult consumeResult(string $id)
 * @method static NativePasskeysResult cancel(string $id)
 *
 * @see \Bbs\NativePasskeys\NativePasskeys
 */
final class NativePasskeys extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativePasskeys\NativePasskeys::class;
    }
}
