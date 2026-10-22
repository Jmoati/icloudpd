<?php

namespace Foundation\Logging;

use Foundation\Logging;
use Foundation\Patch;

/**
 * Instances of the Logger class represent a single logging channel. A "logging channel" indicates an
 * area of an application. Exactly how an "area" is defined is up to the application developer. Since
 * an application can have any number of areas, logging channels are identified by a unique string.
 * Application areas can be nested (e.g. an area of "input processing" might include sub-areas
 * "read CSV files", "read XLS files" and "read Gnumeric files"). To cater for this natural nesting,
 * channel names are organized into a namespace hierarchy where levels are separated by periods, much
 * like the Java or Python package namespace. So in the instance given above, channel names might be
 * "input" for the upper level, and "input.csv", "input.xls" and "input.gnu" for the sub-levels.
 * There is no arbitrary limit to the depth of nesting.
 */
class Logger extends Filterer
{
    public string $name;
    public int $level;
    public ?Logger $parent = null;
    public bool $propagate = true;
    /** @var list<Handler> */
    public array $handlers = [];
    public bool $disabled = false;

    /** Initialize the logger with a name and an optional level. */
    public function __construct(string $name, int|string $level = Logging::NOTSET)
    {
        $this->name = $name;
        $this->level = Logging::_checkLevel($level);
    }

    /** Set the logging level of this logger. `$level` must be an int or a str. */
    public function setLevel(int|string $level): void
    {
        $this->level = Logging::_checkLevel($level);
    }

    /**
     * Log 'msg % args' with severity 'DEBUG'.
     *
     * To pass exception information, use the keyword argument exc_info with a true value, e.g.
     *
     * logger.debug("Houston, we have a %s", "thorny problem")
     */
    public function debug(mixed $msg, mixed ...$args): void
    {
        if ($this->isEnabledFor(Logging::DEBUG)) {
            $this->_log(Logging::DEBUG, $msg, $args);
        }
    }

    /** Log 'msg % args' with severity 'INFO'. */
    public function info(mixed $msg, mixed ...$args): void
    {
        if ($this->isEnabledFor(Logging::INFO)) {
            $this->_log(Logging::INFO, $msg, $args);
        }
    }

    /** Log 'msg % args' with severity 'WARNING'. */
    public function warning(mixed $msg, mixed ...$args): void
    {
        if ($this->isEnabledFor(Logging::WARNING)) {
            $this->_log(Logging::WARNING, $msg, $args);
        }
    }

    /** Log 'msg % args' with severity 'ERROR'. */
    public function error(mixed $msg, mixed ...$args): void
    {
        if ($this->isEnabledFor(Logging::ERROR)) {
            $this->_log(Logging::ERROR, $msg, $args);
        }
    }

    /** Log 'msg % args' with severity 'CRITICAL'. */
    public function critical(mixed $msg, mixed ...$args): void
    {
        if ($this->isEnabledFor(Logging::CRITICAL)) {
            $this->_log(Logging::CRITICAL, $msg, $args);
        }
    }

    /**
     * Log 'msg % args' with the integer severity 'level'.
     *
     * logger.log(level, "We have a %s", "mysterious problem")
     */
    public function log(int $level, mixed $msg, mixed ...$args): void
    {
        if ($p = Patch::get(__METHOD__)) {
            $p($this, $level, $msg, ...$args);
            return;
        }
        if ($this->isEnabledFor($level)) {
            $this->_log($level, $msg, $args);
        }
    }

    /** Get the effective level for this logger: walk up the hierarchy for the first level that is set. */
    public function getEffectiveLevel(): int
    {
        for ($logger = $this; $logger; $logger = $logger->parent) {
            if ($logger->level) {
                return $logger->level;
            }
        }
        return Logging::NOTSET;
    }

    /** Is this logger enabled for level 'level'? */
    public function isEnabledFor(int $level): bool
    {
        if ($this->disabled) {
            return false;
        }
        return $level >= $this->getEffectiveLevel();
    }

    /**
     * Get a logger which is a descendant to this one.
     *
     * This is a convenience method, such that
     *
     * logging.getLogger('abc').getChild('def.ghi')
     *
     * is the same as
     *
     * logging.getLogger('abc.def.ghi')
     */
    public function getChild(string $suffix): Logger
    {
        if ($this !== Logging::root()) {
            $suffix = $this->name.'.'.$suffix;
        }
        return Logging::getLogger($suffix);
    }

    /** Low-level logging routine which creates a LogRecord and then calls all the handlers of this logger to handle the record. */
    protected function _log(int $level, mixed $msg, array $args): void
    {
        $this->handle($this->makeRecord($this->name, $level, $msg, $args));
    }

    /** A factory method which can be overridden in subclasses to create specialized LogRecords. */
    public function makeRecord(string $name, int $level, mixed $msg, array $args): LogRecord
    {
        return new LogRecord($name, $level, $msg, $args);
    }

    /** Call the handlers for the specified record. */
    public function handle(LogRecord $record): void
    {
        if ($this->disabled) {
            return;
        }
        $maybe_record = $this->filter($record);
        if (!$maybe_record) {
            return;
        }
        $this->callHandlers($maybe_record);
    }

    /** Add the specified handler to this logger. */
    public function addHandler(Handler $hdlr): void
    {
        if (!in_array($hdlr, $this->handlers, true)) {
            $this->handlers[] = $hdlr;
        }
    }

    /** Remove the specified handler from this logger. */
    public function removeHandler(Handler $hdlr): void
    {
        $i = array_search($hdlr, $this->handlers, true);
        if ($i !== false) {
            array_splice($this->handlers, $i, 1);
        }
    }

    /**
     * Pass a record to all relevant handlers.
     *
     * Loop through all handlers for this logger and its parents in the logger hierarchy. If no handler
     * was found, the record is output by the `lastResort` handler. Stop searching up the hierarchy
     * whenever a logger with the "propagate" attribute set to zero is found - that will be the last
     * logger whose handlers are called.
     */
    public function callHandlers(LogRecord $record): void
    {
        $c = $this;
        $found = 0;
        while ($c) {
            foreach ($c->handlers as $hdlr) {
                $found++;
                if ($record->levelno >= $hdlr->level) {
                    $hdlr->handle($record);
                }
            }
            $c = $c->propagate ? $c->parent : null;
        }
        if ($found === 0) {
            $lastResort = Logging::lastResort();
            if ($record->levelno >= $lastResort->level) {
                $lastResort->handle($record);
            }
        }
    }
}
