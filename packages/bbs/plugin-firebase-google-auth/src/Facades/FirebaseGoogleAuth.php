<?php

namespace Bbs\FirebaseGoogleAuth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed execute(array $options = [])
 * @method static object|null getStatus()
 *
 * @see \Bbs\FirebaseGoogleAuth\FirebaseGoogleAuth
 */
class FirebaseGoogleAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\FirebaseGoogleAuth\FirebaseGoogleAuth::class;
    }
}