<?php

namespace Foundation\Plist;

/**
 * plistlib.UID: used by Key-Archiver plist files
 */
final class UID
{
    /** @param int|\GMP $data (a GMP when it does not fit in 64 bits) */
    public function __construct(public int|\GMP $data)
    {
    }
}
