<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Facades;

use Bbs\NativeCalendar\Support\NativeCalendarResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object isAvailable()
 * @method static NativeCalendarResult createEvent(array $options = [])
 * @method static NativeCalendarResult open(array $options = [])
 * @method static NativeCalendarResult getStatus(mixed $id)
 *
 * @see \Bbs\NativeCalendar\NativeCalendar
 */
final class NativeCalendar extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bbs\NativeCalendar\NativeCalendar::class;
    }
}
