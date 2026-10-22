<?php

namespace PyicloudIpd\Exceptions;

class PyiCloud2SARequiredError extends PyiCloudException
{
    public function __construct(string $url)
    {
        $message = "Two-step authentication required for $url";
        parent::__construct($message);
    }
}
