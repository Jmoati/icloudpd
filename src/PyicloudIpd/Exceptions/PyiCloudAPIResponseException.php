<?php

namespace PyicloudIpd\Exceptions;

/** iCloud response exception. */
class PyiCloudAPIResponseException extends PyiCloudException
{
    public string $reason;
    /** the error code (a string, or whatever Apple sent): the property of PHP's Exception, made public */
    public $code;

    public function __construct(string $reason, mixed $code = null)
    {
        $this->reason = $reason;
        $this->code = $code;
        $message = $reason ?: '';
        if ($code) {
            $message .= " ($code)";
        }

        parent::__construct($message);
        $this->code = $code;
    }
}
