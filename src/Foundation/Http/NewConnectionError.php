<?php

namespace Foundation\Http;

/** urllib3.exceptions.NewConnectionError: "Failed to establish a new connection". */
class NewConnectionError extends \RuntimeException
{
    public function __construct(public mixed $pool = null, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
