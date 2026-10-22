<?php

namespace Foundation;

use Foundation\Argparse\Action;

/**
 * Command-line parsing library: the subset of Python 3.13 `argparse` used by `icloudpd/cli.py` and
 * `pyicloud_ipd/cmdline.py` (optional arguments only).
 *
 * This class holds the module-level constants and helpers; the classes live in `Foundation\Argparse\`.
 *
 * ponytail: no positional arguments, sub-parsers, parents, argument groups with titles, usage=,
 * epilog=, formatter_class, fromfile_prefix_chars, conflict_handler other than "error",
 * set_defaults/argument_default, intermixed parsing, tuple metavars, BooleanOptionalAction,
 * FileType, append_const/extend/version actions: neither cli.py nor cmdline.py use them.
 * Add them from upstream argparse.py when a caller needs them.
 */
final class Argparse
{
    public const SUPPRESS = '==SUPPRESS==';

    public const OPTIONAL = '?';
    public const ZERO_OR_MORE = '*';
    public const ONE_OR_MORE = '+';

    /**
     * Action name (`action="store_true"`) to Action class: the "action" registry of _ActionsContainer.
     * ponytail: static registry, no register() (nobody registers custom actions)
     */
    public const ACTIONS = [
        'store' => Argparse\_StoreAction::class,
        'store_const' => Argparse\_StoreConstAction::class,
        'store_true' => Argparse\_StoreTrueAction::class,
        'store_false' => Argparse\_StoreFalseAction::class,
        'append' => Argparse\_AppendAction::class,
        'count' => Argparse\_CountAction::class,
        'help' => Argparse\_HelpAction::class,
    ];

    /**
     * `sys.stderr` for the messages of argparse (usage, errors, warnings): STDERR unless a test swaps it
     * for a stream of its own, as `redirect_stderr` does upstream.
     *
     * @var resource|null
     */
    public static $stderr = null;

    /** @return resource */
    public static function stderr()
    {
        return self::$stderr ?? STDERR;
    }

    public static function _get_action_name(?Action $argument): ?string
    {
        if ($argument === null) {
            return null;
        } elseif ($argument->option_strings) {
            return implode('/', $argument->option_strings);
        } elseif ($argument->metavar !== null && $argument->metavar !== self::SUPPRESS) {
            return $argument->metavar;
        } elseif ($argument->dest !== null && $argument->dest !== self::SUPPRESS) {
            return $argument->dest;
        } elseif ($argument->choices) {
            return '{' . implode(',', array_map('strval', $argument->choices)) . '}';
        } else {
            return null;
        }
    }

    public static function _copy_items(mixed $items): array
    {
        if ($items === null) {
            return [];
        }
        // arrays are copied on assignment
        return $items;
    }
}
