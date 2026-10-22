<?php

namespace Tests;

use Foundation\Logging;
use Foundation\Logging\Filter;
use Foundation\Logging\Formatter;
use Foundation\Logging\Handler;
use Foundation\Logging\LogRecord;
use Foundation\Logging\Logger;
use Foundation\Logging\NullHandler;
use Foundation\Logging\StreamHandler;
use Foundation\Patch;
use Foundation\Py;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `Foundation\Logging`: the hierarchy, propagation, filters, handlers, `lastResort`, `basicConfig`, and
 * the `Filter`, `Formatter` and `getLevelName` outputs of Python 3.13 (the data providers were produced by running Python).
 */
class FoundationLoggingTest extends TestCase
{
    private int $seq = 0;
    private string $prefix;
    private array $root_handlers;
    private int $root_level;
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $this->prefix = 'ft'.bin2hex(random_bytes(3));
        $this->root_handlers = Logging::root()->handlers;
        $this->root_level = Logging::root()->level;
        $this->stream = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        Logging::root()->handlers = $this->root_handlers;
        Logging::root()->level = $this->root_level;
        Logging::setLoggerClass(Logger::class);
        parent::tearDown();
    }

    private function lname(string $suffix = ''): string
    {
        return $this->prefix.($suffix === '' ? '' : '.'.$suffix);
    }

    private function captured(): string
    {
        rewind($this->stream);
        return stream_get_contents($this->stream);
    }

    private function handler(string $fmt = '%(levelname)s:%(name)s:%(message)s'): StreamHandler
    {
        $h = new StreamHandler($this->stream);
        $h->setFormatter(new Formatter($fmt));
        return $h;
    }

    public function test_constants_and_level_names(): void
    {
        $this->assertSame([10, 20, 30, 40, 50], [Logging::DEBUG, Logging::INFO, Logging::WARNING, Logging::ERROR, Logging::CRITICAL]);
        $this->assertSame(0, Logging::NOTSET);
    }

    #[DataProvider('level_name_cases')]
    public function test_getLevelName(int|string $level, int|string $expected): void
    {
        $this->assertSame($expected, Logging::getLevelName($level));
    }

    #[DataProvider('filter_cases')]
    public function test_filter(string $filter_name, string $record_name, bool $expected): void
    {
        $record = new LogRecord($record_name, Logging::INFO, 'm');
        $this->assertSame($expected, (new Filter($filter_name))->filter($record));
    }

    #[DataProvider('formatter_cases')]
    public function test_formatter(string $fmt, ?string $datefmt, int $level, mixed $msg, array $args, float $created, string $name, string $expected): void
    {
        Patch::set('Foundation\Time::time', fn () => $created);
        $record = new LogRecord($name, $level, $msg, $args);
        $this->assertSame($expected, (new Formatter($fmt, $datefmt))->format($record));
    }

    /** upstream tests/test_logger.py::test_logger_output, on a plain logger (the setup_logger() one is Icloudpd's) */
    public function test_logger_output(): void
    {
        Patch::set('Foundation\Time::time', fn () => 1514764800.0); // freeze_time("2018-01-01 00:00:00-0000")
        $logger = Logging::getLogger($this->lname());
        $h = $this->handler('%(asctime)s %(levelname)-8s %(message)s');
        $h->setFormatter(new Formatter('%(asctime)s %(levelname)-8s %(message)s', '%Y-%m-%d %H:%M:%S'));
        $logger->addHandler($h);
        $logger->setLevel(Logging::DEBUG);
        $logger->info('Test info output');
        $logger->debug('Test debug output');
        $logger->error('Test error output');
        $output = trim($this->captured());
        $expectedDatetime = (new \DateTimeImmutable('2018-01-01 00:00:00', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        $this->assertStringContainsString($expectedDatetime, $output);
        $this->assertStringContainsString('INFO     Test info output', $output);
        $this->assertStringContainsString('DEBUG    Test debug output', $output);
        $this->assertStringContainsString('ERROR    Test error output', $output);
    }

    public function test_get_logger_is_a_registry(): void
    {
        $this->assertSame(Logging::getLogger($this->lname('a')), Logging::getLogger($this->lname('a')));
        $this->assertSame(Logging::root(), Logging::getLogger());
        $this->assertSame(Logging::root(), Logging::getLogger('root'));
        $this->assertSame('root', Logging::root()->name);
        $this->assertSame(Logging::WARNING, Logging::root()->level);
    }

    public function test_hierarchy_and_late_parent(): void
    {
        $c = Logging::getLogger($this->lname('a.b.c'));
        $this->assertSame(Logging::root(), $c->parent);
        $a = Logging::getLogger($this->lname('a'));
        $this->assertSame($a, $c->parent);
        $this->assertSame(Logging::root(), $a->parent);
        $b = Logging::getLogger($this->lname('a.b'));
        $this->assertSame($b, $c->parent);
        $this->assertSame($a, $b->parent);
        $d = Logging::getLogger($this->lname('a.x.y'));
        $this->assertSame($a, $d->parent);
    }

    public function test_getChild(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $this->assertSame(Logging::getLogger($this->lname('a.http')), $a->getChild('http'));
        $this->assertSame(Logging::getLogger($this->lname('a.b.c')), $a->getChild('b.c'));
        $this->assertSame(Logging::getLogger('plain'), Logging::root()->getChild('plain'));
        $this->assertSame($a, $a->getChild('http')->parent);
    }

    public function test_levels_and_effective_level(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $b = Logging::getLogger($this->lname('a.b'));
        $this->assertSame(Logging::NOTSET, $b->level);
        $this->assertSame(Logging::WARNING, $b->getEffectiveLevel()); // root
        $a->setLevel(Logging::DEBUG);
        $this->assertSame(Logging::DEBUG, $b->getEffectiveLevel());
        $this->assertTrue($b->isEnabledFor(Logging::DEBUG));
        $b->setLevel('ERROR');
        $this->assertSame(Logging::ERROR, $b->level);
        $this->assertFalse($b->isEnabledFor(Logging::WARNING));
        $this->assertTrue($b->isEnabledFor(Logging::ERROR));
        $this->expectException(\ValueError::class);
        $b->setLevel('LOUD');
    }

    public function test_propagation_to_parent_handlers(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::INFO);
        $a->addHandler($this->handler());
        $child = $a->getChild('child');
        $child->info('hello %s', 'there');
        $child->debug('dropped');
        $this->assertSame('INFO:'.$this->lname('a.child').":hello there\n", $this->captured());
    }

    public function test_propagate_false_stops(): void
    {
        $other = fopen('php://memory', 'w+');
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::INFO);
        $a->addHandler($this->handler());
        $child = $a->getChild('child');
        $h2 = new StreamHandler($other);
        $child->addHandler($h2);
        $child->propagate = false;
        $child->info('x');
        $this->assertSame('', $this->captured());
        rewind($other);
        $this->assertSame("x\n", stream_get_contents($other));
    }

    public function test_handler_level_and_remove(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::DEBUG);
        $h = $this->handler();
        $h->setLevel(Logging::ERROR);
        $a->addHandler($h);
        $a->addHandler($h); // once
        $this->assertCount(1, $a->handlers);
        $a->warning('no');
        $a->error('yes');
        $a->critical('yes too');
        $this->assertSame('ERROR:'.$this->lname('a').":yes\nCRITICAL:".$this->lname('a').":yes too\n", $this->captured());
        $a->removeHandler($h);
        $this->assertSame([], $a->handlers);
        $a->removeHandler($h); // no error
    }

    public function test_log_with_level_and_lazy_formatting(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::INFO);
        $a->addHandler($this->handler('%(levelname)s %(message)s'));
        $a->log(25, 'custom %d', 7);
        $a->log(Logging::DEBUG, 'bad format %d', 'not a number'); // disabled level: never formatted
        $this->assertSame("Level 25 custom 7\n", $this->captured());
    }

    public function test_dict_argument_and_non_string_message(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::DEBUG);
        $a->addHandler($this->handler('%(message)s'));
        $a->debug('%(k)s-%(n)03d', ['k' => 'v', 'n' => 5]);
        $a->debug(['content-type' => 'text/json']); // response.headers is logged as is
        $a->debug('100%'); // no args: no formatting
        $this->assertSame("v-005\n{'content-type': 'text/json'}\n100%\n", $this->captured());
    }

    public function test_disabled_logger(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::DEBUG);
        $a->addHandler($this->handler('%(message)s'));
        $a->disabled = true;
        $a->error('x');
        $this->assertSame('', $this->captured());
        $a->disabled = false;
        $a->error('y');
        $this->assertSame("y\n", $this->captured());
    }

    public function test_logger_filter_with_a_password_filter_like_upstream(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::DEBUG);
        $a->addHandler($this->handler('%(message)s'));
        // pyicloud_ipd.session.PyiCloudPasswordFilter
        $filter = new class('s3cret') extends Filter {
            public function filter(LogRecord $record): bool
            {
                $message = $record->getMessage();
                if (str_contains($message, $this->name)) {
                    $record->msg = str_replace($this->name, '********', $message);
                    $record->args = [];
                }
                return true;
            }
        };
        $a->addFilter($filter);
        $a->addFilter($filter);
        $this->assertCount(1, $a->filters);
        $this->assertContains($filter, $a->filters);
        $a->debug('POST %s %s', 'url', 'password=s3cret');
        $this->assertSame("POST url password=********\n", $this->captured());
        $a->removeFilter($filter);
        $this->assertSame([], $a->filters);
    }

    public function test_filters_veto_replace_and_callables(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::DEBUG);
        $a->addHandler($this->handler('%(message)s'));
        $a->addFilter(fn (LogRecord $r) => $r->msg !== 'secret');
        $a->addFilter(fn (LogRecord $r) => new LogRecord($r->name, $r->levelno, strtoupper($r->msg)));
        $a->info('secret');
        $a->info('shout');
        $this->assertSame("SHOUT\n", $this->captured());
    }

    public function test_handler_filter(): void
    {
        $a = Logging::getLogger($this->lname('a'));
        $a->setLevel(Logging::DEBUG);
        $h = $this->handler('%(message)s');
        $h->addFilter(new Filter($this->lname('a.keep')));
        $a->addHandler($h);
        $a->getChild('keep')->info('kept');
        $a->getChild('drop')->info('dropped');
        $this->assertSame("kept\n", $this->captured());
    }

    public function test_setLoggerClass_applies_to_new_loggers_only(): void
    {
        $before = Logging::getLogger($this->lname('before'));
        Logging::setLoggerClass(IPDLoggerLike::class);
        $after = Logging::getLogger($this->lname('after'));
        $child = $after->getChild('child');
        $this->assertSame(Logger::class, $before::class);
        $this->assertInstanceOf(IPDLoggerLike::class, $after);
        $this->assertInstanceOf(IPDLoggerLike::class, $child);
        $this->assertSame(Logging::INFO, $after->level, 'a subclass constructor default is honoured');
        $this->assertSame($after, Logging::getLogger($this->lname('after')));
        $this->expectException(\TypeError::class);
        Logging::setLoggerClass(\stdClass::class);
    }

    public function test_log_can_be_patched_for_one_logger_class(): void
    {
        $calls = [];
        Patch::set('Foundation\Logging\Logger::log', function (Logger $self, int $level, mixed $msg, mixed ...$args) use (&$calls) {
            $calls[] = [$self->name, $level, $msg, $args];
        });
        $a = Logging::getLogger($this->lname('a'));
        $a->log(Logging::INFO, 'foo');
        $a->log(Logging::INFO, 'bar %s', 'x');
        $this->assertSame([[$this->lname('a'), 20, 'foo', []], [$this->lname('a'), 20, 'bar %s', ['x']]], $calls);
    }

    public function test_last_resort_handler(): void
    {
        $last = Logging::lastResort();
        $this->assertSame(Logging::WARNING, $last->level);
        $old = $last->stream;
        $last->stream = $this->stream;
        try {
            $lonely = Logging::getLogger($this->lname('lonely'));
            $lonely->setLevel(Logging::DEBUG);
            $lonely->info('below WARNING: dropped');
            $lonely->warning('to stderr');
            $lonely->error('with %s', 'args');
            $this->assertSame("to stderr\nwith args\n", $this->captured());

            // a NullHandler anywhere up the hierarchy counts as a handler (pyicloud_ipd/__init__.py)
            ftruncate($this->stream, 0);
            rewind($this->stream);
            $quiet = Logging::getLogger($this->lname('quiet'));
            $quiet->addHandler(new NullHandler());
            $quiet->getChild('deep')->error('swallowed');
            $this->assertSame('', $this->captured());
        } finally {
            $last->stream = $old;
        }
    }

    public function test_basicConfig_configures_root_once(): void
    {
        Logging::root()->handlers = [];
        Logging::basicConfig(format: '%(levelname)s - %(message)s', stream: $this->stream, level: Logging::INFO);
        $this->assertCount(1, Logging::root()->handlers);
        $this->assertSame(Logging::INFO, Logging::root()->level);
        Logging::basicConfig(format: 'ignored', stream: fopen('php://memory', 'w'));
        $this->assertCount(1, Logging::root()->handlers);
        Logging::getLogger($this->lname('a'))->info('via root');
        $this->assertSame("INFO - via root\n", $this->captured());
        // force replaces the handler
        $second = fopen('php://memory', 'w+');
        Logging::basicConfig(format: '%(message)s', stream: $second, force: true);
        $this->assertCount(1, Logging::root()->handlers);
        Logging::getLogger($this->lname('a'))->info('again');
        rewind($second);
        $this->assertSame("again\n", stream_get_contents($second));
    }

    public function test_basicConfig_default_format(): void
    {
        Logging::root()->handlers = [];
        Logging::basicConfig(stream: $this->stream);
        Logging::getLogger($this->lname('a'))->warning('w');
        $this->assertSame('WARNING:'.$this->lname('a').":w\n", $this->captured());
    }

    public function test_stream_handler_defaults_and_swappable_stream(): void
    {
        $h = new StreamHandler();
        $this->assertSame(STDERR, $h->stream);
        $h->stream = $this->stream;
        $h->emit(new LogRecord('n', Logging::INFO, 'swapped'));
        $this->assertSame("swapped\n", $this->captured());
    }

    public function test_handler_name_property(): void
    {
        $h = new StreamHandler($this->stream);
        $this->assertNull($h->name);
        $h->name = 'stdoutLogger';
        $logger = Logging::getLogger($this->lname('a'));
        $logger->addHandler($h);
        $found = false;
        foreach ($logger->handlers as $handler) {
            $found = $found || $handler->name === 'stdoutLogger';
        }
        $this->assertTrue($found);
        $this->assertInstanceOf(Handler::class, $h);
    }

    public function test_record_attributes(): void
    {
        Patch::set('Foundation\Time::time', fn () => 1514764800.123);
        $r = new LogRecord('n', Logging::WARNING, 'a %s', ['b']);
        $this->assertSame('WARNING', $r->levelname);
        $this->assertSame(30, $r->levelno);
        $this->assertSame(1514764800.123, $r->created);
        $this->assertSame(123.0, $r->msecs);
        $this->assertSame('a b', $r->getMessage());
        $this->assertSame(getmypid(), $r->process);
    }

    public function test_py_stdout_is_capturable(): void
    {
        $h = new StreamHandler(Py::stdout());
        ob_start();
        $h->emit(new LogRecord('n', Logging::INFO, 'captured'));
        $this->assertSame("captured\n", ob_get_clean());
    }

    public static function filter_cases(): array
    {
        return [
            ["", "", true],
            ["", "a", true],
            ["", "a.b", true],
            ["", "a.b.c", true],
            ["", "a.bb", true],
            ["", "b.a.b", true],
            ["", "ab", true],
            ["", "a.", true],
            ["", "a.b.c.d", true],
            ["a.b", "", false],
            ["a.b", "a", false],
            ["a.b", "a.b", true],
            ["a.b", "a.b.c", true],
            ["a.b", "a.bb", false],
            ["a.b", "b.a.b", false],
            ["a.b", "ab", false],
            ["a.b", "a.", false],
            ["a.b", "a.b.c.d", true],
            ["a", "", false],
            ["a", "a", true],
            ["a", "a.b", true],
            ["a", "a.b.c", true],
            ["a", "a.bb", true],
            ["a", "b.a.b", false],
            ["a", "ab", false],
            ["a", "a.", true],
            ["a", "a.b.c.d", true],
            ["a.b.c", "", false],
            ["a.b.c", "a", false],
            ["a.b.c", "a.b", false],
            ["a.b.c", "a.b.c", true],
            ["a.b.c", "a.bb", false],
            ["a.b.c", "b.a.b", false],
            ["a.b.c", "ab", false],
            ["a.b.c", "a.", false],
            ["a.b.c", "a.b.c.d", true],
            ["x", "", false],
            ["x", "a", false],
            ["x", "a.b", false],
            ["x", "a.b.c", false],
            ["x", "a.bb", false],
            ["x", "b.a.b", false],
            ["x", "ab", false],
            ["x", "a.", false],
            ["x", "a.b.c.d", false],
        ];
    }

    public static function formatter_cases(): array
    {
        return [
            ["%(asctime)s %(levelname)-8s %(message)s", "%Y-%m-%d %H:%M:%S", 20, "hello %s", ["world"], 1514764800.123, "icloudpd", "2018-01-01 00:00:00 INFO     hello world"],
            ["%(asctime)s %(levelname)-8s %(message)s", null, 10, "plain", [], 1514764800.123, "icloudpd", "2018-01-01 00:00:00,123 DEBUG    plain"],
            ["%(asctime)s", null, 40, "x", [], 1514764800.9996, "n", "2018-01-01 00:00:00,999"],
            ["%(message)s", null, 30, "%d items, %s", [3, [1, 2]], 1.0, "n", "3 items, [1, 2]"],
            ["%(name)s|%(levelno)d|%(levelname)s|%(msecs)d|%(message)s", null, 50, "a %(k)s b", [["k" => "v"]], 1514764800.5, "icloudpd.http", "icloudpd.http|50|CRITICAL|500|a v b"],
            ["%(levelname)s:%(name)s:%(message)s", null, 25, "100%", [], 5.0, "root", "Level 25:root:100%"],
            ["%(created)f|%(asctime)s|%(message)s", "%d/%m/%Y %H:%M", 20, "m", [], 1700000000.25, "n", "1700000000.250000|14/11/2023 22:13|m"],
            ["%(message)s", null, 20, ["a" => 1], [], 5.0, "n", "{'a': 1}"],
            ["%(message)s", null, 20, "only %s", ["arg"], 5.0, "n", "only arg"],
        ];
    }

    public static function level_name_cases(): array
    {
        return [
            [0, "NOTSET"],
            [10, "DEBUG"],
            [20, "INFO"],
            [30, "WARNING"],
            [40, "ERROR"],
            [50, "CRITICAL"],
            [25, "Level 25"],
            [7, "Level 7"],
            ["INFO", 20],
            ["DEBUG", 10],
            ["WARNING", 30],
            ["nope", "Level nope"],
            ["NOTSET", 0],
        ];
    }
}

/** what `icloudpd/logger.py` does: a Logger subclass with its own default level, registered with setLoggerClass */
class IPDLoggerLike extends Logger
{
    public mixed $tqdm = null;

    public function __construct(string $name, int|string $level = Logging::INFO)
    {
        parent::__construct($name, $level);
    }
}
