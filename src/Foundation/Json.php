<?php

namespace Foundation;

use Closure;
use Generator;
use SplFixedArray;

/**
 * Port of `foundation/json.py`: rules applied to a JSON document (a decoded `dict`, `list`, `str`…),
 * keyed by the dotted path of each value.
 *
 * Data model (see `Py`): a tuple is a `\SplFixedArray` (what `dict.items()` yields), a list a PHP list, a
 * dict an associative array or a `\stdClass`. A `Pattern` is a PCRE regex (delimiters and modifiers included)
 * as `re_compile_flag` builds it; a `Rule` is `[Pattern, callable(string): ?string]`.
 *
 * ponytail: an empty array is a list, so an empty dict comes out as `[]`, and a dict whose keys are exactly
 * 0..n-1 is taken for a list.
 */
final class Json
{
    // def extract_context(context: Context[T1], pair: Tuple[T1, T2]) -> Tuple[Context[T1], T2]:
    //     return (context + [pair[0]], pair[1])

    /** Context = str */
    public static function extract_context(string $context, array|\ArrayAccess $pair): array
    {
        $should_add_dot = $context && !StringUtils::endswith('.')($context);
        $new_context = $context.($should_add_dot ? '.' : '').$pair[0];
        return [$new_context, $pair[1]];
    }

    public static function first(iterable $input): mixed
    {
        foreach ($input as $item) {
            return $item;
        }
        return new StopIteration();
    }

    public static function first_or_default(iterable $input, mixed $default): mixed
    {
        foreach ($input as $item) {
            return $item;
        }
        return $default;
    }

    public static function first_or_none(iterable $input): mixed
    {
        return Core::partial_1_1(Core::flip(self::first_or_default(...)), null)($input);
    }

    public static function is_none(mixed $input): bool
    {
        return $input === null;
    }

    public static function is_not_none(mixed $input): bool
    {
        return Core::compose(Py::not_(...), self::is_none(...))($input);
    }

    /** `re.search(pattern, string)`: the matches, or null */
    private static function re_search(string $pattern, string $string): ?array
    {
        return preg_match($pattern, $string, $m) === 1 ? $m : null;
    }

    public static function first_matching_rule(string $context, array $rules): ?array
    {
        $match_with_context = Core::partial_1_1(Core::flip(self::re_search(...)), $context);
        $match_on_key_of_pair = Core::compose($match_with_context, Core::fst(...));
        $is_matching_pair = Core::compose(self::is_not_none(...), $match_on_key_of_pair);
        $filter_pairs = Core::partial_1_1(Core::filter_(...), $is_matching_pair);
        $first_matching = Core::compose(self::first_or_none(...), $filter_pairs);
        $first_found = $first_matching($rules);
        return $first_found;
    }

    /** `functools.singledispatch` on the type of `$input` (str, tuple, list, dict, anything else unchanged) */
    private static function _apply_rules_internal(mixed $input, string $context, array $rules): mixed
    {
        return match (true) {
            is_string($input) => self::_apply_rules_str($input, $context, $rules),
            $input instanceof SplFixedArray => self::_apply_rules_tuple($input, $context, $rules),
            $input instanceof \stdClass => self::_apply_rules_dict((array) $input, $context, $rules),
            is_array($input) && array_is_list($input) => self::_apply_rules_list($input, $context, $rules),
            is_array($input) => self::_apply_rules_dict($input, $context, $rules),
            default => $input, // print(f"ANY {context} {input}")
        };
    }

    private static function _apply_rules_str(string $input, string $context, array $rules): ?string
    {
        // print(f"STR {context} {input}")
        $first_found_rule = self::first_matching_rule($context, $rules);
        if ($first_found_rule === null) {
            // no pattern matched - return unchanged
            return $input;
        }
        return $first_found_rule[1]($input);
    }

    private static function _apply_rules_tuple(SplFixedArray $input, string $context, array $rules): SplFixedArray
    {
        // print(f"TUPLE {context} {input}")
        $first_found_rule = self::first_matching_rule($context, $rules);
        if ($first_found_rule === null) {
            // no pattern matched - continue recursive
            [$new_context, $new_value] = self::extract_context($context, $input);
            return SplFixedArray::fromArray([$input[0], self::_apply_rules_internal($new_value, $new_context, $rules)]);
        }
        // this is to allow overriding the whole object with string
        return SplFixedArray::fromArray([$input[0], $first_found_rule[1]('tuple')]);
    }

    public static function filter_not_none(iterable $input): Generator
    {
        return Core::filter_(self::is_not_none(...), $input);
    }

    public static function apply_rules(string $context, array $rules, mixed $input): mixed
    {
        return self::_apply_rules_internal($input, $context, $rules);
    }

    private static function _apply_rules_list(array $input, string $context, array $rules): mixed
    {
        // print(f"LIST {context} {input}")
        $first_found_rule = self::first_matching_rule($context, $rules);
        if ($first_found_rule === null) {
            // no pattern matched - continue recursive
            $apply_context_rules = Core::partial_2_1(self::apply_rules(...), $context.'.', $rules);
            $apply_rule_iter = Core::partial_1_1(Core::map_(...), $apply_context_rules);
            $apply_and_filter = Core::compose(self::filter_not_none(...), $apply_rule_iter);
            $materialized_apply_and_filter = Core::compose(self::list(...), $apply_and_filter);
            return $materialized_apply_and_filter($input);
        }
        // this is to allow overriding the whole list with string
        return $first_found_rule[1]('list');
    }

    private static function _apply_rules_dict(array $input, string $context, array $rules): mixed
    {
        // print(f"DICT {context} {input}")
        $first_found_rule = self::first_matching_rule($context, $rules);
        if ($first_found_rule === null) {
            // no pattern matched - continue recursive
            $apply_context_rules = Core::partial_2_1(self::apply_rules(...), $context, $rules);
            $apply_rule_iter = Core::partial_1_1(Core::map_(...), $apply_context_rules);
            $apply_and_filter = Core::compose(Foundation::non_empty_pairs(...), $apply_rule_iter);
            $materialized_apply_and_filter = Core::compose(self::dict(...), $apply_and_filter);
            return $materialized_apply_and_filter(self::items($input));
        }
        // this is to allow overriding the whole list with string
        return $first_found_rule[1]('dict');
    }

    /** `list(iterable)` */
    private static function list(iterable $input): array
    {
        return iterator_to_array($input, false);
    }

    /** `dict(iterable of pairs)` */
    private static function dict(iterable $pairs): array
    {
        $r = [];
        foreach ($pairs as $pair) {
            $r[$pair[0]] = $pair[1];
        }
        return $r;
    }

    /** `dict.items()`: the (key, value) tuples */
    private static function items(array $input): Generator
    {
        foreach ($input as $k => $v) {
            yield SplFixedArray::fromArray([$k, $v]);
        }
    }

    /**
     * `re.compile(input, flags)`: `$flags` are PCRE modifiers (`i`); the pattern is Unicode (`u`) as Python
     * 3 str patterns are
     */
    public static function re_compile_flag(string $flags, string $input): string
    {
        // "\x01" cannot appear in the pattern, so no escaping of the delimiter is needed
        return "\x01".$input."\x01".$flags.'u';
    }

    /** `re.compile(input, re.IGNORECASE)` */
    public static function re_compile_ignorecase(string $input): string
    {
        return Core::partial_1_1(self::re_compile_flag(...), 'i')($input);
    }

    /** `map(re_compile_ignorecase, patterns)` */
    public static function compile_patterns(iterable $patterns): Generator
    {
        return Core::map_(self::re_compile_ignorecase(...), $patterns);
    }
}
