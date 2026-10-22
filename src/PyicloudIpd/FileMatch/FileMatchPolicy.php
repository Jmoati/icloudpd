<?php

namespace PyicloudIpd\FileMatch;

enum FileMatchPolicy: string
{
    case NAME_SIZE_DEDUP_WITH_SUFFIX = 'name-size-dedup-with-suffix';
    case NAME_ID7 = 'name-id7';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
