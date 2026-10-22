<?php

namespace Foundation\Argparse;

use Foundation\Argparse;
use Foundation\Argparse\HelpFormatter\_Section;
use Foundation\Py;

/**
 * Formatter for generating usage messages and argument help strings.
 *
 * Only the name of this class is considered a public API. All the methods
 * provided by the class are considered an implementation detail.
 *
 * ponytail: no usage= override, the terminal width is read from $COLUMNS only (80 otherwise):
 * PHP has no ioctl(TIOCGWINSZ).
 */
class HelpFormatter
{
    public int $_indent_increment;
    public int $_max_help_position;
    public int $_width;

    public int $_current_indent = 0;
    public int $_level = 0;
    public int $_action_max_length = 0;

    public _Section $_root_section;
    public _Section $_current_section;

    public function __construct(
        public string $_prog,
        int $indent_increment = 2,
        int $max_help_position = 24,
        ?int $width = null,
    ) {
        // default setting for width
        if ($width === null) {
            // shutil.get_terminal_size().columns
            $columns = getenv('COLUMNS');
            $width = $columns !== false && ctype_digit($columns) && (int) $columns > 0 ? (int) $columns : 80;
            $width -= 2;
        }

        $this->_indent_increment = $indent_increment;
        $this->_max_help_position = min($max_help_position, max($width - 20, $indent_increment * 2));
        $this->_width = $width;

        $this->_root_section = new _Section($this, null);
        $this->_current_section = $this->_root_section;
    }

    // ===============================
    // Section and indentation methods
    // ===============================

    public function _indent(): void
    {
        $this->_current_indent += $this->_indent_increment;
        $this->_level += 1;
    }

    public function _dedent(): void
    {
        $this->_current_indent -= $this->_indent_increment;
        assert($this->_current_indent >= 0, 'Indent decreased below 0.');
        $this->_level -= 1;
    }

    public function _add_item(callable $func, array $args): void
    {
        $this->_current_section->items[] = [$func, $args];
    }

    // ========================
    // Message building methods
    // ========================

    public function start_section(?string $heading): void
    {
        $this->_indent();
        $section = new _Section($this, $this->_current_section, $heading);
        $this->_add_item([$section, 'format_help'], []);
        $this->_current_section = $section;
    }

    public function end_section(): void
    {
        $this->_current_section = $this->_current_section->parent;
        $this->_dedent();
    }

    public function add_text(?string $text): void
    {
        if ($text !== Argparse::SUPPRESS && $text !== null) {
            $this->_add_item([$this, '_format_text'], [$text]);
        }
    }

    /** @param list<Action> $actions @param list<_MutuallyExclusiveGroup> $groups */
    public function add_usage(array $actions, array $groups, ?string $prefix = null): void
    {
        $this->_add_item([$this, '_format_usage'], [$actions, $groups, $prefix]);
    }

    public function add_argument(Action $action): void
    {
        if ($action->help !== Argparse::SUPPRESS) {
            // find all invocations
            $invocation_lengths = [mb_strlen($this->_format_action_invocation($action)) + $this->_current_indent];

            // update the maximum item length
            $action_length = max($invocation_lengths);
            $this->_action_max_length = max($this->_action_max_length, $action_length);

            // add the item to the list
            $this->_add_item([$this, '_format_action'], [$action]);
        }
    }

    /** @param list<Action> $actions */
    public function add_arguments(array $actions): void
    {
        foreach ($actions as $action) {
            $this->add_argument($action);
        }
    }

    // =======================
    // Help-formatting methods
    // =======================

    public function format_help(): string
    {
        $help = $this->_root_section->format_help();
        if ($help !== '') {
            $help = preg_replace('/\n\n\n+/', "\n\n", $help);
            $help = trim($help, "\n") . "\n";
        }
        return $help;
    }

    /** @param list<?string> $part_strings */
    public function _join_parts(array $part_strings): string
    {
        return implode('', array_filter($part_strings, fn($part) => $part !== null && $part !== '' && $part !== Argparse::SUPPRESS));
    }

