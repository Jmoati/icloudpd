<?php

namespace Foundation;

use Foundation\Plist\BinaryPlistParser;
use Foundation\Plist\InvalidFileException;
use Foundation\Plist\PlistParser;

/**
 * Port of the reading side of Python's plistlib (`plistlib.loads`): binary (`bplist00`) and XML plists.
 *
 * Python → PHP: None → null, bool/int/float → bool/int/float (an int beyond 64 bits → \GMP),
 * str → string (UTF-8), bytes → string (binary), datetime → \DateTimeImmutable in UTC (naive, as plistlib
 * returns it: the wall-clock fields are what counts), list → list array, dict → array, UID → Plist\UID.
 *
 * ponytail: a plist `data` and a plist `string` are both a PHP string and an empty dict equals an empty array;
 * `aware_datetime`, `dict_type` and the writer (`dump`/`dumps`) are not ported, nothing in icloudpd uses them.
 */
final class Plist
{
    public const FMT_XML = 1;
    public const FMT_BINARY = 2;

    public static function _is_fmt_xml(string $header): bool
    {
        $prefixes = ['<?xml', '<plist'];

        foreach ($prefixes as $pfx) {
            if (str_starts_with($header, $pfx)) {
                return true;
            }
        }

        // Also check for alternative XML encodings, this is slightly
        // overkill because the Apple tools (and plistlib) will not
        // generate files with these encodings.
        foreach ([
            ["\xEF\xBB\xBF", 'UTF-8'],
            ["\xFE\xFF", 'UTF-16BE'],
            ["\xFF\xFE", 'UTF-16LE'],
            // expat does not support utf-32
        ] as [$bom, $encoding]) {
            if (!str_starts_with($header, $bom)) {
                continue;
            }

            foreach ($prefixes as $start) {
                $prefix = $bom.mb_convert_encoding($start, $encoding, 'ASCII');
                if (substr($header, 0, strlen($prefix)) === $prefix) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function _is_fmt_binary(string $header): bool
    {
        return substr($header, 0, 8) === 'bplist00';
    }

    /**
     * Read a .plist file from a binary string.
     * Return the unpacked root object (which usually is a dictionary).
     */
    public static function loads(string $value, ?int $fmt = null): mixed
    {
        if ($fmt === null) {
            $header = substr($value, 0, 32);
            if (self::_is_fmt_xml($header)) {
                $P = PlistParser::class;
            } elseif (self::_is_fmt_binary($header)) {
                $P = BinaryPlistParser::class;
            } else {
                throw new InvalidFileException();
            }
        } else {
            $P = $fmt === self::FMT_XML ? PlistParser::class : BinaryPlistParser::class;
        }

        return (new $P())->parse($value);
    }
}
