<?php

namespace Foundation\Argparse;

use Foundation\Argparse;

/**
 * An error from creating or using an argument (optional or positional).
 *
 * The string value of this exception is the message, augmented with
 * information about the argument that caused it. (PHP: getMessage() is Python's str(error))
 */
class ArgumentError extends \Exception
{
    public ?string $argument_name;

    public function __construct(?Action $argument, string $message)
    {
        $this->argument_name = Argparse::_get_action_name($argument);
        if ($this->argument_name === null) {
            $format = $message;
        } else {
            $format = "argument {$this->argument_name}: {$message}";
        }
        parent::__construct($format);
    }
}
