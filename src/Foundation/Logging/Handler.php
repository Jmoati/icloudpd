<?php

namespace Foundation\Logging;

use Foundation\Logging;
use Foundation\Py;

/**
 * Handler instances dispatch logging events to specific destinations.
 *
 * The base handler class. Acts as a placeholder which defines the Handler interface. Handlers can
 * optionally use Formatter instances to format records as desired. By default, no formatter is
 * specified; in this case, the 'raw' message as determined by record.message is logged.
 */
abstract class Handler extends Filterer
{
    public ?string $name = null;
    public int $level;
    public ?Formatter $formatter = null;

    private static ?Formatter $defaultFormatter = null;

    /** Initializes the instance - basically setting the formatter to None and the filter list to empty. */
    public function __construct(int|string $level = Logging::NOTSET)
    {
        $this->level = Logging::_checkLevel($level);
    }

    /** Set the logging level of this handler. */
    public function setLevel(int|string $level): void
    {
        $this->level = Logging::_checkLevel($level);
    }

    /** Set the formatter for this handler. */
    public function setFormatter(?Formatter $fmt): void
    {
        $this->formatter = $fmt;
    }

    /** Format the specified record. If a formatter is set, use it. Otherwise, use the default formatter. */
    public function format(LogRecord $record): string
    {
        $fmt = $this->formatter ?? (self::$defaultFormatter ??= new Formatter());
        return $fmt->format($record);
    }

    /** Do whatever it takes to actually log the specified logging record. */
    abstract public function emit(LogRecord $record): void;

    /**
     * Conditionally emit the specified logging record, depending on filters which may have been added
     * to the handler. Returns the (possibly modified) record, or false if a filter vetoed it.
     */
    public function handle(LogRecord $record): LogRecord|false
    {
        $rv = $this->filter($record);
        if ($rv instanceof LogRecord) {
            $record = $rv;
        }
        if ($rv) {
            $this->emit($record);
        }
        return $rv;
    }

    /** Ensure all logging output has been flushed. */
    public function flush(): void
    {
    }

    /** Tidy up any resources used by the handler. */
    public function close(): void
    {
    }

    /**
     * Handle errors which occur during an emit() call: report them on stderr and carry on, as
     * Python does (`logging.raiseExceptions`).
     */
    public function handleError(LogRecord $record, ?\Throwable $e = null): void
    {
        $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($stderr, "--- Logging error ---\n".($e ? $e::class.': '.$e->getMessage()."\n" : '')
            .'Message: '.Py::repr($record->msg)."\nArguments: ".Py::str($record->args)."\n");
    }
}
