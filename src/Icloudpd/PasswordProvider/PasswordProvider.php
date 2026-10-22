<?php

namespace Icloudpd\PasswordProvider;

enum PasswordProvider: string
{
    case CONSOLE = 'console';
    case WEBUI = 'webui';
    case PARAMETER = 'parameter';
    case KEYRING = 'keyring';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
