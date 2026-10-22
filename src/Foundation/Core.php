<?php

namespace Foundation;

use Closure;
use Generator;

/**
 * Port of `foundation/core/__init__.py`.
 *
 * Callable arguments are PHP `callable`s, the curried results are `Closure`s. A Python tuple used as a
 * record is a PHP list of the same order; `map`/`filter`/`islice` results are lazy `Generator`s, so (as in
 * Python) they can be consumed once.
 */
final class Core
{
    /**
     * `f after g` composition of functions
     *
     * Equiv: lamdba x -> f(g(x))
     */
    public static function compose(callable $f, callable $g): Closure
    {
        return function ($value) use ($f, $g) {
            return $f($g($value));
        };
    }

    /**
     * identity function
     */
    public static function identity(mixed $value): mixed
    {
        return $value;
    }

    /**
     * constant function
     */
    public static function constant(mixed $value): Closure
    {
        return function ($_) use ($value) {
            return $value;
        };
    }

    /**
     * `g after f` composition of functions (reverse of compose)
     */
    public static function pipe(callable $f, callable $g): Closure
    {
        return self::compose($g, $f);
    }

    /**
     * Applying a function. Equiv curried `(&)` in Haskel
     * a -> (a -> b) -> b
     *
     * Example usage:
     *
     *     >>> def _mul(a: int, b: int) -> int:
     *     ...     return a * b
     *     >>> def _add(a: int, b: int) -> int:
     *     ...     return a + b
     *     >>> list(map(apply_reverse(3), [curry2(_mul)(2), curry2(_add)(5)])) == [6, 8]
     *     True
     */
    public static function apply_reverse(mixed $input): Closure
    {
        return function (callable $func) use ($input) {
            return $func($input);
        };
    }

    /**
     * Transforms 2-param function into two nested 1-param functions
     *
     * >>> def _mul(a: int, b: int) -> int:
     * ...     return a * b
     * >>> _mul(2, 3) == 6
     * True
     * >>> curry2(_mul)(2)(3) == 6
     * True
     */
    public static function curry2(callable $func): Closure
    {
        return function ($input) use ($func) {
            return function ($input2) use ($func, $input) {
                return $func($input, $input2);
            };
        };
    }

    /**
     * Transforms two nested 1-param functions into one 2-param function
     *
     * >>> def _mul_c(a: int):
     * ...     def _intern(b: int) -> int:
     * ...         return a * b
     * ...
     * ...     return _intern
     * >>> _mul_c(2)(3) == 6
     * True
     * >>> uncurry2(_mul_c)(2, 3) == 6
     * True
     */
    public static function uncurry2(callable $func): Closure
    {
        return function ($input, $input2) use ($func) {
            return $func($input)($input2);
        };
    }

    public static function curry3(callable $func): Closure
    {
        return function ($input) use ($func) {
            return function ($input2) use ($func, $input) {
                return function ($input3) use ($func, $input, $input2) {
                    return $func($input, $input2, $input3);
                };
            };
        };
    }

    /**
     * get first of tuple
     * >>> fst((1, 2)) == 1
     * True
     */
    public static function fst(array|\ArrayAccess $t): mixed
    {
        return $t[0];
    }

    /**
     * get second of tuple
     * >>> snd((1, 2)) == 2
     * True
     */
    public static function snd(array|\ArrayAccess $t): mixed
    {
        return $t[1];
    }

    /**
     * flips params
     * >>> def minus(a: int, b: int) -> int:
     * ...     return a - b
     * >>> minus(5, 3) == 2
     * True
     * >>> flip(minus)(5, 3) == -2
     * True
     */
    public static function flip(callable $func): Closure
    {
        return function ($input2, $input) use ($func) {
            return $func($input, $input2);
        };
    }

    /** Compacts two parameters into one tuple */
    public static function compact2(callable $func): Closure
    {
        return function ($input) use ($func) {
            return $func(self::fst($input), self::snd($input));
        };
    }

    public static function expand2(callable $func): Closure
    {
        return function ($input, $input2) use ($func) {
            return $func([$input, $input2]);
        };
    }

    /**
     * `g after f` composition of functions (reverse of compose). f takes 2 params
     * >>> def mul(a: int, b: int) -> int:
     * ...     return a * b
     * >>> def add5(a: int) -> int:
     * ...     return a + 5
     * >>> pipe2(mul, add5)(2, 3) == 11
     * True
     */
    public static function pipe2(callable $f, callable $g): Closure
    {
        return self::expand2(self::pipe(self::compact2($f), $g));
    }

