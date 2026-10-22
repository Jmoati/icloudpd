<?php

namespace Foundation\Argparse;

/**
 * Simple object for storing attributes (`Namespace` is a reserved word in PHP).
 *
 * `setattr`/`getattr`/`hasattr` are the dynamic properties and property_exists().
 */
#[\AllowDynamicProperties]
class Namespace_
{
    public function __construct(mixed ...$kwargs)
    {
        foreach ($kwargs as $name => $value) {
            $this->$name = $value;
        }
    }
}
