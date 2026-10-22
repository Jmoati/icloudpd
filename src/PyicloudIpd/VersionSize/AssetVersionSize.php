<?php

namespace PyicloudIpd\VersionSize;

enum AssetVersionSize: string
{
    case ORIGINAL = 'original';
    case ADJUSTED = 'adjusted';
    case ALTERNATIVE = 'alternative';
    case MEDIUM = 'medium';
    case THUMB = 'thumb';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
