<?php

namespace Foundation\Logging;

use Foundation\Logging;
use Foundation\Py;
use Foundation\Time;

/**
 * A LogRecord instance represents an event being logged.
 *
 * The main information passed in is in msg and args, which are combined using str(msg) % args to
 * create the message field of the record. ponytail: no pathname, lineno, funcName, exc_info or
 * thread: the formatter attributes are name, levelno, levelname, message, asctime, created, msecs,
 * relativeCreated, process.
 */
class LogRecord
{
    public string $name;
    public mixed $msg;
    /** @var array the arguments, or the mapping of a lone dict argument */
    public array $args;
    public string $levelname;
    public int $levelno;
    public float $created;
    public float $msecs;
    public float $relativeCreated;
    public int $process;
    /** set by `Formatter::format` */
    public ?string $message = null;
    public ?string $asctime = null;

    public function __construct(string $name, int $level, mixed $msg, array $args = [])
    {
        $ct = Time::time();
        $this->name = $name;
        $this->msg = $msg;
        // The following statement allows passing of a dictionary as a sole argument, so that you can do
        // something like logging.debug("a %(a)d b %(b)s", {'a':1, 'b':2})
        if (count($args) === 1 && isset($args[0]) && is_array($args[0]) && $args[0] !== [] && !array_is_list($args[0])) {
            $args = $args[0];
        }
        $this->args = $args;
        $this->levelname = Logging::getLevelName($level);
        $this->levelno = $level;
        $this->created = $ct;
        // Get the number of whole milliseconds (0-999) in the fractional part of seconds.
        $us = (int) round(($ct - floor($ct)) * 1_000_000);
        $this->msecs = $us >= 1_000_000 ? 0.0 : (float) intdiv($us, 1000);
        $this->relativeCreated = ($ct - Logging::startTime()) * 1000;
        $this->process = getmypid();
    }

    /**
     * Return the message for this LogRecord.
     *
     * Return the message for this LogRecord after merging any user-supplied arguments with the message.
     */
    public function getMessage(): string
    {
        $msg = Py::str($this->msg);
        if ($this->args) {
            $msg = Py::mod_format($msg, $this->args);
        }
        return $msg;
    }
}
