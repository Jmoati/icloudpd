<?php

namespace Foundation;

use Closure;

/**
 * Point-free string utility functions for functional composition
 *
 * ponytail: `replace` with an empty `old` does not insert `new` between characters as Python does.
 */
final class StringUtils
{
    /**
     * Point-free version of str.strip()
     *
     * >>> strip("  hello  ")
     * 'hello'
     */
    public static function strip(string $s): string
    {
        // str.strip() strips Unicode whitespace (and the C0 separators \x1c-\x1f), unlike trim()
        return preg_replace('/^[\s\x1c-\x1f]+|[\s\x1c-\x1f]+$/u', '', $s) ?? trim($s);
    }

    /**
     * Point-free version of str.lower()
     *
     * >>> lower("HELLO")
     * 'hello'
     */
    public static function lower(string $s): string
    {
        return mb_strtolower($s);
    }

    /**
     * Point-free version of str.upper()
     *
     * >>> upper("hello")
     * 'HELLO'
     */
    public static function upper(string $s): string
    {
        return mb_strtoupper($s);
    }

    /**
     * Curried version of str.endswith()
     *
     * >>> ends_with_txt = endswith(".txt")
     * >>> ends_with_txt("file.txt")
     * True
     * >>> ends_with_txt("file.pdf")
     * False
     * >>> ends_with_images = endswith((".jpg", ".png"))
     * >>> ends_with_images("file.jpg")
     * True
     *
     * @param string|list<string> $suffix a tuple of suffixes is a list
     */
    public static function endswith(string|array $suffix): Closure
    {
        return function (string $s) use ($suffix) {
            foreach ((array) $suffix as $x) {
                if (str_ends_with($s, $x)) {
                    return true;
                }
            }
            return false;
        };
    }

    /**
     * Curried version of str.startswith()
     *
     * >>> starts_with_img = startswith("IMG_")
     * >>> starts_with_img("IMG_1234.jpg")
     * True
     * >>> starts_with_img("DSC_5678.jpg")
     * False
     */
    public static function startswith(string $prefix): Closure
    {
        return fn (string $s) => str_starts_with($s, $prefix);
    }

    /**
     * Check if string contains substring
     *
     * >>> has_hevc = contains("HEVC")
     * >>> has_hevc("IMG_1234_HEVC.MOV")
     * True
     * >>> has_hevc("IMG_1234.MOV")
     * False
     */
    public static function contains(string $substring): Closure
    {
        return fn (string $s) => str_contains($s, $substring);
    }

    /**
     * String equality predicate
     *
     * >>> is_none = eq("none")
     * >>> is_none("none")
     * True
     * >>> is_none("other")
     * False
     */
    public static function eq(string $expected): Closure
    {
        return fn (string $actual) => $actual === $expected;
    }

    /**
     * Curried version of str.replace()
     *
     * >>> replace_heic = replace(".HEIC", ".MOV")
     * >>> replace_heic("IMG_1234.HEIC")
     * 'IMG_1234.MOV'
     */
    public static function replace(string $old, string $new): Closure
    {
        return fn (string $s) => str_replace($old, $new, $s);
    }

    /**
     * Curried version of str.split()
     *
     * >>> split_lines = split("\\n")
     * >>> split_lines("line1\\nline2\\nline3")
     * ['line1', 'line2', 'line3']
     */
    public static function split(string $separator): Closure
    {
        return fn (string $s) => explode($separator, $s);
    }

    /**
     * Curried version of str.join()
     *
     * >>> join_with_dot = join(".")
     * >>> join_with_dot(["filename", "txt"])
     * 'filename.txt'
     */
    public static function join(string $separator): Closure
    {
        return fn (array $parts) => implode($separator, $parts);
    }

    /**
     * Check if string is empty
     *
     * >>> is_empty("")
     * True
     * >>> is_empty("hello")
     * False
     */
    public static function is_empty(string $s): bool
    {
        return mb_strlen($s) === 0;
    }

    /**
     * Check if string is not empty
     *
     * >>> is_not_empty("hello")
     * True
     * >>> is_not_empty("")
     * False
     */
    public static function is_not_empty(string $s): bool
    {
        return mb_strlen($s) > 0;
    }

    // Composed utilities for common patterns

    /**
     * Strip whitespace and convert to lowercase
     *
     * >>> strip_and_lower('  HELLO  ')
     * 'hello'
     */
    public static function strip_and_lower(string $s): string
    {
        return Core::compose(self::lower(...), self::strip(...))($s);
    }

    /**
     * Replace file extension with new extension
     *
     * >>> replace_with_mov = replace_extension(".MOV")
     * >>> replace_with_mov("IMG_1234.HEIC")
     * 'IMG_1234.MOV'
     * >>> replace_with_mov("no_extension")
     * 'no_extension'
     */
    public static function replace_extension(string $new_ext): Closure
    {
        return function (string $filename) use ($new_ext) {
            [$name, $ext] = Os::path_splitext($filename);
            if (!$ext) {
                return $filename;
            }
            return $name.$new_ext;
        };
    }
}
