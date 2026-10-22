<?php

namespace Foundation\Argparse;

use Foundation\Argparse;
use Foundation\Py;

/**
 * Object for parsing command line strings into PHP objects.
 *
 * Keyword Arguments:
 *     - prog -- The name of the program (default:
 *         ``basename($_SERVER['argv'][0])``)
 *     - description -- A description of what the program does
 *     - add_help -- Add a -h/-help option
 *     - allow_abbrev -- Allow long options to be abbreviated unambiguously
 *     - exit_on_error -- Determines whether or not ArgumentParser exits with
 *         error info when an error occurs
 *
 * `copy.deepcopy(parser)` is `clone $parser`.
 */
class ArgumentParser extends _ActionsContainer
{
    public string $prog;
    public bool $add_help;
    public bool $allow_abbrev;
    public bool $exit_on_error;

    public _ArgumentGroup $_optionals;

    public function __construct(
        ?string $prog = null,
        ?string $description = null,
        bool $add_help = true,
        bool $allow_abbrev = true,
        bool $exit_on_error = true,
    ) {
        parent::__construct($description);

        // default setting for prog
        if ($prog === null) {
            // sys.argv[0]
            $prog = basename($_SERVER['argv'][0] ?? '');
        }

        $this->prog = $prog;
        $this->add_help = $add_help;
        $this->allow_abbrev = $allow_abbrev;
        $this->exit_on_error = $exit_on_error;

        // ponytail: no "positional arguments" group, nobody adds positionals
        $this->_optionals = $this->add_argument_group('options');

        // add help argument if necessary
        // (using explicit default to override global argument_default)
        $default_prefix = '-';
        if ($this->add_help) {
            $this->add_argument(
                $default_prefix . 'h',
                $default_prefix . $default_prefix . 'help',
                action: 'help',
                default: Argparse::SUPPRESS,
                help: 'show this help message and exit',
            );
        }
    }

    /**
     * `copy.deepcopy(parser)`: actions and groups are copied, the groups keep sharing the
     * action registries of their (new) parser as in Python. Types, being Closures, are shared.
     */
    public function __clone()
    {
        /** @var \SplObjectStorage<Action, Action> $actions_map */
        $actions_map = new \SplObjectStorage();
        $new_actions = [];
        foreach ($this->_actions as $action) {
            $actions_map[$action] = $new_actions[] = clone $action;
        }
        $new_option_string_actions = [];
        foreach ($this->_option_string_actions as $option_string => $action) {
            $new_option_string_actions[$option_string] = $actions_map[$action];
        }
        // break the references shared with the original parser and its groups
        unset($this->_actions, $this->_option_string_actions);
        $this->_actions = $new_actions;
        $this->_option_string_actions = $new_option_string_actions;

        $rewire = function (_ArgumentGroup $group) use ($actions_map): void {
            $group->_actions = &$this->_actions;
            $group->_option_string_actions = &$this->_option_string_actions;
            $group->_group_actions = array_map(fn($action) => $actions_map[$action], $group->_group_actions);
        };

        $groups_map = new \SplObjectStorage();
        $new_groups = [];
        foreach ($this->_action_groups as $group) {
            $groups_map[$group] = $new_groups[] = $copy = clone $group;
            $rewire($copy);
        }
        $this->_action_groups = $new_groups;
        $this->_optionals = $groups_map[$this->_optionals];

        $new_groups = [];
        foreach ($this->_mutually_exclusive_groups as $group) {
            $new_groups[] = $copy = clone $group;
            $copy->_container = $this;
            $rewire($copy);
        }
        $this->_mutually_exclusive_groups = $new_groups;
    }

    // ==================================
    // Optional/Positional adding methods
    // ==================================

    public function _add_action(Action $action): Action
    {
        $this->_optionals->_add_action($action);
        return $action;
    }

    // =====================================
    // Command line argument parsing methods
    // =====================================

    /**
     * @param list<string>|null $args
     */
    public function parse_args(?array $args = null, ?Namespace_ $namespace = null): Namespace_
    {
        [$args, $argv] = $this->parse_known_args($args, $namespace);
        if ($argv) {
            $msg = 'unrecognized arguments: ' . implode(' ', $argv);
            if ($this->exit_on_error) {
                $this->error($msg);
            } else {
                throw new ArgumentError(null, $msg);
            }
        }
        return $args;
    }