    /** applies different functions for elements of the tuple like Control.Arrow *** */
    public static function arrow(callable $func1, callable $func2, array|\ArrayAccess $inp): array
    {
        return [$func1($inp[0]), $func2($inp[1])];
    }

    /**
     * partial application of one parameter. Safe for static type-checking
     *
     * Equiv: functools.partial
     */
    public static function partial_1_1(callable $f, mixed $p1): Closure
    {
        return function ($value) use ($f, $p1) {
            return $f($p1, $value);
        };
    }

    /**
     * partial application of one parameter. Safe for static type-checking
     *
     * Equiv: functools.partial
     */
    public static function partial_2_1(callable $f, mixed $p1, mixed $p2): Closure
    {
        return function ($value) use ($f, $p1, $p2) {
            return $f($p1, $p2, $value);
        };
    }

    /**
     * typed filter
     *
     * Equiv: functools.filter
     */
    public static function filter_(callable $f, iterable $p1): Generator
    {
        foreach ($p1 as $x) {
            if ($f($x)) {
                yield $x;
            }
        }
    }

    /**
     * typed filter for guarded output
     *
     * Equiv: functools.filter
     */
    public static function filter_guarded(callable $f, iterable $p1): Generator
    {
        return self::filter_($f, $p1);
    }

    /**
     * typed map
     *
     * Equiv: functools.map
     */
    public static function map_(callable $f, iterable $p1): Generator
    {
        foreach ($p1 as $x) {
            yield $f($x);
        }
    }

    /**
     * duplicate iterable
     * >>> inp = [1, 2, 3]
     * >>> a, b = tee_(inp)
     * >>> list(a)
     * [1, 2, 3]
     * >>> list(b)
     * [1, 2, 3]
     */
    public static function tee_(iterable $inp): array
    {
        // ponytail: not lazy, the source is consumed at once (an infinite iterable hangs); a buffered tee if one is ever needed
        $items = is_array($inp) ? array_values($inp) : iterator_to_array($inp, false);
        return [$items, $items];
    }

    /**
     * zip tuple of iterables into iterable of tuples
     * >>> inp = ([1, 2], [4, 5, 6])
     * >>> list(zip_longest_(inp))
     * [(1, 4), (2, 5), (None, 6)]
     */
    public static function zip_longest_(array|\ArrayAccess $inp): Generator
    {
        $a = self::iterator($inp[0]);
        $b = self::iterator($inp[1]);
        $a->rewind();
        $b->rewind();
        while ($a->valid() || $b->valid()) {
            $x = $a->valid() ? $a->current() : null;
            $y = $b->valid() ? $b->current() : null;
            $a->valid() && $a->next();
            $b->valid() && $b->next();
            yield [$x, $y];
        }
    }

    /**
     * unzip iterable of tuples
     * >>> a, b = unzip([(1, 2), (3, 4), (5, 6)])
     * >>> list(a)
     * [1, 3, 5]
     * >>> list(b)
     * [2, 4, 6]
     */
    public static function unzip(iterable $inp): array
    {
        $fst_i = self::partial_1_1(self::map_(...), self::fst(...));
        $snd_i = self::partial_1_1(self::map_(...), self::snd(...));
        $split = self::partial_2_1(self::arrow(...), $fst_i, $snd_i);
        $func = self::compose($split, self::tee_(...));
        return $func($inp);
    }

    public static function chain_from_iterable(iterable $inp): Generator
    {
        foreach ($inp as $it) {
            foreach ($it as $x) {
                yield $x;
            }
        }
    }

    /**
     * typed islice with start
     *
     * Equiv: itertools.islice
     * >>> list(skip(3, [1, 2, 3, 4, 5]))
     * [4, 5]
     */
    public static function skip(int $inp, iterable $p1): Generator
    {
        $i = 0;
        foreach ($p1 as $x) {
            if ($i++ >= $inp) {
                yield $x;
            }
        }
    }

    /**
     * typed islice with stop
     *
     * Equiv: itertools.islice
     * >>> list(take(3, [1, 2, 3, 4, 5]))
     * [1, 2, 3]
     */
    public static function take(int $inp, iterable $p1): Generator
    {
        if ($inp <= 0) {
            return;
        }
        $i = 0;
        foreach ($p1 as $x) {
            yield $x;
            if (++$i >= $inp) {
                return;
            }
        }
    }

    /** `iter(iterable)` */
    private static function iterator(iterable $it): \Iterator
    {
        return match (true) {
            is_array($it) => new \ArrayIterator($it),
            $it instanceof \IteratorAggregate => new \IteratorIterator($it),
            default => $it,
        };
    }
}
