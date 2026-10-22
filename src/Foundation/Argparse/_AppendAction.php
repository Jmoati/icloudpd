<?php

namespace Foundation\Argparse;

use Foundation\Argparse;

class _AppendAction extends Action
{
    public function __construct(
        array $option_strings,
        ?string $dest,
        int|string|null $nargs = null,
        mixed $const = null,
        mixed $default = null,
        \Closure|string|null $type = null,
        ?array $choices = null,
        bool $required = false,
        ?string $help = null,
        ?string $metavar = null,
        bool $deprecated = false,
    ) {
        if ($nargs === 0) {
            throw new \ValueError('nargs for append actions must be != 0; if arg '
                . 'strings are not supplying the value to append, '
                . 'the append const action may be more appropriate');
        }
        if ($const !== null && $nargs !== Argparse::OPTIONAL) {
            throw new \ValueError("nargs must be '?' to supply const");
        }
        parent::__construct(
            option_strings: $option_strings,
            dest: $dest,
            nargs: $nargs,
            const: $const,
            default: $default,
            type: $type,
            choices: $choices,
            required: $required,
            help: $help,
            metavar: $metavar,
            deprecated: $deprecated,
        );
    }

    public function __invoke(ArgumentParser $parser, Namespace_ $namespace, mixed $values, ?string $option_string = null): void
    {
        $items = $namespace->{$this->dest} ?? null;
        $items = Argparse::_copy_items($items);
        $items[] = $values;
        $namespace->{$this->dest} = $items;
    }
}
