<?php

namespace Foundation\Argparse;

class _StoreTrueAction extends _StoreConstAction
{
    public function __construct(
        array $option_strings,
        ?string $dest,
        mixed $default = false,
        bool $required = false,
        ?string $help = null,
        bool $deprecated = false,
    ) {
        parent::__construct(
            option_strings: $option_strings,
            dest: $dest,
            const: true,
            deprecated: $deprecated,
            required: $required,
            help: $help,
            default: $default,
        );
    }
}
