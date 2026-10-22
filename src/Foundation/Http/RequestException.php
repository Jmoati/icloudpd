<?php

namespace Foundation\Http;

/**
 * There was an ambiguous exception that occurred while handling your request.
 *
 * Python: ``class RequestException(IOError)``.
 */
class RequestException extends \Foundation\OSError
{
    public mixed $response;
    public mixed $request;

    /** Initialize RequestException with `request` and `response` objects. */
    public function __construct(string $message = '', ?\Throwable $previous = null, mixed $response = null, mixed $request = null)
    {
        $this->response = $response;
        $this->request = $request;
        if ($response !== null && !$request && isset($response->request)) {
            $this->request = $response->request;
        }
        parent::__construct($message, 0, $previous);
    }
}
