<?php

namespace Foundation\Argparse;

use Foundation\Argparse;

class _HelpAction extends Action
{
    public function __construct(
        array $option_strings,
        ?string $dest = Argparse::SUPPRESS,
        mixed $default = Argparse::SUPPRESS,
        ?string $help = null,
        bool $deprecated = false,
    ) {
        parent::__construct(
            option_strings: $option_strings,
            dest: $dest,
            default: $default,
            nargs: 0,
            help: $help,
            deprecated: $deprecated,
        );
    }

    public function __invoke(ArgumentParser $parser, Namespace_ $namespace, mixed $values, ?string $option_string = null): void
    {
        $parser->print_help();
        $parser->exit();
    }
}
