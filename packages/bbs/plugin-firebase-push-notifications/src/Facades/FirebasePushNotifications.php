<?php

namespace Bbs\FirebasePushNotifications\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static object|null checkPermission()
 * @method static object|null requestPermission()
 * @method static object|null getToken(?string $id = null)
 * @method static object|null getStoredToken()
 *
 * @see \Bbs\FirebasePushNotifications\FirebasePushNotifications
 */
class FirebasePushNotifications extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\FirebasePushNotifications\FirebasePushNotifications::class;
    }
}
