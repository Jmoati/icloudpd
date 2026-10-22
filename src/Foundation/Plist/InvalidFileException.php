<?php

namespace Foundation\Plist;

/** plistlib.InvalidFileException (a ValueError, as in Python) */
final class InvalidFileException extends \ValueError
{
    public function __construct(string $message = 'Invalid file')
    {
        parent::__construct($message);
    }
}
