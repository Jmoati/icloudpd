<?php

namespace Icloudpd;

use Foundation\Logging;
use Foundation\Logging\Formatter;
use Foundation\Logging\StreamHandler;
use Foundation\Py;
use Icloudpd\Logger\IPDLogger;

/** Custom logging class and setup function */
final class Logger
{
    /** Set up logger and add stdout handler */
    public static function setup_logger(): \Foundation\Logging\Logger
    {
        Logging::setLoggerClass(IPDLogger::class);
        $logger = Logging::getLogger('icloudpd');
        $has_stdout_handler = false;
        foreach ($logger->handlers as $handler) {
            if ($handler->name == 'stdoutLogger') {
                $has_stdout_handler = true;
            }
        }
        if (!$has_stdout_handler) {
            $formatter = new Formatter(
                fmt: '%(asctime)s %(levelname)-8s %(message)s',
                datefmt: '%Y-%m-%d %H:%M:%S',
            );
            $stdout_handler = new StreamHandler(stream: Py::stdout());
            $stdout_handler->setFormatter($formatter);
            $stdout_handler->name = 'stdoutLogger';
            $logger->addHandler($stdout_handler);
        }
        return $logger;
    }
}
