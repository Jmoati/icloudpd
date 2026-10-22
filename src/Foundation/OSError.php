<?php

namespace Foundation;

/**
 * Python's `OSError` (`IOError` is an alias in Python 3); `errno` is the exception code.
 * Subclasses of the standard library (`FileNotFoundError`, `FileExistsError`, `ConnectionError`…) extend it.
 */
class OSError extends \RuntimeException
{
}
