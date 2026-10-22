<?php

namespace PyicloudIpd\LivePhotoMovFilenamePolicy;

enum LivePhotoMovFilenamePolicy: string
{
    case SUFFIX = 'suffix';
    case ORIGINAL = 'original';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
