<?php

namespace Icloudpd\LogLevel;

enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case ERROR = 'error';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
