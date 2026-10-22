<?php

namespace Icloudpd\Logger;

use Foundation\Logging;
use Foundation\Logging\Logger;

/** Custom logger class with support for tqdm progress bar */
class IPDLogger extends Logger
{
    public mixed $tqdm = null;

    public function __construct(string $name, int|string $level = Logging::INFO)
    {
        parent::__construct($name, $level);
        $this->tqdm = null;
    }

    // If tdqm progress bar is not set, we just write regular log messages
    /** Sets the tqdm progress bar */
    public function set_tqdm(mixed $tdqm): void
    {
        $this->tqdm = $tdqm;
    }

    /** Set tqdm progress bar description, fallback to logging */
    public function set_tqdm_description(string $desc, int $loglevel = Logging::INFO): void
    {
        if ($this->tqdm === null) {
            $this->log($loglevel, $desc);
        } else {
            $this->tqdm->set_description($desc);
        }
    }

    /** Write to tqdm progress bar, fallback to logging */
    public function tqdm_write(string $message, int $loglevel = Logging::INFO): void
    {
        if ($this->tqdm === null) {
            $this->log($loglevel, $message);
        } else {
            $this->tqdm->write($message);
        }
    }
}
