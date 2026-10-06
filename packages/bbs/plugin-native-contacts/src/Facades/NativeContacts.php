<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Facades;

use Bbs\NativeContacts\Support\NativeContactsResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object isAvailable()
 * @method static NativeContactsResult pick(array $options = [])
 * @method static NativeContactsResult create(array $options = [])
 * @method static NativeContactsResult open(mixed $uri, array $options = [])
 * @method static NativeContactsResult getStatus(mixed $id)
 * @method static NativeContactsResult consumeResult(mixed $id)
 *
 * @see \Bbs\NativeContacts\NativeContacts
 */
class NativeContacts extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativeContacts\NativeContacts::class;
    }
}