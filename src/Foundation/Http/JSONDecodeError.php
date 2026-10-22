<?php

namespace Foundation\Http;

/**
 * Couldn't decode the text into json.
 *
 * Python: ``requests.exceptions.JSONDecodeError(InvalidJSONError, json.JSONDecodeError)``, a ``ValueError``.
 */
class JSONDecodeError extends \ValueError
{
}
