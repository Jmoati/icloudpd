<?php

namespace PyicloudIpd\Exceptions;

class PyiCloudAPIResponseError extends PyiCloudException
{
    public string $reason;
    public $code;

    public function __construct(string $reason, ?int $code)
    {
        $this->reason = $reason;
        $message = $reason;
        if ($code) {
            $message .= " ($code)";
        }

        parent::__construct($message);
        $this->code = $code;
    }
}
