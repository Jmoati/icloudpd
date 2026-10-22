<?php

namespace Foundation\Argparse;

class _CountAction extends Action
{
    public function __construct(
        array $option_strings,
        ?string $dest,
        mixed $default = null,
        bool $required = false,
        ?string $help = null,
        bool $deprecated = false,
    ) {
        parent::__construct(
            option_strings: $option_strings,
            dest: $dest,
            nargs: 0,
            default: $default,
            required: $required,
            help: $help,
            deprecated: $deprecated,
        );
    }

    public function __invoke(ArgumentParser $parser, Namespace_ $namespace, mixed $values, ?string $option_string = null): void
    {
        $count = $namespace->{$this->dest} ?? null;
        if ($count === null) {
            $count = 0;
        }
        $namespace->{$this->dest} = $count + 1;
    }
}
