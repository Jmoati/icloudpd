<?php

namespace Tests;

use Foundation\Core;
use Foundation\Core\Optional;
use Foundation\Foundation;
use Foundation\Predicates;
use Foundation\StringUtils;

/**
 * The doctests of `src/foundation` (upstream runs them with `pytest --doctest-modules`): one method per
 * collected docstring, `test_<module>_<function>`, in the same order.
 */
class FoundationDoctestsTest extends TestCase
{
    private static function list_(iterable $it): array
    {
        return is_array($it) ? array_values($it) : iterator_to_array($it, false);
    }

    // ---------------------------------------------------------------- foundation/__init__.py

    public function test_foundation_split_with_alternatives(): void
    {
        $this->assertSame([[1], [2, 3], [2, 5], [4, 6]], Foundation::split_with_alternatives([2, 4], [1, 2, 3, 2, 5, 4, 6]));
    }

    public function test_foundation_two_tuple(): void
    {
        $this->assertSame([1, 2], Foundation::two_tuple(1, 2));
    }

    public function test_foundation_unique_sequence(): void
    {
        $this->assertSame(['abc', 'def', 'ghi'], Foundation::unique_sequence(['abc', 'def', 'abc', 'ghi']));
        $this->assertSame([1, 2, 3], Foundation::unique_sequence([1, 2, 1, 3]));
        // array keys would have made "1" an int
        $this->assertSame(['1', 1, '2'], Foundation::unique_sequence(['1', 1, '1', '2']));
    }

    // ---------------------------------------------------------------- foundation/core/__init__.py

    public function test_core_apply_reverse(): void
    {
        $_mul = fn (int $a, int $b): int => $a * $b;
        $_add = fn (int $a, int $b): int => $a + $b;
        $this->assertTrue(self::list_(Core::map_(Core::apply_reverse(3), [Core::curry2($_mul)(2), Core::curry2($_add)(5)])) == [6, 8]);
    }

    public function test_core_curry2(): void
    {
        $_mul = fn (int $a, int $b): int => $a * $b;
        $this->assertTrue($_mul(2, 3) == 6);
        $this->assertTrue(Core::curry2($_mul)(2)(3) == 6);
    }

    public function test_core_flip(): void
    {
        $minus = fn (int $a, int $b): int => $a - $b;
        $this->assertTrue($minus(5, 3) == 2);
        $this->assertTrue(Core::flip($minus)(5, 3) == -2);
    }

    public function test_core_fst(): void
    {
        $this->assertTrue(Core::fst([1, 2]) == 1);
    }

    public function test_core_pipe2(): void
    {
        $mul = fn (int $a, int $b): int => $a * $b;
        $add5 = fn (int $a): int => $a + 5;
        $this->assertTrue(Core::pipe2($mul, $add5)(2, 3) == 11);
    }

    public function test_core_skip(): void
    {
        $this->assertSame([4, 5], self::list_(Core::skip(3, [1, 2, 3, 4, 5])));
    }

    public function test_core_snd(): void
    {
        $this->assertTrue(Core::snd([1, 2]) == 2);
    }

    public function test_core_take(): void
    {
        $this->assertSame([1, 2, 3], self::list_(Core::take(3, [1, 2, 3, 4, 5])));
    }

    public function test_core_tee_(): void
    {
        $inp = [1, 2, 3];
        [$a, $b] = Core::tee_($inp);
        $this->assertSame([1, 2, 3], self::list_($a));
        $this->assertSame([1, 2, 3], self::list_($b));
    }

    public function test_core_uncurry2(): void
    {
        $_mul_c = function (int $a) {
            return function (int $b) use ($a): int {
                return $a * $b;
            };
        };
        $this->assertTrue($_mul_c(2)(3) == 6);
        $this->assertTrue(Core::uncurry2($_mul_c)(2, 3) == 6);
    }

    public function test_core_unzip(): void
    {
        [$a, $b] = Core::unzip([[1, 2], [3, 4], [5, 6]]);
        $this->assertSame([1, 3, 5], self::list_($a));
        $this->assertSame([2, 4, 6], self::list_($b));
    }

    public function test_core_zip_longest_(): void
    {
        $inp = [[1, 2], [4, 5, 6]];
        $this->assertSame([[1, 4], [2, 5], [null, 6]], self::list_(Core::zip_longest_($inp)));
    }