    /**
     * @param list<string>|null $args
     * @return array{Namespace_, list<string>}
     */
    public function parse_known_args(?array $args = null, ?Namespace_ $namespace = null): array
    {
        if ($args === null) {
            // args default to the system args
            $args = array_slice($_SERVER['argv'] ?? [], 1);
        } else {
            // make sure that args are mutable
            $args = array_values($args);
        }

        // default Namespace built from parser defaults
        if ($namespace === null) {
            $namespace = new Namespace_();
        }

        // add any action defaults that aren't present
        foreach ($this->_actions as $action) {
            if ($action->dest !== Argparse::SUPPRESS) {
                if (!property_exists($namespace, $action->dest)) {
                    if ($action->default !== Argparse::SUPPRESS) {
                        $namespace->{$action->dest} = $action->default;
                    }
                }
            }
        }

        // parse the arguments and exit if there are any errors
        if ($this->exit_on_error) {
            try {
                [$namespace, $args] = $this->_parse_known_args($args, $namespace);
            } catch (ArgumentError $err) {
                $this->error($err->getMessage());
            }
        } else {
            [$namespace, $args] = $this->_parse_known_args($args, $namespace);
        }

        return [$namespace, $args];
    }

    /**
     * @param list<string> $arg_strings
     * @return array{Namespace_, list<string>}
     */
    public function _parse_known_args(array $arg_strings, Namespace_ $namespace): array
    {
        // map all mutually exclusive arguments to the other arguments
        // they can't occur with
        $action_conflicts = [];
        foreach ($this->_mutually_exclusive_groups as $mutex_group) {
            $group_actions = $mutex_group->_group_actions;
            foreach ($mutex_group->_group_actions as $i => $mutex_action) {
                $id = spl_object_id($mutex_action);
                $action_conflicts[$id] ??= [];
                array_push($action_conflicts[$id], ...array_slice($group_actions, 0, $i));
                array_push($action_conflicts[$id], ...array_slice($group_actions, $i + 1));
            }
        }

        // find all option indices, and determine the arg_string_pattern
        // which has an 'O' if there is an option at an index,
        // an 'A' if there is an argument, or a '-' if there is a '--'
        $option_string_indices = [];
        $arg_string_pattern_parts = [];
        $count = count($arg_strings);
        for ($i = 0; $i < $count; $i++) {
            $arg_string = $arg_strings[$i];

            // all args after -- are non-options
            if ($arg_string === '--') {
                $arg_string_pattern_parts[] = '-';
                for ($i++; $i < $count; $i++) {
                    $arg_string_pattern_parts[] = 'A';
                }
            }

            // otherwise, add the arg to the arg strings
            // and note the index if it was an option
            else {
                $option_tuples = $this->_parse_optional($arg_string);
                if ($option_tuples === null) {
                    $pattern = 'A';
                } else {
                    $option_string_indices[$i] = $option_tuples;
                    $pattern = 'O';
                }
                $arg_string_pattern_parts[] = $pattern;
            }
        }

        // join the pieces together to form the pattern
        $arg_strings_pattern = implode('', $arg_string_pattern_parts);

        // converts arg strings to the appropriate and then takes the action
        $seen_actions = [];
        $seen_non_default_actions = [];
        $warned = [];

        $take_action = function (Action $action, array $argument_strings, ?string $option_string = null) use (
            &$seen_actions, &$seen_non_default_actions, &$action_conflicts, $namespace
        ): void {
            $seen_actions[spl_object_id($action)] = true;
            $argument_values = $this->_get_values($action, $argument_strings);

            // error if this argument is not allowed with other previously
            // seen arguments
            if ($action->option_strings || $argument_strings) {
                $seen_non_default_actions[spl_object_id($action)] = true;
                foreach ($action_conflicts[spl_object_id($action)] ?? [] as $conflict_action) {
                    if (isset($seen_non_default_actions[spl_object_id($conflict_action)])) {
                        $msg = 'not allowed with argument %s';
                        $action_name = Argparse::_get_action_name($conflict_action);
                        throw new ArgumentError($action, sprintf($msg, $action_name));
                    }
                }
            }

            // take the action (ponytail: no nargs=SUPPRESS, so no "is not SUPPRESS" check)
            $action($this, $namespace, $argument_values, $option_string);
        };

        // the list of extra arguments, filled by consume_optional()
        $extras = [];

        // function to convert arg_strings into an optional action
        $consume_optional = function (int $start_index) use (
            &$extras, &$warned, $option_string_indices, $arg_strings, $arg_strings_pattern, $take_action
        ): int {
            // get the optional identified at this index
            $option_tuples = $option_string_indices[$start_index];
            // if multiple actions match, the option string was ambiguous
            if (count($option_tuples) > 1) {
                $options = implode(', ', array_map(fn($t) => $t[1], $option_tuples));
                $msg = "ambiguous option: {$arg_strings[$start_index]} could match {$options}";
                throw new ArgumentError(null, $msg);
            }

            [$action, $option_string, $sep, $explicit_arg] = $option_tuples[0];

            // identify additional optionals in the same arg string
            // (e.g. -xyz is the same as -x -y -z if no args are required)
            $action_tuples = [];
            while (true) {
                // if we found no optional action, skip it
                if ($action === null) {
                    $extras[] = $arg_strings[$start_index];
                    return $start_index + 1;
                }

                // if there is an explicit argument, try to match the
                // optional's string arguments to only this
                if ($explicit_arg !== null) {
                    $arg_count = $this->_match_argument($action, 'A');

                    // if the action is a single-dash option and takes no
                    // arguments, try to parse more single-dash options out
                    // of the tail of the option string
                    $chars = $this->prefix_chars;
                    if (
                        $arg_count === 0
                        && !str_contains($chars, $option_string[1])
                        && $explicit_arg !== ''
                    ) {
                        if ($sep || str_contains($chars, $explicit_arg[0])) {
                            $msg = 'ignored explicit argument %s';
                            throw new ArgumentError($action, sprintf($msg, Py::repr($explicit_arg)));
                        }
                        $action_tuples[] = [$action, [], $option_string];
                        $char = $option_string[0];
                        // ponytail: bytes, not characters: every option string is ASCII
                        $option_string = $char . $explicit_arg[0];
                        $optionals_map = $this->_option_string_actions;
                        if (isset($optionals_map[$option_string])) {
                            $action = $optionals_map[$option_string];
                            $explicit_arg = substr($explicit_arg, 1);
                            if ($explicit_arg === '') {
                                $sep = $explicit_arg = null;
                            } elseif ($explicit_arg[0] === '=') {
                                $sep = '=';
                                $explicit_arg = substr($explicit_arg, 1);
                            } else {
                                $sep = '';
                            }
                        } else {
                            $extras[] = $char . $explicit_arg;
                            $stop = $start_index + 1;
                            break;
                        }
                    }
                    // if the action expect exactly one argument, we've
                    // successfully matched the option; exit the loop
                    elseif ($arg_count === 1) {
                        $stop = $start_index + 1;
                        $args = [$explicit_arg];
                        $action_tuples[] = [$action, $args, $option_string];
                        break;
                    }

                    // error if a double-dash option did not use the
                    // explicit argument
                    else {
                        $msg = 'ignored explicit argument %s';
                        throw new ArgumentError($action, sprintf($msg, Py::repr($explicit_arg)));
                    }
                }

                // if there is no explicit argument, try to match the
                // optional's string arguments with the following strings
                // if successful, exit the loop
                else {
                    $start = $start_index + 1;
                    $selected_patterns = substr($arg_strings_pattern, $start);
                    $arg_count = $this->_match_argument($action, $selected_patterns);
                    $stop = $start + $arg_count;
                    $args = array_slice($arg_strings, $start, $stop - $start);
                    $action_tuples[] = [$action, $args, $option_string];
                    break;
                }
            }

            // add the Optional to the list and return the index at which
            // the Optional's string args stopped
            assert((bool) $action_tuples);
            foreach ($action_tuples as [$action, $args, $option_string]) {
                if ($action->deprecated && !isset($warned[$option_string])) {
                    $this->_warning("option '{$option_string}' is deprecated");
                    $warned[$option_string] = true;
                }
                $take_action($action, $args, $option_string);
            }
            return $stop;
        };

        // consume Optionals until we have passed the last option string
        // ponytail: no positionals, so the arguments between two options are extras
        $start_index = 0;
        if ($option_string_indices) {
            $max_option_string_index = max(array_keys($option_string_indices));
        } else {
            $max_option_string_index = -1;
        }
        while ($start_index <= $max_option_string_index) {
            // find the next option
            $next_option_string_index = $start_index;
            while ($next_option_string_index <= $max_option_string_index) {
                if (isset($option_string_indices[$next_option_string_index])) {
                    break;
                }
                $next_option_string_index += 1;
            }

            // if we're not at the index of an option string, there were extra arguments
            if (!isset($option_string_indices[$start_index])) {
                $strings = array_slice($arg_strings, $start_index, $next_option_string_index - $start_index);
                array_push($extras, ...$strings);
                $start_index = $next_option_string_index;
            }

            // consume the next optional and any arguments for it
            $start_index = $consume_optional($start_index);
        }

        // if we didn't consume all the argument strings, there were extras
        array_push($extras, ...array_slice($arg_strings, $start_index));

        // make sure all required actions were present and also convert
        // action defaults which were not given as arguments
        $required_actions = [];
        foreach ($this->_actions as $action) {
            if (!isset($seen_actions[spl_object_id($action)])) {
                if ($action->required) {
                    $required_actions[] = Argparse::_get_action_name($action);
                } else {
                    // Convert action default now instead of doing it before
                    // parsing arguments to avoid calling convert functions
                    // twice (which may fail) if the argument was given, but
                    // only if it was defined already in the namespace
                    // ponytail: "is" (same object) is "===" (same value) for strings
                    if (
                        $action->default !== null
                        && is_string($action->default)
                        && property_exists($namespace, $action->dest)
                        && $action->default === $namespace->{$action->dest}
                    ) {
                        $namespace->{$action->dest} = $this->_get_value($action, $action->default);
                    }
                }
            }
        }

        if ($required_actions) {
            throw new ArgumentError(null, 'the following arguments are required: ' . implode(', ', $required_actions));
        }

        // make sure all required groups had one option present
        foreach ($this->_mutually_exclusive_groups as $group) {
            if ($group->required) {
                foreach ($group->_group_actions as $action) {
                    if (isset($seen_non_default_actions[spl_object_id($action)])) {
                        continue 2;
                    }
                }

                // if no actions were used, report the error
                $names = [];
                foreach ($group->_group_actions as $action) {
                    if ($action->help !== Argparse::SUPPRESS) {
                        $names[] = Argparse::_get_action_name($action);
                    }
                }
                throw new ArgumentError(null, 'one of the arguments ' . implode(' ', $names) . ' is required');
            }
        }

        // return the updated namespace and the extra arguments
        return [$namespace, $extras];
    }

