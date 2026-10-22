<?php

namespace Foundation;

/**
 * Port of `foundation/string.py` (`String` is a reserved word in PHP).
 */
final class String_
{
    /**
     * `obfuscate` is a `singledispatch` on str: `OBFUSCATED-<crc32 of the UTF-8 bytes>`;
     * any other type raises `NotImplementedError`.
     */
    public static function obfuscate(mixed $_input): string
    {
        if (is_string($_input)) {
            return 'OBFUSCATED-'.crc32($_input);
        }
        throw new \BadMethodCallException('NotImplementedError');
    }
}
