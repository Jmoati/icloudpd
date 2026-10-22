<?php

namespace PyicloudIpd\Exceptions;

use Foundation\Py;

/**
 * iCloud failed login exception.
 *
 * Python raises it with two arguments (a message and the exception it comes from), for which
 * ``str(exception)`` is the tuple: the message below is that string.
 */
class PyiCloudFailedLoginException extends PyiCloudException
{
    public function __construct(string $message = '', ?\Throwable $error = null)
    {
        if ($error !== null) {
            $message = '('.Py::repr($message).', '.Py::repr($error).')';
        }
        parent::__construct($message, 0, $error);
    }
}