    public function _match_argument(Action $action, string $arg_strings_pattern): int
    {
        // match the pattern for this action to the arg strings
        $nargs_pattern = $this->_get_nargs_pattern($action);
        $match = preg_match('/^' . $nargs_pattern . '/', $arg_strings_pattern, $m);

        // raise an exception if we weren't able to find a match
        if (!$match) {
            $msg = match ($action->nargs) {
                null => 'expected one argument',
                Argparse::OPTIONAL => 'expected at most one argument',
                Argparse::ONE_OR_MORE => 'expected at least one argument',
                1 => 'expected 1 argument',
                default => "expected {$action->nargs} arguments",
            };
            throw new ArgumentError($action, $msg);
        }

        // return the number of arguments matched
        return strlen($m[1]);
    }

    /**
     * @return list<array{?Action, string, ?string, ?string}>|null
     */
    public function _parse_optional(string $arg_string): ?array
    {
        // if it's an empty string, it was meant to be a positional
        if ($arg_string === '') {
            return null;
        }

        // if it doesn't start with a prefix, it was meant to be positional
        if (!str_contains($this->prefix_chars, $arg_string[0])) {
            return null;
        }

        // if the option string is present in the parser, return the action
        if (isset($this->_option_string_actions[$arg_string])) {
            $action = $this->_option_string_actions[$arg_string];
            return [[$action, $arg_string, null, null]];
        }

        // if it's just a single character, it was meant to be positional
        if (strlen($arg_string) == 1) {
            return null;
        }

        // if the option string before the "=" is present, return the action
        [$option_string, $sep, $explicit_arg] = self::_partition($arg_string, '=');
        if ($sep !== '' && isset($this->_option_string_actions[$option_string])) {
            $action = $this->_option_string_actions[$option_string];
            return [[$action, $option_string, $sep, $explicit_arg]];
        }

        // search through all possible prefixes of the option string
        // and all actions in the parser for possible interpretations
        $option_tuples = $this->_get_option_tuples($arg_string);

        if ($option_tuples) {
            return $option_tuples;
        }

        // if it was not found as an option, but it looks like a negative
        // number, it was meant to be positional
        // (ponytail: no option string of the ported parsers looks like a negative number)
        if (preg_match(self::_negative_number_matcher, $arg_string)) {
            return null;
        }

        // if it contains a space, it was meant to be a positional
        if (str_contains($arg_string, ' ')) {
            return null;
        }

        // it was meant to be an optional but there is no such option
        // in this parser (though it might be a valid option in a subparser)
        return [[null, $arg_string, null, null]];
    }

