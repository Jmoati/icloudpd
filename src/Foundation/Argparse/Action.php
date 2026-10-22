<?php

namespace Foundation\Argparse;

/**
 * Information about how to convert command line strings to PHP values.
 *
 * Action objects are used by an ArgumentParser to represent the information
 * needed to parse a single argument from one or more strings from the
 * command line. The keyword arguments to the Action constructor are also
 * all attributes of Action instances.
 *
 * Keyword Arguments:
 *
 *     - option_strings -- A list of command-line option strings which
 *         should be associated with this action.
 *
 *     - dest -- The name of the attribute to hold the created object(s)
 *
 *     - nargs -- The number of command-line arguments that should be
 *         consumed. By default, one argument will be consumed and a single
 *         value will be produced.  Other values include:
 *             - N (an integer) consumes N arguments (and produces a list)
 *             - '?' consumes zero or one arguments
 *             - '*' consumes zero or more arguments (and produces a list)
 *             - '+' consumes one or more arguments (and produces a list)
 *         Note that the difference between the default and nargs=1 is that
 *         with the default, a single value will be produced, while with
 *         nargs=1, a list containing a single value will be produced.
 *
 *     - const -- The value to be produced if the option is specified and the
 *         option uses an action that takes no values.
 *
 *     - default -- The value to be produced if the option is not specified.
 *
 *     - type -- A callable that accepts a single string argument, and
 *         returns the converted value.  ponytail: the Python builtins `str` and `int` are
 *         the strings "str" and "int" (PHP has no callable for them); anything else is a Closure.
 *         If null, str is used.
 *
 *     - choices -- A container of values that should be allowed. If not null,
 *         after a command-line argument has been converted to the appropriate
 *         type, an exception will be raised if it is not a member of this
 *         collection.
 *
 *     - required -- True if the action must always be specified at the
 *         command line. This is only meaningful for optional command-line
 *         arguments.
 *
 *     - help -- The help string describing the argument.
 *
 *     - metavar -- The name to be used for the option's argument with the
 *         help string. If null, the 'dest' value will be used as the name.
 */
class Action
{
    public function __construct(
        public array $option_strings,
        public ?string $dest,
        public int|string|null $nargs = null,
        public mixed $const = null,
        public mixed $default = null,
        public \Closure|string|null $type = null,
        public ?array $choices = null,
        public bool $required = false,
        public ?string $help = null,
        public ?string $metavar = null,
        public bool $deprecated = false,
    ) {
    }

    public function format_usage(): string
    {
        return $this->option_strings[0];
    }

    /** `__call__` of the Python Action */
    public function __invoke(ArgumentParser $parser, Namespace_ $namespace, mixed $values, ?string $option_string = null): void
    {
        throw new \BadMethodCallException('.__call__() not defined');
    }
}
