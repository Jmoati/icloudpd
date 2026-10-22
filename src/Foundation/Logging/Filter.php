<?php

namespace Foundation\Logging;

/**
 * Filter instances are used to perform arbitrary filtering of LogRecords.
 *
 * Loggers and Handlers can optionally use Filter instances to filter records as desired. The base
 * filter class only allows events which are below a certain point in the logger hierarchy. For
 * example, a filter initialized with "A.B" will allow events logged by loggers "A.B", "A.B.C",
 * "A.B.C.D", "A.B.D" etc. but not "A.BB", "B.A.B" etc. If initialized with the empty string, all
 * events are passed.
 */
class Filter
{
    public string $name;
    public int $nlen;

    /** Initialize a filter. `$name` is the name of the logger which, together with its children, will have its events allowed through the filter. If no name is specified, allow every event. */
    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->nlen = strlen($name);
    }

    /** Determine if the specified record is to be logged. Returns true if the record is to be logged, false otherwise. */
    public function filter(LogRecord $record): bool
    {
        if ($this->nlen === 0) {
            return true;
        }
        if ($this->name === $record->name) {
            return true;
        }
        if (!str_starts_with($record->name, $this->name)) {
            return false;
        }
        return ($record->name[$this->nlen] ?? '') === '.';
    }
}
