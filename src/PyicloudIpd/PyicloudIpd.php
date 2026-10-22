<?php

namespace PyicloudIpd;

use Foundation\Logging;
use Foundation\Logging\NullHandler;

/**
 * Package `pyicloud_ipd` (`pyicloud_ipd/__init__.py`): `logging.getLogger(__name__).addHandler(logging.NullHandler())`
 * (and the re-export of `PyiCloudService`, which PHP does not need).
 *
 * Python runs it when the package is imported; here the module loggers call `init()` (idempotent).
 */
final class PyicloudIpd
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (!self::$initialized) {
            self::$initialized = true;
            Logging::moduleLogger('pyicloud_ipd')->addHandler(new NullHandler());
        }
    }
}
