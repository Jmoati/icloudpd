<?php

namespace Foundation\Argparse;

class _StoreFalseAction extends _StoreConstAction
{
    public function __construct(
        array $option_strings,
        ?string $dest,
        mixed $default = true,
        bool $required = false,
        ?string $help = null,
        bool $deprecated = false,
    ) {
        parent::__construct(
            option_strings: $option_strings,
            dest: $dest,
            const: false,
            default: $default,
            required: $required,
            help: $help,
            deprecated: $deprecated,
        );
    }
}
