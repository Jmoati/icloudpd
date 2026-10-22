<?php

namespace Foundation\Logging;

use Foundation\Logging;

/**
 * A handler class which writes logging records, appropriately formatted, to a stream. Note that this
 * class does not close the stream, as STDOUT or STDERR may be used.
 */
class StreamHandler extends Handler
{
    public string $terminator = "\n";

    /** @var resource */
    public $stream;

    /**
     * Initialize the handler. If `$stream` is not specified, STDERR is used (`php://output` for what
     * Python calls `sys.stdout`, see `Py::stdout()`). The stream is a public property that can be
     * swapped, as the tests do to capture the output.
     *
     * @param resource|null $stream
     */
    public function __construct($stream = null, int|string $level = Logging::NOTSET)
    {
        parent::__construct($level);
        $this->stream = $stream ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
    }

    /** Flushes the stream. */
    public function flush(): void
    {
        if (is_resource($this->stream)) {
            fflush($this->stream);
        }
    }

    /** Emit a record: format it and write it to the stream with a terminator. */
    public function emit(LogRecord $record): void
    {
        try {
            $msg = $this->format($record);
            fwrite($this->stream, $msg.$this->terminator);
            $this->flush();
        } catch (\Throwable $e) {
            $this->handleError($record, $e);
        }
    }
}
