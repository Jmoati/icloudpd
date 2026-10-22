<?php

namespace Foundation;

use Foundation\Logging\Formatter;
use Foundation\Logging\Handler;
use Foundation\Logging\Logger;
use Foundation\Logging\StreamHandler;

/**
 * The subset of Python's `logging` module that icloudpd and pyicloud_ipd use: the logger hierarchy
 * (`getLogger`, `setLoggerClass`, propagation), levels, `basicConfig`, and the `lastResort` handler.
 * `Logging\{Logger,LogRecord,Filter,Handler,StreamHandler,NullHandler,Formatter}` are the classes.
 */
final class Logging
{
    public const CRITICAL = 50;
    public const FATAL = self::CRITICAL;
    public const ERROR = 40;
    public const WARNING = 30;
    public const WARN = self::WARNING;
    public const INFO = 20;
    public const DEBUG = 10;
    public const NOTSET = 0;

    private const LEVEL_TO_NAME = [
        self::CRITICAL => 'CRITICAL',
        self::ERROR => 'ERROR',
        self::WARNING => 'WARNING',
        self::INFO => 'INFO',
        self::DEBUG => 'DEBUG',
        self::NOTSET => 'NOTSET',
    ];

    public const BASIC_FORMAT = '%(levelname)s:%(name)s:%(message)s';

    private static ?Logger $root = null;
    private static ?Handler $lastResort = null;
    private static ?float $startTime = null;
    /** @var class-string<Logger> */
    private static string $loggerClass = Logger::class;
    /** @var array<string, Logger|list<Logger>> a list is a `PlaceHolder` (the children waiting for their parent) */
    private static array $loggerDict = [];

    /**
     * Return a string representation of a numeric logging level, or the number for a name.
     * Unknown levels give "Level %s".
     */
    public static function getLevelName(int|string $level): int|string
    {
        if (is_int($level)) {
            return self::LEVEL_TO_NAME[$level] ?? "Level $level";
        }
        $r = array_search($level, self::LEVEL_TO_NAME, true);
        return $r === false ? "Level $level" : $r;
    }

    /** Level as an int: an int, or a level name (`"INFO"`) */
    public static function _checkLevel(int|string $level): int
    {
        if (is_int($level)) {
            return $level;
        }
        $r = array_search($level, self::LEVEL_TO_NAME, true);
        if ($r === false) {
            throw new \ValueError('Unknown level: '.Py::repr($level));
        }
        return $r;
    }

    /** Seconds since the epoch when logging was first used, for `%(relativeCreated)d` */
    public static function startTime(): float
    {
        return self::$startTime ??= Time::time();
    }

    /** The root logger (level WARNING) */
    public static function root(): Logger
    {
        return self::$root ??= new Logger('root', self::WARNING);
    }

    /**
     * The handler of last resort, for a record that no handler of the hierarchy took: stderr at WARNING
     * with the bare message.
     */
    public static function lastResort(): Handler
    {
        return self::$lastResort ??= new StreamHandler(level: self::WARNING);
    }

    /** Tell `getLogger` which class to instantiate for the loggers it creates from now on */
    public static function setLoggerClass(string $class): void
    {
        if (!is_a($class, Logger::class, true)) {
            throw new \TypeError('logger not derived from logging.Logger: '.$class);
        }
        self::$loggerClass = $class;
    }

    /** Return a logger with the specified name, creating it if necessary. `root` (or nothing) is the root logger. */
    public static function getLogger(string $name = 'root'): Logger
    {
        if ($name === '' || $name === 'root') {
            return self::root();
        }
        $rv = self::$loggerDict[$name] ?? null;
        if ($rv === null) {
            $rv = new (self::$loggerClass)($name);
            self::$loggerDict[$name] = $rv;
            self::fixupParents($rv);
        } elseif (is_array($rv)) {
            $ph = $rv;
            $rv = new (self::$loggerClass)($name);
            self::$loggerDict[$name] = $rv;
            self::fixupChildren($ph, $rv);
            self::fixupParents($rv);
        }
        return $rv;
    }

    /** Ensure that there are either loggers or placeholders all the way from the specified logger to the root */
    private static function fixupParents(Logger $alogger): void
    {
        $name = $alogger->name;
        $i = strrpos($name, '.');
        $rv = null;
        while ($i !== false && $i > 0 && $rv === null) {
            $substr = substr($name, 0, $i);
            $obj = self::$loggerDict[$substr] ?? null;
            if ($obj === null) {
                self::$loggerDict[$substr] = [$alogger];
            } elseif ($obj instanceof Logger) {
                $rv = $obj;
            } elseif (!in_array($alogger, $obj, true)) {
                self::$loggerDict[$substr][] = $alogger;
            }
            $i = strrpos(substr($name, 0, $i - 1), '.');
        }
        $alogger->parent = $rv ?? self::root();
    }

    /** Ensure that children of the placeholder `$ph` are connected to the specified logger */
    private static function fixupChildren(array $ph, Logger $alogger): void
    {
        $name = $alogger->name;
        foreach ($ph as $c) {
            // The if means ... if not c.parent.name.startswith(nm)
            if (substr($c->parent->name, 0, strlen($name)) !== $name) {
                $alogger->parent = $c->parent;
                $c->parent = $alogger;
            }
        }
    }

    /**
     * Do basic configuration for the logging system: when the root logger has no handler, add a
     * `StreamHandler($stream)` (stderr by default) with `Formatter($format, $datefmt)`.
     *
     * @param resource|null $stream
     * @param list<Handler>|null $handlers
     */
    public static function basicConfig(
        ?string $format = null,
        ?string $datefmt = null,
        $stream = null,
        ?int $level = null,
        bool $force = false,
        ?array $handlers = null,
    ): void {
        $root = self::root();
        if ($force) {
            foreach ($root->handlers as $h) {
                $root->removeHandler($h);
                $h->close();
            }
        }
        if (count($root->handlers) === 0) {
            $handlers ??= [new StreamHandler($stream)];
            $fmt = new Formatter($format ?? self::BASIC_FORMAT, $datefmt);
            foreach ($handlers as $h) {
                if ($h->formatter === null) {
                    $h->setFormatter($fmt);
                }
                $root->addHandler($h);
            }
            if ($level !== null) {
                $root->setLevel($level);
            }
        }
    }
}