    /**
     * @param list<Action> $actions
     * @param list<_MutuallyExclusiveGroup> $groups
     */
    public function _format_usage(array $actions, array $groups, ?string $prefix): string
    {
        if ($prefix === null) {
            $prefix = 'usage: ';
        }

        // if no optionals or positionals are available, usage is just prog
        if (!$actions) {
            $usage = $this->_prog;
        }

        // if optionals and positionals are available, calculate usage
        else {
            $prog = $this->_prog;

            // split optionals from positionals
            $optionals = [];
            $positionals = [];
            foreach ($actions as $action) {
                if ($action->option_strings) {
                    $optionals[] = $action;
                } else {
                    $positionals[] = $action;
                }
            }

            // build full usage string
            $action_usage = $this->_format_actions_usage(array_merge($optionals, $positionals), $groups);
            $usage = implode(' ', array_filter([$prog, $action_usage], fn($s) => $s !== ''));

            // wrap the usage parts if it's too long
            $text_width = $this->_width - $this->_current_indent;
            if (mb_strlen($prefix) + mb_strlen($usage) > $text_width) {
                // break usage into wrappable parts
                // keep optionals and positionals together to preserve
                // mutually exclusive group formatting (gh-75949)
                $all_actions = array_merge($optionals, $positionals);
                [$parts, $pos_start] = $this->_get_actions_usage_parts_with_split(
                    $all_actions, $groups, count($optionals)
                );
                $opt_parts = array_slice($parts, 0, $pos_start);
                $pos_parts = array_slice($parts, $pos_start);

                // helper for wrapping lines
                $get_lines = function (array $parts, string $indent, ?string $prefix = null) use ($text_width): array {
                    $lines = [];
                    $line = [];
                    $indent_length = strlen($indent);
                    if ($prefix !== null) {
                        $line_len = mb_strlen($prefix) - 1;
                    } else {
                        $line_len = $indent_length - 1;
                    }
                    foreach ($parts as $part) {
                        if ($line_len + 1 + mb_strlen($part) > $text_width && $line) {
                            $lines[] = $indent . implode(' ', $line);
                            $line = [];
                            $line_len = $indent_length - 1;
                        }
                        $line[] = $part;
                        $line_len += mb_strlen($part) + 1;
                    }
                    if ($line) {
                        $lines[] = $indent . implode(' ', $line);
                    }
                    if ($prefix !== null) {
                        $lines[0] = substr($lines[0], $indent_length);
                    }
                    return $lines;
                };

                // if prog is short, follow it with optionals or positionals
                if (mb_strlen($prefix) + mb_strlen($prog) <= 0.75 * $text_width) {
                    $indent = str_repeat(' ', mb_strlen($prefix) + mb_strlen($prog) + 1);
                    if ($opt_parts) {
                        $lines = $get_lines(array_merge([$prog], $opt_parts), $indent, $prefix);
                        array_push($lines, ...$get_lines($pos_parts, $indent));
                    } elseif ($pos_parts) {
                        $lines = $get_lines(array_merge([$prog], $pos_parts), $indent, $prefix);
                    } else {
                        $lines = [$prog];
                    }
                }

                // if prog is long, put it on its own line
                else {
                    $indent = str_repeat(' ', mb_strlen($prefix));
                    $parts = array_merge($opt_parts, $pos_parts);
                    $lines = $get_lines($parts, $indent);
                    if (count($lines) > 1) {
                        $lines = [];
                        array_push($lines, ...$get_lines($opt_parts, $indent));
                        array_push($lines, ...$get_lines($pos_parts, $indent));
                    }
                    $lines = array_merge([$prog], $lines);
                }

                // join lines into usage
                $usage = implode("\n", $lines);
            }
        }

        // prefix with 'usage:'
        return "{$prefix}{$usage}\n\n";
    }

    /**
     * @param list<Action> $actions
     * @param list<_MutuallyExclusiveGroup> $groups
     */
    public function _format_actions_usage(array $actions, array $groups): string
    {
        return implode(' ', $this->_get_actions_usage_parts($actions, $groups));
    }

    /**
     * @param list<Action> $actions
     * @param list<_MutuallyExclusiveGroup> $groups
     * @return list<string>
     */
    public function _get_actions_usage_parts(array $actions, array $groups): array
    {
        [$parts, $_] = $this->_get_actions_usage_parts_with_split($actions, $groups);
        return $parts;
    }

