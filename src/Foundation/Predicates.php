<?php

namespace Foundation;

use Closure;

/**
 * Boolean predicate composition utilities for functional programming
 *
 * ponytail: `===` where Python has `==` (`eq_pred(5)(5.0)` is false here) and `in_array(…, true)` for `in`.
 */
final class Predicates
{
    /**
     * Logical AND composition of predicates
     *
     * >>> is_positive = lambda x: x > 0
     * >>> is_even = lambda x: x % 2 == 0
     * >>> is_positive_and_even = and_(is_positive, is_even)
     * >>> is_positive_and_even(4)
     * True
     * >>> is_positive_and_even(-2)
     * False
     * >>> is_positive_and_even(3)
     * False
     */
    public static function and_(callable $f1, callable $f2): Closure
    {
        return fn ($x) => $f1($x) && $f2($x);
    }

    /**
     * Logical OR composition of predicates
     *
     * >>> is_negative = lambda x: x < 0
     * >>> is_zero = lambda x: x == 0
     * >>> is_not_positive = or_(is_negative, is_zero)
     * >>> is_not_positive(-1)
     * True
     * >>> is_not_positive(0)
     * True
     * >>> is_not_positive(1)
     * False
     */
    public static function or_(callable $f1, callable $f2): Closure
    {
        return fn ($x) => $f1($x) || $f2($x);
    }

    /**
     * Logical NOT of a predicate
     *
     * >>> is_positive = lambda x: x > 0
     * >>> is_not_positive = not_(is_positive)
     * >>> is_not_positive(-1)
     * True
     * >>> is_not_positive(1)
     * False
     */
    public static function not_(callable $f): Closure
    {
        return fn ($x) => !$f($x);
    }

    /**
     * Logical XOR composition of predicates
     *
     * >>> is_odd = lambda x: x % 2 == 1
     * >>> is_positive = lambda x: x > 0
     * >>> is_odd_xor_positive = xor_(is_odd, is_positive)
     * >>> is_odd_xor_positive(1)  # odd and positive
     * False
     * >>> is_odd_xor_positive(-3)  # odd but not positive
     * True
     * >>> is_odd_xor_positive(2)  # positive but not odd
     * True
     */
    public static function xor_(callable $f1, callable $f2): Closure
    {
        return fn ($x) => (bool) $f1($x) !== (bool) $f2($x);
    }

    /**
     * Predicate that always returns True
     *
     * >>> always_true(42)
     * True
     * >>> always_true("anything")
     * True
     */
    public static function always_true(mixed $_): bool
    {
        return true;
    }

    /**
     * Predicate that always returns False
     *
     * >>> always_false(42)
     * False
     * >>> always_false("anything")
     * False
     */
    public static function always_false(mixed $_): bool
    {
        return false;
    }

    /**
     * Equality predicate generator
     *
     * >>> is_five = eq_pred(5)
     * >>> is_five(5)
     * True
     * >>> is_five(3)
     * False
     */
    public static function eq_pred(mixed $expected): Closure
    {
        return fn ($actual) => $actual === $expected;
    }

    /**
     * Not equal predicate generator
     *
     * >>> is_not_five = ne_pred(5)
     * >>> is_not_five(3)
     * True
     * >>> is_not_five(5)
     * False
     */
    public static function ne_pred(mixed $expected): Closure
    {
        return fn ($actual) => $actual !== $expected;
    }

    /**
     * Membership predicate generator
     *
     * >>> is_vowel = in_pred(["a", "e", "i", "o", "u"])
     * >>> is_vowel("a")
     * True
     * >>> is_vowel("b")
     * False
     */
    public static function in_pred(array $container): Closure
    {
        return fn ($item) => in_array($item, $container, true);
    }

    /**
     * Not in membership predicate generator
     *
     * >>> is_consonant = not_in_pred(["a", "e", "i", "o", "u"])
     * >>> is_consonant("b")
     * True
     * >>> is_consonant("a")
     * False
     */
    public static function not_in_pred(array $container): Closure
    {
        return fn ($item) => !in_array($item, $container, true);
    }
}
