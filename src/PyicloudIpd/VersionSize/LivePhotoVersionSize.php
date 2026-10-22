<?php

namespace PyicloudIpd\VersionSize;

enum LivePhotoVersionSize: string
{
    case ORIGINAL = 'original';
    case MEDIUM = 'medium';
    case THUMB = 'small';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
