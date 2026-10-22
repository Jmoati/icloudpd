<?php

namespace Foundation\Core;

use Closure;
use Foundation\Py;

/**
 * Port of `foundation/core/optional/__init__.py`.
 *
 * Python truthiness (`if input:`) is kept: 0, "" and [] count as missing, like None (`Py::truthy`).
 */
final class Optional
{
    /**
     * Monadic bind for Optional.
     *
     * Example usage:
     *     >>> def div8(divider: int) -> float | None:
     *     ...     if divider == 0:
     *     ...         return None
     *     ...     return 8 / divider
     *     >>> b = bind(div8)
     *
     *     Basic operation on div8:
     *     >>> div8(2) == 4
     *     True
     *     >>> div8(0) == None
     *     True
     *
     *     Passing None throws:
     *     >>> div8(None)
     *     Traceback (most recent call last):
     *         ...
     *     TypeError: unsupported operand type(s) for /: 'int' and 'NoneType'
     *
     *     Binding works for basic and None:
     *     >>> b(2) == 4
     *     True
     *     >>> b(0) == None
     *     True
     *     >>> b(None) == None
     *     True
     */
    public static function bind(callable $func): Closure
    {
        return function ($input) use ($func) {
            if (Py::truthy($input)) {
                return $func($input);
            }
            return null;
        };
    }

    /**
     * Lifts regular function into Optional. (Lift2 for Optional Applicative Functor)
     * (a -> b -> c) -> Maybe a -> Maybe b -> Maybe c
     *
     * Example usage:
     *     >>> def _mul(input: int, input2: int) -> int:
     *     ...     return input * input2
     *     >>> l = lift2(_mul)
     *
     *     Works for numbers:
     *     >>> l(2, 3) == 6
     *     True
     *
     *     Works for None in any and/or all parameter:
     *     >>> l(2, None) == None
     *     True
     *     >>> l(None, 3) == None
     *     True
     *     >>> l(None, None) == None
     *     True
     */
    public static function lift2(callable $func): Closure
    {
        return function ($input, $input2) use ($func) {
            if (Py::truthy($input) && Py::truthy($input2)) {
                return $func($input, $input2);
            }
            return null;
        };
    }

    /**
     * Lifts regular function into Optional. see lift2 for Optional Applicative Functor
     * (a -> b -> c) -> Maybe a -> Maybe b -> Maybe c
     */
    public static function lift3(callable $func): Closure
    {
        return function ($input, $input2, $input3) use ($func) {
            if (Py::truthy($input) && Py::truthy($input2) && Py::truthy($input3)) {
                return $func($input, $input2, $input3);
            }
            return null;
        };
    }

    /**
     * FromMaybe function similar to Haskell's fromMaybe.
     * Takes a default value and returns a function that extracts the value from Maybe,
     * using the default if the Maybe is None.
     *
     * Signature: a -> Maybe a -> a
     *
     * Args:
     *     default: The default value to return when input is None
     *
     * Returns:
     *     A function that takes an optional input and returns the value or default
     *
     * Example usage:
     *     >>> extract_or_zero = fromMaybe(0)
     *     >>> extract_or_zero(5) == 5
     *     True
     *     >>> extract_or_zero(None) == 0
     *     True
     */
    public static function fromMaybe(mixed $default): Closure
    {
        return function ($maybe_value) use ($default) {
            if ($maybe_value !== null) {
                return $maybe_value;
            }
            return $default;
        };
    }
}