    /**
     * `str.partition`
     *
     * @return array{string, string, string}
     */
    private static function _partition(string $string, string $separator): array
    {
        $pos = strpos($string, $separator);
        if ($pos === false) {
            return [$string, '', ''];
        }
        return [substr($string, 0, $pos), $separator, substr($string, $pos + strlen($separator))];
    }

    /**
     * @return list<array{?Action, string, ?string, ?string}>
     */
    public function _get_option_tuples(string $option_string): array
    {
        $result = [];

        // option strings starting with two prefix characters are only
        // split at the '='
        $chars = $this->prefix_chars;
        if (str_contains($chars, $option_string[0]) && str_contains($chars, $option_string[1])) {
            if ($this->allow_abbrev) {
                [$option_prefix, $sep, $explicit_arg] = self::_partition($option_string, '=');
                if ($sep === '') {
                    $sep = $explicit_arg = null;
                }
                foreach ($this->_option_string_actions as $option_string => $action) {
                    if (str_starts_with($option_string, $option_prefix)) {
                        $result[] = [$action, $option_string, $sep, $explicit_arg];
                    }
                }
            }
        }

        // single character options can be concatenated with their arguments
        // but multiple character options always have to have their argument
        // separate
        elseif (str_contains($chars, $option_string[0]) && !str_contains($chars, $option_string[1])) {
            [$option_prefix, $sep, $explicit_arg] = self::_partition($option_string, '=');
            if ($sep === '') {
                $sep = $explicit_arg = null;
            }
            $short_option_prefix = substr($option_string, 0, 2);
            $short_explicit_arg = substr($option_string, 2);

            foreach ($this->_option_string_actions as $option_string => $action) {
                if ($option_string === $short_option_prefix) {
                    $result[] = [$action, $option_string, '', $short_explicit_arg];
                } elseif ($this->allow_abbrev && str_starts_with($option_string, $option_prefix)) {
                    $result[] = [$action, $option_string, $sep, $explicit_arg];
                }
            }
        }

        // shouldn't ever get here
        else {
            throw new ArgumentError(null, "unexpected option string: {$option_string}");
        }

        // return the collected option tuples
        return $result;
    }

