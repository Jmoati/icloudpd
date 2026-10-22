<?php

namespace Tests;

use Foundation\Logging;
use Foundation\Logging\Formatter;
use Foundation\Logging\StreamHandler;
use Foundation\Patch;
use Foundation\Py;
use Foundation\Tqdm;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `Foundation\Tqdm`. `format_meter` and `format_interval` are compared with tqdm 4.67.1 (the data
 * providers were produced by running Python); the rest is the behaviour icloudpd relies on.
 */
class FoundationTqdmTest extends TestCase
{
    /** @var resource */
    private $file;
    private float $clock = 1000.0;
    private array $root_handlers;

    protected function setUp(): void
    {
        $this->file = fopen('php://memory', 'w+');
        $this->clock = 1000.0;
        // every look at the clock takes a second, so that the bar refreshes at each step
        Patch::set('Foundation\Time::time', function () {
            return $this->clock += 1.0;
        });
        $this->root_handlers = Logging::root()->handlers;
    }

    protected function tearDown(): void
    {
        Logging::root()->handlers = $this->root_handlers;
        parent::tearDown();
    }

    private function written(): string
    {
        rewind($this->file);
        return stream_get_contents($this->file);
    }

    #[DataProvider('format_meter_cases')]
    public function test_format_meter(int|float $n, int|float|null $total, float $elapsed, ?int $ncols, string $prefix, bool $ascii, string $expected): void
    {
        $this->assertSame($expected, Tqdm::format_meter($n, $total, $elapsed, $ncols, $prefix, $ascii));
    }

    #[DataProvider('interval_cases')]
    public function test_format_interval(int|float $t, string $expected): void
    {
        $this->assertSame($expected, Tqdm::format_interval($t));
    }

    public function test_iteration_yields_the_items_and_counts(): void
    {
        $bar = new Tqdm(iterable: ['a', 'b', 'c'], leave: false, file: $this->file);
        $this->assertSame(3, $bar->total);
        $seen = [];
        foreach ($bar as $x) {
            $seen[] = $x;
        }
        $this->assertSame(['a', 'b', 'c'], $seen);
        $this->assertSame(3, $bar->n);
        $this->assertTrue($bar->disable, 'closed at the end');
    }

    public function test_generator_with_explicit_total(): void
    {
        $gen = (function () {
            yield from [1, 2];
        })();
        $bar = new Tqdm(iterable: $gen, total: 5, ascii: true, leave: false, dynamic_ncols: true, file: $this->file);
        $this->assertSame(5, $bar->total);
        $this->assertSame([1, 2], iterator_to_array($bar, false));
        $this->assertStringStartsWith("\r  0%|", $this->written());
    }

    public function test_unknown_total_has_no_percentage(): void
    {
        $gen = (function () {
            yield 1;
        })();
        $bar = new Tqdm(iterable: $gen, file: $this->file);
        $this->assertNull($bar->total);
        $this->assertMatchesRegularExpression('/^\r0it \[00:0\d, \?it\/s\]/', $this->written());
    }

    public function test_leave_false_clears_the_line(): void
    {
        $bar = new Tqdm(iterable: [1, 2], leave: false, file: $this->file);
        iterator_to_array($bar);
        $out = $this->written();
        $this->assertStringStartsWith("\r", $out);
        $this->assertStringEndsWith("\r", $out);
        $this->assertStringNotContainsString("\n", $out);
        // the last drawing before the clear shows the end state
        $this->assertStringContainsString('100%', $out);
    }

    public function test_leave_true_keeps_the_final_bar(): void
    {
        $bar = new Tqdm(iterable: [1, 2], file: $this->file, ascii: true);
        iterator_to_array($bar);
        $out = $this->written();
        $this->assertStringEndsWith("\n", $out);
        $this->assertStringContainsString('100%|##########| 2/2', $out);
    }

    public function test_disable_writes_nothing(): void
    {
        $bar = new Tqdm(iterable: [1, 2, 3], total: 3, disable: true, file: $this->file);
        $this->assertSame([1, 2, 3], iterator_to_array($bar, false));
        $bar->update();
        $bar->set_description('x');
        $bar->close();
        $this->assertSame('', $this->written());
        $this->assertSame(0, $bar->n, 'a disabled bar does not count');
    }

    public function test_update_and_set_description(): void
    {
        $bar = new Tqdm(total: 10, desc: 'Downloading', file: $this->file, ascii: true);
        $bar->update(4);
        $bar->set_description('Next');
        $this->assertSame(4, $bar->n);
        $this->assertSame('Next: ', $bar->desc);
        $out = $this->written();
        $this->assertStringContainsString('Downloading:   0%|', $out);
        $this->assertStringContainsString('Next:  40%|####      | 4/10', $out);
        $bar->set_description(null);
        $this->assertSame('', $bar->desc);
        $this->assertStringContainsString('Next:  40%|####      | 4/10', (string) new Tqdm(total: 10, desc: 'Next', initial: 4, ascii: true, disable: false, file: fopen('php://memory', 'w')));
        $bar->close();
        $bar->close(); // idempotent
    }

    public function test_abandoned_iteration_closes_the_bar(): void
    {
        $bar = new Tqdm(iterable: [1, 2, 3], leave: false, file: $this->file);
        foreach ($bar as $x) {
            break;
        }
        unset($x);
        $bar->getIterator(); // not consumed
        $bar->close();
        $this->assertTrue($bar->disable);
        $this->assertStringEndsWith("\r", $this->written());
    }

