<?php

namespace PyicloudIpd\AssetVersion;

class AssetVersion
{
    public function __construct(
        public int $size,
        public string $url,
        public string $type,
        public string $checksum,
    ) {
    }

    public function __eq__(mixed $other): bool
    {
        if (!($other instanceof AssetVersion)) {
            // don't attempt to compare against unrelated types
            return false;
        }
        return $this->size == $other->size && $this->url == $other->url && $this->type == $other->type;
    }
}
