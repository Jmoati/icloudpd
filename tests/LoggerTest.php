<?php

namespace Tests;

use Foundation\Logging;
use Foundation\Logging\StreamHandler;
use Foundation\Patch;
use Icloudpd\Logger;
use Icloudpd\Logger\IPDLogger;

class LoggerTest extends TestCase
{
    // Tests the formatter that is set up in setup_logger()
    public function test_logger_output(): void
    {
        // @freeze_time("2018-01-01 00:00:00-0000"): logging stamps its records with time.time()
        Patch::set('Foundation\Time::time', fn() => (float) (new \DateTimeImmutable('2018-01-01 00:00:00-0000'))->getTimestamp());

        $logger = Logger::setup_logger();
        $test_logger = Logging::getLogger('icloudpd-test');
        $string_io = fopen('php://memory', 'w+');
        $string_handler = new StreamHandler(stream: $string_io);
        $string_handler->setFormatter($logger->handlers[0]->formatter);
        $test_logger->addHandler($string_handler);
        $test_logger->setLevel(Logging::DEBUG);
        $test_logger->info('Test info output');
        $test_logger->debug('Test debug output');
        $test_logger->error('Test error output');
        rewind($string_io);
        $output = trim(stream_get_contents($string_io));
        // 2018:01:01 00:00:00 utc
        $expectedDatetime = (new \DateTimeImmutable('2018-01-01 00:00:00', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
        $this->assertStringContainsString($expectedDatetime, $output);
        $this->assertStringContainsString('INFO     Test info output', $output);
        $this->assertStringContainsString('DEBUG    Test debug output', $output);
        $this->assertStringContainsString('ERROR    Test error output', $output);

        $test_logger->removeHandler($string_handler);
    }

    public function test_logger_tqdm_fallback(): void
    {
        Logging::setLoggerClass(IPDLogger::class);
        $logger = Logging::getLogger('icloudpd-test');

        // logger.log = MagicMock()
        $log_calls = [];
        Patch::set('Foundation\Logging\Logger::log', function ($logger, $level, $msg, ...$args) use (&$log_calls) {
            $log_calls[] = [$level, $msg];
        });

        $logger->set_tqdm_description('foo');
        $this->assertSame([[Logging::INFO, 'foo']], $log_calls);

        $log_calls = [];
        $logger->tqdm_write('bar');
        $this->assertSame([[Logging::INFO, 'bar']], $log_calls);

        // logger.set_tqdm(MagicMock()), its write and set_description being MagicMocks too
        $tqdm = new class {
            public array $write_calls = [];
            public array $set_description_calls = [];

            public function write(string $message): void
            {
                $this->write_calls[] = $message;
            }

            public function set_description(string $desc): void
            {
                $this->set_description_calls[] = $desc;
            }
        };
        $logger->set_tqdm($tqdm);
        $log_calls = [];
        $logger->set_tqdm_description('baz');
        $this->assertSame(['baz'], $tqdm->set_description_calls);
        $logger->tqdm_write('qux');
        $this->assertSame(['qux'], $tqdm->write_calls);
        $this->assertSame([], $log_calls);

        $logger->set_tqdm(null);
    }
}
