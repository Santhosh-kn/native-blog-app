<?php

namespace Bbs\Biometric\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static object|null isAvailable()
 * @method static void authenticate(string $title = 'Authenticate', string $subtitle = 'Confirm your identity', ?string $id = null)
 *
 * @see \Bbs\Biometric\Biometric
 */
class Biometric extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\Biometric\Biometric::class;
    }
}