    public function _get_nargs_pattern(Action $action): string
    {
        // in all examples below, we have to allow for '--' args
        // which are represented as '-' in the pattern
        $nargs = $action->nargs;

        // the default (null) is assumed to be a single argument
        // (ponytail: optionals only, where '--' is not allowed)
        if ($nargs === null) {
            $nargs_pattern = '([A])';
        }

        // allow zero or one arguments
        elseif ($nargs === Argparse::OPTIONAL) {
            $nargs_pattern = '(A?)';
        }

        // allow zero or more arguments
        elseif ($nargs === Argparse::ZERO_OR_MORE) {
            $nargs_pattern = '(A*)';
        }

        // allow one or more arguments
        elseif ($nargs === Argparse::ONE_OR_MORE) {
            $nargs_pattern = '(A+)';
        }

        // all others should be integers
        else {
            $nargs_pattern = "([AO]{{$nargs}})";
        }

        // return the pattern
        return $nargs_pattern;
    }

    // ========================
    // Value conversion methods
    // ========================

    /**
     * @param list<string> $arg_strings
     */
    public function _get_values(Action $action, array $arg_strings): mixed
    {
        // optional argument produces a default when not present
        if (!$arg_strings && $action->nargs === Argparse::OPTIONAL) {
            $value = $action->const;
            if (is_string($value) && $value !== Argparse::SUPPRESS) {
                $value = $this->_get_value($action, $value);
                $this->_check_value($action, $value);
            }
        }

        // single argument or optional argument produces a single value
        elseif (count($arg_strings) == 1 && ($action->nargs === null || $action->nargs === Argparse::OPTIONAL)) {
            [$arg_string] = $arg_strings;
            $value = $this->_get_value($action, $arg_string);
            $this->_check_value($action, $value);
        }

        // all other types of nargs produce a list
        else {
            $value = array_map(fn($v) => $this->_get_value($action, $v), $arg_strings);
            foreach ($value as $v) {
                $this->_check_value($action, $v);
            }
        }

        // return the converted value
        return $value;
    }

