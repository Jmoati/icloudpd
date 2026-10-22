<?php

namespace Foundation;

use Foundation\Logging\LogRecord;
use Foundation\Logging\StreamHandler;

/**
 * The subset of `tqdm` that icloudpd uses: an iterable wrapped in a one-line progress bar
 * (`iterable`, `total`, `desc`, `leave`, `dynamic_ncols`, `ascii`, `disable`, `file`…), `update`,
 * `set_description`, `write`, `close`, `n`, `total`, and `logging_redirect_tqdm`.
 *
 * The bar goes to `$file` (STDERR by default, as `sys.stderr`); `Tqdm::write()` goes to stdout by default
 * (`Py::stdout()`, so that a test can capture it), and is both static and instance (`$bar->write(…)` is
 * the classmethod too): `@method static void write(string $s, $file = null, string $end = "\n")`.
 *
 * ponytail: one bar at a time (no `position`), no smoothing of the rate, no `unit_scale`, `bar_format`,
 * `postfix` or colours; the rate is the average since the start.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Tqdm implements \IteratorAggregate, \Stringable
{
    public const ASCII = ' 123456789#';
    public const UTF = ' ▏▎▍▌▋▊▉█';

    /** @var list<self> */
    private static array $_instances = [];

    public ?iterable $iterable;
    public int|float|null $total;
    public int|float $n;
    public string $desc;
    public bool $leave;
    public bool $disable;
    /** @var resource */
    public $fp;
    public ?int $ncols;
    public bool $dynamic_ncols;
    public bool|string $ascii;
    public string $unit;
    public float $mininterval;
    public float $start_t = 0.0;
    public float $last_print_t = 0.0;
    public int|float $last_print_n = 0;
    private int $last_len = 0;
    private int|float $initial;

    /**
     * @param resource|null $file the bar's output, STDERR by default
     * @param bool|string|null $ascii true for the ASCII bar characters " 123456789#", a string for custom ones
     */
    public function __construct(
        ?iterable $iterable = null,
        ?string $desc = null,
        int|float|null $total = null,
        bool $leave = true,
        $file = null,
        ?int $ncols = null,
        float $mininterval = 0.1,
        bool|string|null $ascii = null,
        bool $disable = false,
        string $unit = 'it',
        bool $dynamic_ncols = false,
        int|float $initial = 0,
    ) {
        $file ??= defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        $this->iterable = $iterable;
        $this->leave = $leave;
        $this->disable = $disable;
        $this->n = $initial;
        $this->initial = $initial;
        $this->fp = $file;
        $this->unit = $unit;
        $this->mininterval = $mininterval;
        $this->ascii = $ascii ?? false;
        $this->dynamic_ncols = $dynamic_ncols;
        $this->desc = $desc ?? '';
        if ($total === null && $iterable !== null && (is_array($iterable) || $iterable instanceof \Countable)) {
            $total = count($iterable);
        }
        if ($total === INF) {
            $total = null;
        }
        $this->total = $total;
        if ($disable) {
            $this->ncols = $ncols;
            return;
        }
        $this->ncols = $ncols ?? $this->screen_width();
        $this->start_t = $this->last_print_t = Time::time();
        $this->last_print_n = $initial;
        self::$_instances[] = $this;
        $this->refresh();
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Iterate over the iterable, updating the bar and closing it at the end (or when abandoned). */
    public function getIterator(): \Generator
    {
        $iterable = $this->iterable ?? [];
        if ($this->disable) {
            foreach ($iterable as $obj) {
                yield $obj;
            }
            return;
        }
        $n = $this->n;
        $last_print_n = $this->last_print_n;
        $last_print_t = $this->last_print_t;
        try {
            foreach ($iterable as $obj) {
                yield $obj;
                $n++;
                $cur_t = Time::time();
                if ($cur_t - $last_print_t >= $this->mininterval) {
                    $this->update($n - $last_print_n);
                    $last_print_n = $this->last_print_n;
                    $last_print_t = $this->last_print_t;
                }
            }
        } finally {
            $this->n = $n;
            $this->close();
        }
    }

    /** Manually update the progress bar, useful for streams such as reading files. */
    public function update(int|float $n = 1): void
    {
        if ($this->disable) {
            return;
        }
        $this->n += $n;
        $cur_t = Time::time();
        if ($cur_t - $this->last_print_t >= $this->mininterval) {
            $this->refresh();
            $this->last_print_n = $this->n;
            $this->last_print_t = $cur_t;
        }
    }

    /** Set/modify description of the progress bar. */
    public function set_description(?string $desc = null, bool $refresh = true): void
    {
        $this->desc = $desc ? $desc.': ' : '';
        if ($refresh) {
            $this->refresh();
        }
    }

    /** Force refresh the display of this bar. */
    public function refresh(): void
    {
        if ($this->disable) {
            return;
        }
        $this->display((string) $this);
    }

    /** Clear current bar display. */
    public function clear(): void
    {
        if (!$this->disable) {
            $this->display('');
            fwrite($this->fp, "\r"); // place cursor back at the beginning of line
        }
    }

    /** Cleanup and (if leave=False) close the progressbar. */
    public function close(): void
    {
        if ($this->disable) {
            return;
        }
        // Prevent multiple closures
        $this->disable = true;
        self::$_instances = array_values(array_filter(self::$_instances, fn ($i) => $i !== $this));
        if (!is_resource($this->fp)) { // at shutdown
            return;
        }
        if ($this->leave) {
            $this->display((string) $this);
            fwrite($this->fp, "\n");
        } else {
            // clear previous display
            $this->display('');
            fwrite($this->fp, "\r");
        }
        fflush($this->fp);
    }

    /** `\r` + the status, over the previous one */
    private function display(string $msg): void
    {
        $len = mb_strlen($msg);
        fwrite($this->fp, "\r".$msg.str_repeat(' ', max($this->last_len - $len, 0)));
        fflush($this->fp);
        $this->last_len = $len;
    }

    public function __toString(): string
    {
        return self::format_meter(
            $this->n,
            $this->total,
            Time::time() - $this->start_t,
            $this->ncols,
            $this->desc,
            $this->ascii,
            $this->unit,
            null,
            $this->initial,
        );
    }

    /**
     * Return a string-based progress bar given some parameters
     *
     * `{prefix}{percentage:3.0f}%|{bar}| {n}/{total} [{elapsed}<{remaining}, {rate}{unit}/s]`, or
     * `{n}{unit} [{elapsed}, {rate}{unit}/s]` when the total is unknown.
     */
    public static function format_meter(
        int|float $n,
        int|float|null $total,
        float $elapsed,
        ?int $ncols = null,
        string $prefix = '',
        bool|string $ascii = false,
        string $unit = 'it',
        ?float $rate = null,
        int|float $initial = 0,
    ): string {
        if ($total && $n >= $total + 0.5) { // allow float imprecision (#849)
            $total = null;
        }
        $elapsed_str = self::format_interval($elapsed);
        if ($rate === null && $elapsed) {
            $rate = ($n - $initial) / $elapsed;
        }
        $inv_rate = $rate ? 1 / $rate : null;
        $rate_noinv_fmt = ($rate ? sprintf('%5.2f', $rate) : '?').$unit.'/s';
        $rate_inv_fmt = ($inv_rate ? sprintf('%5.2f', $inv_rate) : '?').'s/'.$unit;
        $rate_fmt = $inv_rate && $inv_rate > 1 ? $rate_inv_fmt : $rate_noinv_fmt;
        $n_fmt = Py::str($n);
        $total_fmt = $total !== null ? Py::str($total) : '?';
        $remaining = $rate && $total ? ($total - $n) / $rate : 0;
        $remaining_str = $rate ? self::format_interval($remaining) : '?';
        $l_bar = $prefix === '' ? '' : (str_ends_with($prefix, ': ') ? $prefix : $prefix.': ');
        $r_bar = "| $n_fmt/$total_fmt [$elapsed_str<$remaining_str, $rate_fmt]";

        if ($total) {
            $frac = $n / $total;
            $l_bar .= sprintf('%3.0f%%|', $frac * 100);
            if ($ncols === 0) {
                return substr($l_bar, 0, -1).substr($r_bar, 1);
            }
            $bar_len = $ncols ? max(1, $ncols - mb_strwidth($l_bar.$r_bar)) : 10;
            $res = $l_bar.self::bar($frac, $bar_len, $ascii === true ? self::ASCII : ($ascii ?: self::UTF)).$r_bar;
            return $ncols ? mb_strimwidth($res, 0, $ncols) : $res; // disp_trim: wide characters take two columns
        }
        // no total: no progressbar, ETA, just progress stats
        return ($prefix !== '' ? $prefix.': ' : '')."$n_fmt$unit [$elapsed_str, $rate_fmt]";
    }

    /** `00:05`, `1:02:03` */
    public static function format_interval(int|float $t): string
    {
        [$mins, $s] = [intdiv((int) $t, 60), (int) $t % 60];
        [$h, $m] = [intdiv($mins, 60), $mins % 60];
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }

    /** The bar itself: `$frac` of `$width` characters, the last one drawn from the charset's partial symbols */
    private static function bar(float $frac, int $width, string $charset): string
    {
        $chars = mb_str_split($charset);
        $nsyms = count($chars) - 1;
        $total = (int) ($frac * $width * $nsyms);
        [$bar_length, $frac_bar_length] = [intdiv($total, $nsyms), $total % $nsyms];
        $res = str_repeat($chars[$nsyms], $bar_length);
        if ($bar_length < $width) { // whitespace padding
            $res .= $chars[$frac_bar_length].str_repeat($chars[0], $width - $bar_length - 1);
        }
        return mb_substr($res, 0, $width);
    }

    /** The width of the terminal when the bar goes to one, null otherwise (a 10 character bar) */
    private function screen_width(): ?int
    {
        // ponytail: tqdm asks the terminal with an ioctl; here `stty size` on the controlling terminal, once per
        // process (a resize is not followed), and nothing without a terminal
        static $cache = [];
        $key = (int) $this->fp;
        if (!array_key_exists($key, $cache)) {
            $size = stream_isatty($this->fp) && function_exists('shell_exec') ? @shell_exec('stty size </dev/tty 2>/dev/null') : null;
            $cache[$key] = $size !== null && preg_match('/^\d+ (\d+)/', $size, $m) ? (int) $m[1] : null;
        }
        return $cache[$key];
    }

    /** `tqdm.write(s, file=None, end="\n")` */
    private static function _write(string $s, $file = null, string $end = "\n"): void
    {
        $fp = $file ?? Py::stdout();
        // Clear instances that are in the target output file, or when both outputs are the terminal
        $console = [Py::stdout(), defined('STDOUT') ? STDOUT : null, defined('STDERR') ? STDERR : null];
        $cleared = [];
        foreach (self::$_instances as $inst) {
            if ($inst->fp === $fp || (in_array($fp, $console, true) && in_array($inst->fp, $console, true))) {
                $inst->clear();
                $cleared[] = $inst;
            }
        }
        fwrite($fp, $s.$end);
        fflush($fp);
        foreach ($cleared as $inst) {
            $inst->refresh();
        }
    }

    public function __call(string $name, array $args): void
    {
        if ($name !== 'write') {
            throw new \Error('Call to undefined method '.self::class."::$name()");
        }
        self::_write(...$args);
    }

    public static function __callStatic(string $name, array $args): void
    {
        if ($name !== 'write') {
            throw new \Error('Call to undefined method '.self::class."::$name()");
        }
        self::_write(...$args);
    }

    /**
     * `tqdm.contrib.logging.logging_redirect_tqdm(loggers)` as a function: runs `$body` with the
     * console handlers of the loggers (root by default) replaced by one that logs through `Tqdm::write`,
     * so that log lines do not break a bar, and returns what `$body` returns.
     *
     * @param list<Logging\Logger>|null $loggers
     */
    public static function logging_redirect_tqdm(callable $body, ?array $loggers = null): mixed
    {
        $loggers ??= [Logging::root()];
        $is_console = fn ($h) => $h instanceof StreamHandler
            && in_array($h->stream, [Py::stdout(), defined('STDOUT') ? STDOUT : null, defined('STDERR') ? STDERR : null], true);
        $original_handlers_list = array_map(fn ($logger) => $logger->handlers, $loggers);
        try {
            foreach ($loggers as $logger) {
                $tqdm_handler = new class extends StreamHandler {
                    public function emit(LogRecord $record): void
                    {
                        try {
                            Tqdm::write($this->format($record), file: $this->stream);
                            $this->flush();
                        } catch (\Throwable $e) {
                            $this->handleError($record, $e);
                        }
                    }
                };
                $orig_handler = null;
                foreach ($logger->handlers as $h) {
                    if ($is_console($h)) {
                        $orig_handler = $h;
                        break;
                    }
                }
                if ($orig_handler !== null) {
                    $tqdm_handler->setFormatter($orig_handler->formatter);
                    $tqdm_handler->setLevel($orig_handler->level);
                    $tqdm_handler->stream = $orig_handler->stream;
                }
                $logger->handlers = [...array_filter($logger->handlers, fn ($h) => !$is_console($h)), $tqdm_handler];
            }
            return $body();
        } finally {
            foreach ($loggers as $i => $logger) {
                $logger->handlers = $original_handlers_list[$i];
            }
        }
    }
}
