<?php

namespace Foundation;

use Closure;
use Generator;

/**
 * Port of `foundation/__init__.py`.
 */
final class Foundation
{
    /** will be updated by CI; use `version_info()` */
    public static ?VersionInfo $version_info = null;

    /** `foundation.version_info` */
    public static function version_info(): VersionInfo
    {
        return self::$version_info ??= new VersionInfo(
            version: '0.0.1',
            commit_sha: 'abcdefgh',
            commit_timestamp: 1234567890,
        );
    }

    public static function version_info_formatted(): string
    {
        $vi = self::version_info();
        $ts = (new \DateTimeImmutable('@'.$vi->commit_timestamp))->setTimezone(Clock::get_localzone());
        return str_replace(
            '  ',
            ' ',
            "version:{$vi->version}, commit sha:{$vi->commit_sha}, commit timestamp:".Py::format('{:%c %Z}', $ts),
        );
    }

    public static function bytes_decode(string $encoding): Closure
    {
        return function (string $inp) use ($encoding): string {
            // ponytail: the encodings Python's codecs name that mbstring knows under the same name; strict, as decode() is
            $codec = strtolower(str_replace('_', '-', $encoding));
            $mb = ['utf8' => 'UTF-8', 'utf-8' => 'UTF-8', 'ascii' => 'ASCII', 'latin-1' => 'ISO-8859-1', 'latin1' => 'ISO-8859-1', 'iso-8859-1' => 'ISO-8859-1'][$codec] ?? $encoding;
            if (!mb_check_encoding($inp, $mb)) {
                throw new \ValueError("'$codec' codec can't decode bytes: invalid ".Py::repr($inp));
            }
            return $mb === 'UTF-8' ? $inp : mb_convert_encoding($inp, 'UTF-8', $mb);
        };
    }

    /** Decorator to wrap excpetion into ValueError with the dump of the input parameter */
    public static function wrap_param_in_exception(string $caption, callable $func): Closure
    {
        return function ($input) use ($caption, $func) {
            try {
                return $func($input);
            } catch (\Throwable $err) {
                throw new \ValueError("Invalid Input ($caption): ".Py::repr($input), 0, $err);
            }
        };
    }

    // def is_none(input: T_in | None) -> bool:
    //     return input is None

    public static function is_none(mixed $input): bool
    {
        return $input === null;
    }

    public static function is_not_none(mixed $input): bool
    {
        return Core::compose(Py::not_(...), self::is_none(...))($input);
    }

    public static function empty_pairs(iterable $pairs): Generator
    {
        return Core::partial_1_1(Core::filter_(...), Core::compose(self::is_none(...), Core::snd(...)))($pairs);
    }

    public static function keys_from_pairs(iterable $pairs): Generator
    {
        return Core::partial_1_1(Core::map_(...), Core::fst(...))($pairs);
    }

    public static function keys_for_empty_values(iterable $pairs): Generator
    {
        return Core::compose(self::keys_from_pairs(...), self::empty_pairs(...))($pairs);
    }

    public static function non_empty_pairs(iterable $pairs): Generator
    {
        return Core::partial_1_1(Core::filter_(...), Core::compose(self::is_not_none(...), Core::snd(...)))($pairs);
    }

    /** @param iterable<array> $input the dicts */
    public static function flat_dict(iterable $input): array
    {
        $flattened_dict = [];
        foreach ($input as $d) {
            // dict.update: later keys win, insertion order of the first occurrence kept
            foreach ($d as $k => $v) {
                $flattened_dict[$k] = $v;
            }
        }
        return $flattened_dict;
    }

    /**
     * Breaks incoming sequence into subsequences based on supplied slitter. Splitter is supported as sequence of alternatives.
     * >>> split_with_alternatives([2, 4], [1, 2, 3, 2, 5, 4, 6])
     * [[1], [2, 3], [2, 5], [4, 6]]
     */
    public static function split_with_alternatives(array $splitter, iterable $inp): array
    {
        $result = [[]];
        foreach ($inp as $item) {
            if (in_array($item, $splitter, true)) {
                //  add group
                $result[] = [];
            }
            $group_index = count($result) - 1;
            $result[$group_index][] = $item;
        }
        return $result;
    }

    /**
     * Converts two positional arguments into tuple
     * >>> two_tuple(1, 2)
     * (1, 2)
     */
    public static function two_tuple(mixed $k, mixed $v): array
    {
        return [$k, $v];
    }

    /**
     * Unique values from iterable
     * >>> unique_sequence(["abc", "def", "abc", "ghi"])
     * ['abc', 'def', 'ghi']
     * >>> unique_sequence([1, 2, 1, 3])
     * [1, 2, 3]
     */
    public static function unique_sequence(iterable $inp): array
    {
        // ponytail: Python dedupes through the keys of a dict; PHP array keys turn "1" into 1, so compare
        // values strictly instead (quadratic, the inputs are command-line sizes)
        $result = [];
        foreach ($inp as $item) {
            if (!in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
