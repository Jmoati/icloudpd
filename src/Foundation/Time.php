<?php

namespace Foundation;

/**
 * Python's `time` module (patchable, see `Foundation\Patch`): `time.sleep`, `time.time` and `time.monotonic`.
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

    /** `time.monotonic()`: seconds of a clock that cannot go backwards, as a float */
    public static function monotonic(): float
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p();
        }
        return hrtime(true) / 1e9;
    }
}
