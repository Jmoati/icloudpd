<?php

namespace Icloudpd\MfaProvider;

enum MFAProvider: string
{
    case CONSOLE = 'console';
    case WEBUI = 'webui';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
