<?php

namespace Foundation\Logging;

/**
 * A base class for loggers and handlers which allows them to share common code.
 */
class Filterer
{
    /** @var list<Filter|callable> */
    public array $filters = [];

    /** Add the specified filter to this handler. */
    public function addFilter(Filter|callable $filter): void
    {
        if (!in_array($filter, $this->filters, true)) {
            $this->filters[] = $filter;
        }
    }

    /** Remove the specified filter from this handler. */
    public function removeFilter(Filter|callable $filter): void
    {
        $i = array_search($filter, $this->filters, true);
        if ($i !== false) {
            array_splice($this->filters, $i, 1);
        }
    }

    /**
     * Determine if a record is loggable by consulting all the filters.
     *
     * The default is to allow the record to be logged; any filter can veto this by returning a
     * false value. If a filter returns a LogRecord instance instead of a boolean, that record is
     * used instead of the original (allows filters to modify records).
     */
    public function filter(LogRecord $record): LogRecord|false
    {
        foreach ($this->filters as $f) {
            $result = $f instanceof Filter ? $f->filter($record) : $f($record);
            if (!$result) {
                return false;
            }
            if ($result instanceof LogRecord) {
                $record = $result;
            }
        }
        return $record;
    }
}
