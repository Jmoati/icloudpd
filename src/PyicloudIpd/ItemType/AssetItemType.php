<?php

namespace PyicloudIpd\ItemType;

enum AssetItemType: string
{
    case MOVIE = 'movie';
    case IMAGE = 'image';

    /** `__str__` of the Python Enum */
    public function __str__(): string
    {
        return $this->name;
    }
}
