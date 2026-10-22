<?php

namespace Foundation\Argparse;

class _StoreConstAction extends Action
{
    public function __construct(
        array $option_strings,
        ?string $dest,
        mixed $const = null,
        mixed $default = null,
        bool $required = false,
        ?string $help = null,
        ?string $metavar = null,
        bool $deprecated = false,
    ) {
        // as upstream, metavar is accepted but not forwarded
        parent::__construct(
            option_strings: $option_strings,
            dest: $dest,
            nargs: 0,
            const: $const,
            default: $default,
            required: $required,
            help: $help,
            deprecated: $deprecated,
        );
    }

    public function __invoke(ArgumentParser $parser, Namespace_ $namespace, mixed $values, ?string $option_string = null): void
    {
        $namespace->{$this->dest} = $this->const;
    }
}
