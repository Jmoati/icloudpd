<?php

namespace Foundation\Argparse;

use Foundation\Argparse;
use Foundation\Py;

class _ActionsContainer
{
    public ?string $description;
    public string $prefix_chars = '-';

    /** @var list<Action> */
    public array $_actions = [];
    /** @var array<string, Action> */
    public array $_option_string_actions = [];

    /** @var list<_ArgumentGroup> */
    public array $_action_groups = [];
    /** @var list<_MutuallyExclusiveGroup> */
    public array $_mutually_exclusive_groups = [];

    // determines whether an "option" looks like a negative number
    public const _negative_number_matcher = '/^-\d+$|^-\d*\.\d+$/';

    // ponytail: no _has_negative_number_optionals (none of the ported parsers has an option string
    // that looks like a negative number, so "-1" is always a value) and no _defaults/argument_default

    public function __construct(?string $description)
    {
        $this->description = $description;
    }

    // =======================
    // Adding argument actions
    // =======================

    /**
     * add_argument(option_string, option_string, ..., name=value, ...)
     *
     * Python's `*args, **kwargs`: positional arguments are the option strings, named arguments the keywords.
     * ponytail: optional arguments only (no positionals).
     */
    public function add_argument(mixed ...$args): Action
    {
        $names = [];
        $kwargs = [];
        foreach ($args as $key => $value) {
            if (is_int($key)) {
                $names[] = $value;
            } else {
                $kwargs[$key] = $value;
            }
        }

        // if no positional args are supplied or only one is supplied and
        // it doesn't look like an option string, it would be a positional argument
        $chars = $this->prefix_chars;
        if (!$names || count($names) == 1 && !str_contains($chars, $names[0][0])) {
            throw new \ValueError('positional arguments are not supported by this port of argparse');
        }

        // otherwise, we're adding an optional argument
        $kwargs = $this->_get_optional_kwargs($names, $kwargs);

        // create the action object, and add it to the parser
        $action_class = $this->_pop_action_class($kwargs);
        if (!is_string($action_class) || !is_a($action_class, Action::class, true)) {
            throw new \ValueError('unknown action "' . (is_string($action_class) ? $action_class : gettype($action_class)) . '"');
        }
        $action = new $action_class(...$kwargs);

        // raise an error if the action type is not callable
        $type_func = $action->type;
        if ($type_func !== null && $type_func !== 'int' && $type_func !== 'str' && !($type_func instanceof \Closure)) {
            throw new \ValueError(Py::repr($type_func) . ' is not callable');
        }

        return $this->_add_action($action);
    }

    public function add_argument_group(?string $title = null, ?string $description = null): _ArgumentGroup
    {
        $group = new _ArgumentGroup($this, $title, $description);
        $this->_action_groups[] = $group;
        return $group;
    }

    public function add_mutually_exclusive_group(bool $required = false): _MutuallyExclusiveGroup
    {
        $group = new _MutuallyExclusiveGroup($this, $required);
        $this->_mutually_exclusive_groups[] = $group;
        return $group;
    }

    public function _add_action(Action $action): Action
    {
        // resolve any conflicts
        $this->_check_conflict($action);

        // add to actions list
        $this->_actions[] = $action;

        // index the action by any option strings it has
        foreach ($action->option_strings as $option_string) {
            $this->_option_string_actions[$option_string] = $action;
        }

        // return the created action
        return $action;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $kwargs
     * @return array<string, mixed>
     */
    public function _get_optional_kwargs(array $args, array $kwargs): array
    {
        // determine short and long option strings
        $option_strings = [];
        $long_option_strings = [];
        foreach ($args as $option_string) {
            // error on strings that don't start with an appropriate prefix
            if (!str_contains($this->prefix_chars, $option_string[0])) {
                throw new \ValueError('invalid option string ' . Py::repr($option_string)
                    . ': must start with a character ' . Py::repr($this->prefix_chars));
            }

            // strings starting with two prefix characters are long options
            $option_strings[] = $option_string;
            if (strlen($option_string) > 1 && str_contains($this->prefix_chars, $option_string[1])) {
                $long_option_strings[] = $option_string;
            }
        }

        // infer destination, '--foo-bar' -> 'foo_bar' and '-x' -> 'x'
        $dest = $kwargs['dest'] ?? null;
        unset($kwargs['dest']);
        if ($dest === null) {
            if ($long_option_strings) {
                $dest_option_string = $long_option_strings[0];
            } else {
                $dest_option_string = $option_strings[0];
            }
            $dest = ltrim($dest_option_string, $this->prefix_chars);
            if ($dest === '') {
                throw new \ValueError('dest= is required for options like ' . Py::repr($option_string));
            }
            $dest = str_replace('-', '_', $dest);
        }

        // return the updated keyword arguments
        return $kwargs + ['dest' => $dest, 'option_strings' => $option_strings];
    }

    /** @param array<string, mixed> $kwargs */
    public function _pop_action_class(array &$kwargs, mixed $default = null): mixed
    {
        $action = $kwargs['action'] ?? $default;
        unset($kwargs['action']);
        if ($action === null) {
            $action = 'store';
        }
        return Argparse::ACTIONS[$action] ?? $action;
    }

    public function _check_conflict(Action $action): void
    {
        // find all options that conflict with this option
        $confl_optionals = [];
        foreach ($action->option_strings as $option_string) {
            if (isset($this->_option_string_actions[$option_string])) {
                $confl_optional = $this->_option_string_actions[$option_string];
                $confl_optionals[] = [$option_string, $confl_optional];
            }
        }

        // resolve any conflicts
        if ($confl_optionals) {
            $this->_handle_conflict_error($action, $confl_optionals);
        }
    }

    /** @param list<array{string, Action}> $conflicting_actions */
    public function _handle_conflict_error(Action $action, array $conflicting_actions): never
    {
        $conflict_string = implode(', ', array_map(fn($c) => $c[0], $conflicting_actions));
        $message = count($conflicting_actions) == 1
            ? "conflicting option string: {$conflict_string}"
            : "conflicting option strings: {$conflict_string}";
        throw new ArgumentError($action, $message);
    }
}
