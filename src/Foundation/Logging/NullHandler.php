<?php

namespace Foundation\Logging;

/**
 * This handler does nothing. It's intended to be used to avoid the "No handlers could be found for
 * logger XXX" one-off warning (here: the `lastResort` handler).
 */
class NullHandler extends Handler
{
    public function handle(LogRecord $record): LogRecord|false
    {
        return $record;
    }

    public function emit(LogRecord $record): void
    {
    }
}
