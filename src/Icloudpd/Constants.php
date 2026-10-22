<?php

namespace Icloudpd;

/**
 * Constants
 *
 * Each is a class constant and a static property with the same name: `Constants::MAX_RETRIES` and
 * `Constants::$MAX_RETRIES` (the upstream tests read, and may patch, the module attribute).
 */
final class Constants
{
    // For retrying connection after timeouts and errors
    public const MAX_RETRIES = 0;
    public const WAIT_SECONDS = 5;

    public static int $MAX_RETRIES = self::MAX_RETRIES;
    public static int $WAIT_SECONDS = self::WAIT_SECONDS;
}
