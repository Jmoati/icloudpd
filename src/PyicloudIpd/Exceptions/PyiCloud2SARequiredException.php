<?php

namespace PyicloudIpd\Exceptions;

/** iCloud 2SA required exception. */
class PyiCloud2SARequiredException extends PyiCloudException
{
    public function __construct(string $apple_id)
    {
        $message = "Two-step authentication required for account: $apple_id";
        parent::__construct($message);
    }
}
