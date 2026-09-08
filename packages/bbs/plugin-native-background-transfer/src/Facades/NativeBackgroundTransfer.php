<?php

namespace Bbs\NativeBackgroundTransfer\Facades;

use Bbs\NativeBackgroundTransfer\Support\NativeBackgroundTransferResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object startDownload(array $options)
 * @method static NativeBackgroundTransferResult getStatus(string $id)
 * @method static array<int, NativeBackgroundTransferResult> listTransfers()
 * @method static NativeBackgroundTransferResult cancel(string $id)
 * @method static NativeBackgroundTransferResult consumeResult(string $id)
 *
 * @see \Bbs\NativeBackgroundTransfer\NativeBackgroundTransfer
 */
class NativeBackgroundTransfer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativeBackgroundTransfer\NativeBackgroundTransfer::class;
    }
}