    /** `take` stops without consuming one more item, as `islice` does */
    public function test_core_take_is_lazy(): void
    {
        $consumed = 0;
        $gen = (function () use (&$consumed) {
            foreach ([1, 2, 3, 4] as $x) {
                $consumed++;
                yield $x;
            }
        })();
        $this->assertSame([1, 2], self::list_(Core::take(2, $gen)));
        $this->assertSame(2, $consumed);
    }

    // ---------------------------------------------------------------- foundation/core/optional/__init__.py

    public function test_core_optional_bind(): void
    {
        $div8 = function (int $divider): int|float|null {
            if ($divider == 0) {
                return null;
            }
            return 8 / $divider;
        };
        $b = Optional::bind($div8);

        // Basic operation on div8:
        $this->assertTrue($div8(2) == 4);
        $this->assertTrue($div8(0) == null);

        // Passing None throws (PHP: a TypeError on the int parameter):
        $this->expectException(\TypeError::class);
        try {
            $div8(null);
        } finally {
            // Binding works for basic and None:
            $this->assertTrue($b(2) == 4);
            $this->assertTrue($b(0) == null);
            $this->assertTrue($b(null) == null);
        }
    }

    public function test_core_optional_fromMaybe(): void
    {
        $extract_or_zero = Optional::fromMaybe(0);
        $this->assertTrue($extract_or_zero(5) == 5);
        $this->assertTrue($extract_or_zero(null) == 0);
    }

    public function test_core_optional_lift2(): void
    {
        $_mul = fn (int $input, int $input2): int => $input * $input2;
        $l = Optional::lift2($_mul);

        // Works for numbers:
        $this->assertTrue($l(2, 3) == 6);

        // Works for None in any and/or all parameter:
        $this->assertTrue($l(2, null) == null);
        $this->assertTrue($l(null, 3) == null);
        $this->assertTrue($l(null, null) == null);
    }

    /** Python truthiness: "0" is truthy, 0 and "" are not */
    public function test_core_optional_truthiness(): void
    {
        $this->assertSame('x', Optional::bind(fn ($v) => 'x')('0'));
        $this->assertNull(Optional::bind(fn ($v) => 'x')(0));
        $this->assertNull(Optional::bind(fn ($v) => 'x')(''));
        $this->assertSame(6, Optional::lift3(fn ($a, $b, $c) => $a * $b * $c)(1, 2, 3));
        $this->assertNull(Optional::lift3(fn ($a, $b, $c) => $a * $b * $c)(1, 2, 0));
    }

    // ---------------------------------------------------------------- foundation/predicates.py

    public function test_predicates_always_false(): void
    {
        $this->assertFalse(Predicates::always_false(42));
        $this->assertFalse(Predicates::always_false('anything'));
    }

    public function test_predicates_always_true(): void
    {
        $this->assertTrue(Predicates::always_true(42));
        $this->assertTrue(Predicates::always_true('anything'));
    }

    public function test_predicates_and_(): void
    {
        $is_positive = fn ($x) => $x > 0;
        $is_even = fn ($x) => $x % 2 == 0;
        $is_positive_and_even = Predicates::and_($is_positive, $is_even);
        $this->assertTrue($is_positive_and_even(4));
        $this->assertFalse($is_positive_and_even(-2));
        $this->assertFalse($is_positive_and_even(3));
    }

    public function test_predicates_eq_pred(): void
    {
        $is_five = Predicates::eq_pred(5);
        $this->assertTrue($is_five(5));
        $this->assertFalse($is_five(3));
    }

    public function test_predicates_in_pred(): void
    {
        $is_vowel = Predicates::in_pred(['a', 'e', 'i', 'o', 'u']);
        $this->assertTrue($is_vowel('a'));
        $this->assertFalse($is_vowel('b'));
    }

    public function test_predicates_ne_pred(): void
    {
        $is_not_five = Predicates::ne_pred(5);
        $this->assertTrue($is_not_five(3));
        $this->assertFalse($is_not_five(5));
    }

    public function test_predicates_not_(): void
    {
        $is_positive = fn ($x) => $x > 0;
        $is_not_positive = Predicates::not_($is_positive);
        $this->assertTrue($is_not_positive(-1));
        $this->assertFalse($is_not_positive(1));
    }

    public function test_predicates_not_in_pred(): void
    {
        $is_consonant = Predicates::not_in_pred(['a', 'e', 'i', 'o', 'u']);
        $this->assertTrue($is_consonant('b'));
        $this->assertFalse($is_consonant('a'));
    }