    public function _get_value(Action $action, string $arg_string): mixed
    {
        $type_func = $action->type;

        // convert the value to the appropriate type
        try {
            if ($type_func === null || $type_func === 'str') {
                $result = $arg_string;
            } elseif ($type_func === 'int') {
                $result = self::_int($arg_string);
            } else {
                $result = $type_func($arg_string);
            }
        }

        // ArgumentTypeErrors indicate errors
        catch (ArgumentTypeError $err) {
            $msg = $err->getMessage();
            throw new ArgumentError($action, $msg);
        }

        // TypeErrors or ValueErrors also indicate errors
        catch (\TypeError|\ValueError) {
            $name = $type_func instanceof \Closure ? (new \ReflectionFunction($type_func))->getName() : $type_func;
            $msg = "invalid {$name} value: " . Py::repr($arg_string);
            throw new ArgumentError($action, $msg);
        }

        // return the converted value
        return $result;
    }

    /**
     * `int(string)` of Python.
     * ponytail: 64-bit integers
     */
    private static function _int(string $string): int
    {
        if (!preg_match('/^\s*[+-]?[0-9]+(?:_[0-9]+)*\s*$/D', $string)) {
            throw new \ValueError('invalid literal for int() with base 10: ' . Py::repr($string));
        }
        return (int) str_replace('_', '', trim($string));
    }

    public function _check_value(Action $action, mixed $value): void
    {
        // converted value must be one of the choices (if specified)
        $choices = $action->choices;
        if ($choices !== null) {
            if (!in_array($value, $choices, true)) {
                $msg = 'invalid choice: ' . Py::repr(Py::str($value))
                    . ' (choose from ' . implode(', ', array_map(Py::str(...), $choices)) . ')';
                throw new ArgumentError($action, $msg);
            }
        }
    }

    // =======================
    // Help-formatting methods
    // =======================

    public function format_usage(): string
    {
        $formatter = $this->_get_formatter();
        $formatter->add_usage($this->_actions, $this->_mutually_exclusive_groups);
        return $formatter->format_help();
    }

    public function format_help(): string
    {
        $formatter = $this->_get_formatter();

        // usage
        $formatter->add_usage($this->_actions, $this->_mutually_exclusive_groups);

        // description
        $formatter->add_text($this->description);

        // positionals, optionals and user-defined groups
        foreach ($this->_action_groups as $action_group) {
            $formatter->start_section($action_group->title);
            $formatter->add_text($action_group->description);
            $formatter->add_arguments($action_group->_group_actions);
            $formatter->end_section();
        }

        // determine help from format above
        return $formatter->format_help();
    }

    public function _get_formatter(): HelpFormatter
    {
        return new HelpFormatter($this->prog);
    }

    // =====================
    // Help-printing methods
    // =====================

    /** @param resource|null $file */
    public function print_usage($file = null): void
    {
        if ($file === null) {
            $file = Py::stdout();
        }
        $this->_print_message($this->format_usage(), $file);
    }

    /** @param resource|null $file */
    public function print_help($file = null): void
    {
        if ($file === null) {
            $file = Py::stdout();
        }
        $this->_print_message($this->format_help(), $file);
    }

    /** @param resource|null $file */
    public function _print_message(string $message, $file = null): void
    {
        if ($message !== '') {
            // ponytail: sys.stdout is Py::stdout() (php://output, captured by ob_start() like
            // redirect_stdout() in the upstream tests); STDERR cannot be captured in process
            $file = $file ?: STDERR;
            @fwrite($file, $message);
        }
    }

    // ===============
    // Exiting methods
    // ===============

    public function exit(int $status = 0, ?string $message = null): never
    {
        if ($message !== null && $message !== '') {
            $this->_print_message($message, STDERR);
        }
        exit($status);
    }

    /**
     * error(message: string)
     *
     * Prints a usage message incorporating the message to stderr and
     * exits.
     */
    public function error(string $message): never
    {
        $this->print_usage(STDERR);
        $this->exit(2, "{$this->prog}: error: {$message}\n");
    }

    public function _warning(string $message): void
    {
        $this->_print_message("{$this->prog}: warning: {$message}\n", STDERR);
    }
}
