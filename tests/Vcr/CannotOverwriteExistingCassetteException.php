<?php

namespace Tests\Vcr;

/** Raised when a request is not in the cassette and the record mode is "none" (vcr.errors.CannotOverwriteExistingCassetteException). */
class CannotOverwriteExistingCassetteException extends \RuntimeException
{
}
