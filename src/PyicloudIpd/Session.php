<?php

namespace PyicloudIpd;

use Foundation\Logging;
use Foundation\Logging\Logger;

/** Module `pyicloud_ipd/session.py` (the classes are in `PyicloudIpd\Session\`). */
final class Session
{
    public const HEADER_DATA = [
        'X-Apple-ID-Account-Country' => 'account_country',
        'X-Apple-ID-Session-Id' => 'session_id',
        'X-Apple-Session-Token' => 'session_token',
        'X-Apple-TwoSV-Trust-Token' => 'trust_token',
        'X-Apple-TwoSV-Trust-Eligible' => 'trust_eligible',
        'X-Apple-I-Rscd' => 'apple_rscd',
        'X-Apple-I-Ercd' => 'apple_ercd',
        'scnt' => 'scnt',
    ];

    /** `LOGGER = logging.getLogger(__name__)` */
    public static function LOGGER(): Logger
    {
        PyicloudIpd::init();

        return Logging::getLogger('pyicloud_ipd.session');
    }

    /**
     * `inspect.getmodule(frame).__name__` of a class: the Python module the class lives in. A module is a
     * namespace segment (`PyicloudIpd\Base\PyiCloudService` is in `pyicloud_ipd.base`) when a class with the
     * name of the namespace exists (`PyicloudIpd\Base`, the module-level functions), else the class is the module.
     *
     * ponytail: a heuristic, for the name of a logger; tests assert nothing on it.
     */
    public static function module_name(string $class): string
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $module = $namespace !== '' && class_exists($namespace) ? $namespace : $class;

        return strtolower(preg_replace('/(?<!^)(?<!\.)([A-Z])/', '_$1', str_replace('\\', '.', $module)));
    }
}
