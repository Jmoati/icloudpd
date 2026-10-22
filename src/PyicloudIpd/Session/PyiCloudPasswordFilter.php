<?php

namespace PyicloudIpd\Session;

use Foundation\Logging\Filter;
use Foundation\Logging\LogRecord;

/** Hides the password in the log messages: the ``name`` of the filter is the password. */
class PyiCloudPasswordFilter extends Filter
{
    public function __construct(string $password)
    {
        parent::__construct($password);
    }

    public function filter(LogRecord $record): bool
    {
        $message = $record->getMessage();
        if (str_contains($message, $this->name)) {
            $record->msg = str_replace($this->name, '********', $message);
            $record->args = [];
        }

        return true;
    }
}