    public function test_write_goes_to_stdout_by_default_static_and_instance(): void
    {
        ob_start();
        Tqdm::write('static line');
        $bar = new Tqdm(total: 2, file: $this->file, disable: true);
        $bar->write('instance line');
        Tqdm::write('no newline', end: '');
        Tqdm::write('to a file', file: $this->file);
        $out = ob_get_clean();
        $this->assertSame("static line\ninstance line\nno newline", $out);
        $this->assertSame("to a file\n", $this->written());
    }

    public function test_write_clears_and_redraws_the_bars_on_the_same_stream(): void
    {
        $bar = new Tqdm(total: 4, file: $this->file, ascii: true);
        Tqdm::write('log line', file: $this->file);
        $out = $this->written();
        // bar, blank line (clear), message, bar again
        $this->assertSame(1, preg_match_all('/\r {30,}\rlog line\n\r  0%/', $out), $out);
        $this->assertSame(2, substr_count($out, '0/4'));
        $bar->close();
    }

    public function test_undefined_method(): void
    {
        $this->expectException(\Error::class);
        Tqdm::nope();
    }

    public function test_logging_redirect_tqdm(): void
    {
        $h = new StreamHandler(Py::stdout());
        $h->setFormatter(new Formatter('%(levelname)s %(message)s'));
        $other = new StreamHandler($this->file);
        Logging::root()->handlers = [$h, $other];
        $logger = Logging::getLogger('ftq'.bin2hex(random_bytes(3)));
        $logger->setLevel(Logging::DEBUG);

        ob_start();
        $bar = new Tqdm(total: 3, file: Py::stdout(), ascii: true, leave: false);
        $result = Tqdm::logging_redirect_tqdm(function () use ($logger, $h) {
            // the console handler is gone, replaced by one that goes through Tqdm::write
            $handlers = Logging::root()->handlers;
            $this->assertCount(2, $handlers);
            $this->assertNotContains($h, $handlers);
            $logger->warning('inside %s', 'bar');
            return 42;
        });
        $bar->close();
        $captured = ob_get_clean();
        $this->assertSame(42, $result);
        $this->assertSame([$h, $other], Logging::root()->handlers, 'restored');
        // cleared the bar, wrote the line, drew the bar again
        $this->assertStringContainsString("WARNING inside bar\n", $captured);
        $this->assertSame(2, substr_count($captured, '  0%|'), 'drawn at the start, and again after the line');
        // the file handler is untouched
        rewind($this->file);
        $this->assertStringContainsString('inside bar', stream_get_contents($this->file));
    }

    public function test_logging_redirect_tqdm_restores_on_exception(): void
    {
        $h = new StreamHandler(Py::stdout());
        Logging::root()->handlers = [$h];
        try {
            Tqdm::logging_redirect_tqdm(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('exception expected');
        } catch (\RuntimeException) {
            $this->assertSame([$h], Logging::root()->handlers);
        }
    }

    public static function format_meter_cases(): array
    {
        return [
            [0, 10, 0.0, null, "", false, "  0%|          | 0/10 [00:00<?, ?it/s]"],
            [3, 10, 5.0, null, "abc", true, "abc:  30%|###       | 3/10 [00:05<00:11,  1.67s/it]"],
            [10, 10, 10.0, 80, "Waiting...", true, "Waiting...: 100%|###############################| 10/10 [00:10<00:00,  1.00it/s]"],
            [5, null, 12.5, null, "", false, "5it [00:12,  2.50s/it]"],
            [50, 100, 3661.0, 100, "x: ", true, "x:  50%|###########################5                           | 50/100 [1:01:01<1:01:01, 73.22s/it]"],
            [1, 3, 0.5, 0, "", false, " 33% 1/3 [00:00<00:01,  2.00it/s]"],
            [7, 7, 0.0, null, "", false, "100%|\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}| 7/7 [00:00<?, ?it/s]"],
            [2, 4, 90.0, null, "", false, " 50%|\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}     | 2/4 [01:30<01:30, 45.00s/it]"],
            [3, 10, 5.0, 60, "desc", false, "desc:  30%|\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{258d}            | 3/10 [00:05<00:11,  1.67s/it]"],
            [33, 100, 7.0, 70, "", false, " 33%|\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{2588}\u{258c}                     | 33/100 [00:07<00:14,  4.71it/s]"],
            [3.5, 10.0, 2.0, null, "", true, " 35%|###5      | 3.5/10.0 [00:02<00:03,  1.75it/s]"],
            [12, 10, 1.0, null, "", false, "12it [00:01, 12.00it/s]"],
            [0, 0, 1.0, null, "", false, "0it [00:01, ?it/s]"],
            [1, 1000, 0.001, 80, "Photos", true, "Photos:   0%|                                | 1/1000 [00:00<00:00, 1000.00it/s]"],
            [999, 1000, 100000.0, 50, "big", false, "big: 100%|\u{2589}| 999/1000 [27:46:40<01:40, 100.10s/it]"],
            [6, 8, 60.0, 30, "", true, " 75%|7| 6/8 [01:00<00:20, 10.0"],
            [4, 5, 3.0, 40, "\u{e9}\u{65e5}", false, "\u{e9}\u{65e5}:  80%|\u{258a}| 4/5 [00:03<00:00,  1.33it/s"],
            [2, 100, 0.0, 80, "", true, "  2%|#                                                  | 2/100 [00:00<?, ?it/s]"],
            [25, 100, 1.0, 20, "x", true, "x:  25%|2| 25/100 [0"],
        ];
    }

    public static function interval_cases(): array
    {
        return [
            [0, "00:00"],
            [5, "00:05"],
            [59, "00:59"],
            [60, "01:00"],
            [61, "01:01"],
            [3599, "59:59"],
            [3600, "1:00:00"],
            [3661, "1:01:01"],
            [86399, "23:59:59"],
            [100000, "27:46:40"],
            [5.9, "00:05"],
            [0.4, "00:00"],
        ];
    }
}
