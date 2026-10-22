<?php

namespace Icloudpd\Authentication;

/**
 * Python's `SystemExit` as raised by `sys.exit(code)`: the code is the exception code, the entry point
 * (and the tests) turn it into the exit status instead of the process dying in the middle of the run.
 */
class SystemExit extends \Exception
{
}
