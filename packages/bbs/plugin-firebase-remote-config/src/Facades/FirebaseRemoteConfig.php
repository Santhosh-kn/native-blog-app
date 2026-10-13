<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Facades;

use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig as Service;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object isAvailable()
 * @method static object configure(array $settings = [], ?string $id = null)
 * @method static object setDefaults(array $defaults, ?string $id = null)
 * @method static object getValues(array $values)
 * @method static object getValue(string $key, string $type)
 * @method static object getString(string $key)
 * @method static object getBoolean(string $key)
 * @method static object getInteger(string $key)
 * @method static object getDecimal(string $key)
 * @method static object fetch(?string $id = null)
 * @method static object activate(?string $id = null)
 * @method static object fetchAndActivate(?string $id = null)
 * @method static object getStatus(string $id)
 * @method static object getInfo()
 */
final class FirebaseRemoteConfig extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Service::class;
    }
}
