<?php

namespace Foundation\Logging;

use Foundation\Py;

/**
 * Formatter instances are used to convert a LogRecord to text.
 *
 * The format string is a `%(name)s` string over the attributes of the LogRecord:
 *
 *     %(name)s            Name of the logger (logging channel)
 *     %(levelno)s         Numeric logging level for the message
 *     %(levelname)s       Text logging level for the message ("DEBUG", "INFO", ...)
 *     %(created)f         Time when the LogRecord was created
 *     %(asctime)s         Textual time when the LogRecord was created
 *     %(msecs)d           Millisecond portion of the creation time
 *     %(relativeCreated)d Time in milliseconds when the LogRecord was created, relative to the
 *                         time the logging module was loaded
 *     %(process)d         Process ID
 *     %(message)s         The result of record.getMessage(), computed just as the record is emitted
 */
class Formatter
{
    public string $_fmt;
    public ?string $datefmt;

    public string $default_time_format = '%Y-%m-%d %H:%M:%S';
    public string $default_msec_format = '%s,%03d';

    /**
     * Initialize the formatter with a format string (`%(message)s` by default); `$datefmt` is a
     * `strftime` format for `%(asctime)s`, ISO8601-like if omitted.
     *
     * ponytail: only the `%` style, and the format string is not validated.
     */
    public function __construct(string $fmt = '%(message)s', ?string $datefmt = null)
    {
        $this->_fmt = $fmt;
        $this->datefmt = $datefmt;
    }

    /**
     * Return the creation time of the specified LogRecord as formatted text, in local time.
     */
    public function formatTime(LogRecord $record, ?string $datefmt = null): string
    {
        $ct = (new \DateTimeImmutable('@'.(int) floor($record->created)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        if ($datefmt) {
            return Py::strftime($ct, $datefmt);
        }
        $s = Py::strftime($ct, $this->default_time_format);
        if ($this->default_msec_format) {
            $s = Py::mod_format($this->default_msec_format, [$s, (int) $record->msecs]);
        }
        return $s;
    }

    /** Check if the format uses the creation time of the record. */
    public function usesTime(): bool
    {
        return str_contains($this->_fmt, '%(asctime)');
    }

    public function formatMessage(LogRecord $record): string
    {
        return Py::mod_format($this->_fmt, get_object_vars($record));
    }

    /** Format the specified record as text. */
    public function format(LogRecord $record): string
    {
        $record->message = $record->getMessage();
        if ($this->usesTime()) {
            $record->asctime = $this->formatTime($record, $this->datefmt);
        }
        return $this->formatMessage($record);
    }
}
