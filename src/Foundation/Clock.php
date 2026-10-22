<?php

namespace Foundation;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Python's `datetime.datetime.now()` (patchable: the upstream tests freeze it with freezegun).
 *
 * A naive `datetime.now()` and `datetime.now(tz)` both return an aware `DateTimeImmutable`; without `$tz`
 * the zone is the local one (`get_localzone()`).
 */
final class Clock
{
    /** `datetime.now(tz)` */
    public static function now(?DateTimeZone $tz = null): DateTimeImmutable
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($tz);
        }
        return new DateTimeImmutable('now', $tz ?? self::get_localzone());
    }

    /**
     * `tzlocal.get_localzone()`
     *
     * ponytail: this is PHP's default timezone, which is UTC unless the entry point sets it
     * (`date_default_timezone_set()`) from the system (TZ, /etc/localtime).
     */
    public static function get_localzone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }
}
