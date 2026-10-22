<?php

namespace Icloudpd\Status;

enum Status: string
{
    case NO_INPUT_NEEDED = 'no_input_needed';
    case NEED_MFA = 'need_mfa';
    case SUPPLIED_MFA = 'supplied_mfa';
    case CHECKING_MFA = 'checking_mfa';
    case NEED_PASSWORD = 'need_password';
    case SUPPLIED_PASSWORD = 'supplied_password';
    case CHECKING_PASSWORD = 'checking_password';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
