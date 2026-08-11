<?php

namespace Santhosh\FirebaseGoogleAuth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed execute(array $options = [])
 * @method static object|null getStatus()
 *
 * @see \Santhosh\FirebaseGoogleAuth\FirebaseGoogleAuth
 */
class FirebaseGoogleAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Santhosh\FirebaseGoogleAuth\FirebaseGoogleAuth::class;
    }
}