    public function test_predicates_or_(): void
    {
        $is_negative = fn ($x) => $x < 0;
        $is_zero = fn ($x) => $x == 0;
        $is_not_positive = Predicates::or_($is_negative, $is_zero);
        $this->assertTrue($is_not_positive(-1));
        $this->assertTrue($is_not_positive(0));
        $this->assertFalse($is_not_positive(1));
    }

    public function test_predicates_xor_(): void
    {
        $is_odd = fn ($x) => ((($x % 2) + 2) % 2) == 1; // Python's modulo has the sign of the divisor
        $is_positive = fn ($x) => $x > 0;
        $is_odd_xor_positive = Predicates::xor_($is_odd, $is_positive);
        $this->assertFalse($is_odd_xor_positive(1));  // odd and positive
        $this->assertTrue($is_odd_xor_positive(-3));  // odd but not positive
        $this->assertTrue($is_odd_xor_positive(2));  // positive but not odd
    }

    // ---------------------------------------------------------------- foundation/string_utils.py

    public function test_string_utils_contains(): void
    {
        $has_hevc = StringUtils::contains('HEVC');
        $this->assertTrue($has_hevc('IMG_1234_HEVC.MOV'));
        $this->assertFalse($has_hevc('IMG_1234.MOV'));
    }

    public function test_string_utils_endswith(): void
    {
        $ends_with_txt = StringUtils::endswith('.txt');
        $this->assertTrue($ends_with_txt('file.txt'));
        $this->assertFalse($ends_with_txt('file.pdf'));
        $ends_with_images = StringUtils::endswith(['.jpg', '.png']);
        $this->assertTrue($ends_with_images('file.jpg'));
    }

    public function test_string_utils_eq(): void
    {
        $is_none = StringUtils::eq('none');
        $this->assertTrue($is_none('none'));
        $this->assertFalse($is_none('other'));
    }

    public function test_string_utils_is_empty(): void
    {
        $this->assertTrue(StringUtils::is_empty(''));
        $this->assertFalse(StringUtils::is_empty('hello'));
    }

    public function test_string_utils_is_not_empty(): void
    {
        $this->assertTrue(StringUtils::is_not_empty('hello'));
        $this->assertFalse(StringUtils::is_not_empty(''));
    }

    public function test_string_utils_join(): void
    {
        $join_with_dot = StringUtils::join('.');
        $this->assertSame('filename.txt', $join_with_dot(['filename', 'txt']));
    }

    public function test_string_utils_lower(): void
    {
        $this->assertSame('hello', StringUtils::lower('HELLO'));
        // Python's str.lower(): full Unicode mapping, final sigma
        $this->assertSame('éàüς ας', StringUtils::lower('ÉÀÜΣ ΑΣ'));
    }

    public function test_string_utils_replace(): void
    {
        $replace_heic = StringUtils::replace('.HEIC', '.MOV');
        $this->assertSame('IMG_1234.MOV', $replace_heic('IMG_1234.HEIC'));
    }

    public function test_string_utils_replace_extension(): void
    {
        $replace_with_mov = StringUtils::replace_extension('.MOV');
        $this->assertSame('IMG_1234.MOV', $replace_with_mov('IMG_1234.HEIC'));
        $this->assertSame('no_extension', $replace_with_mov('no_extension'));
        // splitext: a leading dot is not an extension
        $this->assertSame('.hidden', $replace_with_mov('.hidden'));
        $this->assertSame('a.b/c', $replace_with_mov('a.b/c'));
    }

    public function test_string_utils_split(): void
    {
        $split_lines = StringUtils::split("\n");
        $this->assertSame(['line1', 'line2', 'line3'], $split_lines("line1\nline2\nline3"));
    }

    public function test_string_utils_startswith(): void
    {
        $starts_with_img = StringUtils::startswith('IMG_');
        $this->assertTrue($starts_with_img('IMG_1234.jpg'));
        $this->assertFalse($starts_with_img('DSC_5678.jpg'));
    }

    public function test_string_utils_strip(): void
    {
        $this->assertSame('hello', StringUtils::strip('  hello  '));
        // str.strip() strips Unicode whitespace
        $this->assertSame('x', StringUtils::strip("\u{a0}\u{2003}\t\n x \u{85}\x1c"));
    }

    public function test_string_utils_upper(): void
    {
        $this->assertSame('HELLO', StringUtils::upper('hello'));
        $this->assertSame('STRASSE', StringUtils::upper('straße'));
    }

    /** the docstring of the `strip_and_lower` attribute, which pytest does not collect */
    public function test_string_utils_strip_and_lower(): void
    {
        $this->assertSame('hello', StringUtils::strip_and_lower('  HELLO  '));
    }
}
