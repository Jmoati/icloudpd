<?php

namespace Foundation;

/**
 * Python's `time` module (patchable, see `Foundation\Patch`): `time.sleep` and `time.time`.
 */
final class Time
{
    /** `time.sleep(seconds)` */
    public static function sleep(float $seconds): void
    {
        if ($p = Patch::get(__METHOD__)) {
            $p($seconds);
            return;
        }
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /** `time.time()`: seconds since the epoch, as a float */
    public static function time(): float
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p();
        }
        return microtime(true);
    }
}
