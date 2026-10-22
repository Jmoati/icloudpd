<?php

namespace PyicloudIpd\RawPolicy;

enum RawTreatmentPolicy: string
{
    case AS_IS = 'as-is';
    case AS_ORIGINAL = 'as-original';
    case AS_ALTERNATIVE = 'as-alternative';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