    /**
     * Get usage parts with split index for optionals/positionals.
     *
     * Returns (parts, pos_start) where pos_start is the index in parts
     * where positionals begin. When opt_count is null, pos_start is null.
     * This preserves mutually exclusive group formatting across the
     * optionals/positionals boundary (gh-75949).
     *
     * @param list<Action> $actions
     * @param list<_MutuallyExclusiveGroup> $groups
     * @return array{list<string>, ?int}
     */
    public function _get_actions_usage_parts_with_split(array $actions, array $groups, ?int $opt_count = null): array
    {
        // find group indices and identify actions in groups
        $group_actions = [];
        $inserts = [];
        foreach ($groups as $group) {
            if (!$group->_group_actions) {
                throw new \ValueError('empty group');
            }

            if (!array_filter($group->_group_actions, fn($action) => $action->help !== Argparse::SUPPRESS)) {
                continue;
            }

            $start = array_search($group->_group_actions[0], $actions, true);
            if ($start === false) {
                continue;
            } else {
                $end = $start + count($group->_group_actions);
                if (array_slice($actions, $start, $end - $start) === $group->_group_actions) {
                    foreach ($group->_group_actions as $a) {
                        $group_actions[spl_object_id($a)] = true;
                    }
                    $inserts[] = [$start, $end, $group];
                }
            }
        }

        // collect all actions format strings
        $parts = [];
        foreach ($actions as $action) {
            // suppressed arguments are marked with null
            if ($action->help === Argparse::SUPPRESS) {
                $part = null;
            }

            // produce all arg strings (ponytail: optionals only)
            else {
                // produce the first way to invoke the option in brackets
                $option_string = $action->option_strings[0];

                // if the Optional doesn't take a value, format is:
                //    -s or --long
                if ($action->nargs === 0) {
                    $part = $action->format_usage();
                }

                // if the Optional takes a value, format is:
                //    -s ARGS or --long ARGS
                else {
                    $default = $this->_get_default_metavar_for_optional($action);
                    $args_string = $this->_format_args($action, $default);
                    $part = "{$option_string} {$args_string}";
                }

                // make it look optional if it's not required or in a group
                if (!$action->required && !isset($group_actions[spl_object_id($action)])) {
                    $part = "[{$part}]";
                }
            }

            // add the action string to the list
            $parts[] = $part;
        }

        // group mutually exclusive actions
        $inserted_separators_indices = [];
        // sorted(inserts, reverse=True)
        usort($inserts, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        foreach ($inserts as [$start, $end, $group]) {
            $group_parts = array_values(array_filter(array_slice($parts, $start, $end - $start), fn($item) => $item !== null));
            $group_size = count($group_parts);
            if ($group->required) {
                [$open, $close] = $group_size > 1 ? ['(', ')'] : ['', ''];
            } else {
                [$open, $close] = ['[', ']'];
            }
            $group_parts[0] = $open . $group_parts[0];
            $group_parts[$group_size - 1] = $group_parts[$group_size - 1] . $close;
            foreach (array_slice($group_parts, 0, -1) as $offset => $part) {
                $i = $start + $offset;
                // insert a separator if not already done in a nested group
                if (!isset($inserted_separators_indices[$i])) {
                    $parts[$i] = $part . ' |';
                    $inserted_separators_indices[$i] = true;
                }
            }
            $parts[$start + $group_size - 1] = $group_parts[$group_size - 1];
            for ($i = $start + $group_size; $i < $end; $i++) {
                $parts[$i] = null;
            }
        }

        // if opt_count is provided, calculate where positionals start in
        // the final parts list (for wrapping onto separate lines).
        // Count before filtering null entries since indices shift after.
        if ($opt_count !== null) {
            $pos_start = count(array_filter(array_slice($parts, 0, $opt_count), fn($p) => $p !== null));
        } else {
            $pos_start = null;
        }

        // return the usage parts and split point (gh-75949)
        return [array_values(array_filter($parts, fn($item) => $item !== null)), $pos_start];
    }

    public function _format_text(string $text): string
    {
        if (str_contains($text, '%(prog)')) {
            $text = Py::mod_format($text, ['prog' => $this->_prog]);
        }
        $text_width = max($this->_width - $this->_current_indent, 11);
        $indent = str_repeat(' ', $this->_current_indent);
        return $this->_fill_text($text, $text_width, $indent) . "\n\n";
    }

    public function _format_action(Action $action): string
    {
        // determine the required width and the entry label
        $help_position = min($this->_action_max_length + 2, $this->_max_help_position);
        $help_width = max($this->_width - $help_position, 11);
        $action_width = $help_position - $this->_current_indent - 2;
        $action_header = $this->_format_action_invocation($action);
        $indent_first = 0;

        // no help; start on same line and add a final newline
        if ($action->help === null || $action->help === '') {
            $action_header = str_repeat(' ', $this->_current_indent) . $action_header . "\n";
        }

        // short action name; start on the same line and pad two spaces
        elseif (mb_strlen($action_header) <= $action_width) {
            $action_header = str_repeat(' ', $this->_current_indent)
                . $action_header . str_repeat(' ', $action_width - mb_strlen($action_header)) . '  ';
            $indent_first = 0;
        }

        // long action name; start on the next line
        else {
            $action_header = str_repeat(' ', $this->_current_indent) . $action_header . "\n";
            $indent_first = $help_position;
        }

        // collect the pieces of the action help
        $parts = [$action_header];

        // if there was help for the action, add lines of help text
        if ($action->help !== null && trim($action->help, " \t\n\r\x0b\x0c") !== '') {
            $help_text = $this->_expand_help($action);
            if ($help_text !== '') {
                $help_lines = $this->_split_lines($help_text, $help_width);
                $parts[] = str_repeat(' ', $indent_first) . $help_lines[0] . "\n";
                foreach (array_slice($help_lines, 1) as $line) {
                    $parts[] = str_repeat(' ', $help_position) . $line . "\n";
                }
            }
        }

        // or add a newline if the description doesn't end with one
        elseif (!str_ends_with($action_header, "\n")) {
            $parts[] = "\n";
        }

        // return a single string
        return $this->_join_parts($parts);
    }

    public function _format_action_invocation(Action $action): string
    {
        // if the Optional doesn't take a value, format is:
        //    -s, --long
        if ($action->nargs === 0) {
            return implode(', ', $action->option_strings);
        }

        // if the Optional takes a value, format is:
        //    -s, --long ARGS
        else {
            $default = $this->_get_default_metavar_for_optional($action);
            $args_string = $this->_format_args($action, $default);
            return implode(', ', $action->option_strings) . ' ' . $args_string;
        }
    }

    /** @return \Closure(int): list<string> */
    public function _metavar_formatter(Action $action, string $default_metavar): \Closure
    {
        if ($action->metavar !== null) {
            $result = $action->metavar;
        } elseif ($action->choices !== null) {
            $result = '{' . implode(',', array_map('strval', $action->choices)) . '}';
        } else {
            $result = $default_metavar;
        }

        return fn(int $tuple_size): array => array_fill(0, $tuple_size, $result);
    }

    public function _format_args(Action $action, string $default_metavar): string
    {
        $get_metavar = $this->_metavar_formatter($action, $default_metavar);
        if ($action->nargs === null) {
            $result = $get_metavar(1)[0];
        } elseif ($action->nargs === Argparse::OPTIONAL) {
            $result = '[' . $get_metavar(1)[0] . ']';
        } elseif ($action->nargs === Argparse::ZERO_OR_MORE) {
            $result = '[' . $get_metavar(1)[0] . ' ...]';
        } elseif ($action->nargs === Argparse::ONE_OR_MORE) {
            [$m1, $m2] = $get_metavar(2);
            $result = "{$m1} [{$m2} ...]";
        } else {
            $result = implode(' ', $get_metavar($action->nargs));
        }
        return $result;
    }

    public function _expand_help(Action $action): string
    {
        $params = get_object_vars($action) + ['prog' => $this->_prog];
        foreach ($params as $name => $value) {
            if ($value === Argparse::SUPPRESS) {
                unset($params[$name]);
            }
        }
        // type functions are replaced by their __name__
        if ($params['type'] instanceof \Closure) {
            $params['type'] = (new \ReflectionFunction($params['type']))->getName();
        }
        if (($params['choices'] ?? null) !== null) {
            $params['choices'] = implode(', ', array_map('strval', $params['choices']));
        }
        return Py::mod_format($this->_get_help_string($action), $params);
    }

    /** @return list<string> */
    public function _split_lines(string $text, int $width): array
    {
        $text = trim(preg_replace('/[ \t\n\r\x0b\x0c]+/', ' ', $text), " \t\n\r\x0b\x0c");
        // The textwrap module is used only for formatting help.
        return Textwrap::wrap($text, $width);
    }

    public function _fill_text(string $text, int $width, string $indent): string
    {
        $text = trim(preg_replace('/[ \t\n\r\x0b\x0c]+/', ' ', $text), " \t\n\r\x0b\x0c");
        return Textwrap::fill($text, $width, $indent, $indent);
    }

    public function _get_help_string(Action $action): string
    {
        return $action->help;
    }

    public function _get_default_metavar_for_optional(Action $action): string
    {
        return strtoupper($action->dest);
    }
}
