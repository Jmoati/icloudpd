<?php

namespace Foundation;

/**
 * Python semantics where PHP differs: `str()`, `repr()`, `datetime.strftime`, `str.format`,
 * `str % args` and `json.dumps` (see PORTING.md).
 *
 * Data model used by the port:
 *  - `list` / `dict` / `tuple` are PHP arrays (list = `array_is_list`, dict = the rest); a tuple that must
 *    be told apart from a list is a `\SplFixedArray`;
 *  - an *empty* dict that must stay a dict (`repr`, `json.dumps`) is a `\stdClass` (an empty array is a list);
 *  - `datetime` is a `\DateTimeInterface`, always timezone-aware;
 *  - Python exceptions map to `\ValueError`, `\TypeError`, `\OutOfRangeException` (IndexError) and
 *    `\OutOfBoundsException` (KeyError).
 */
final class Py
{
    /** `sys.stdout`: `php://output`, so that `ob_start()` captures what a test redirects. */
    public static function stdout()
    {
        static $stdout = null;
        return $stdout ??= fopen('php://output', 'w');
    }

    /** Python truthiness: `bool(v)`. PHP differs for the string "0". */
    public static function truthy(mixed $v): bool
    {
        if (is_string($v)) {
            return $v !== '';
        }
        if ($v instanceof \Countable) {
            return count($v) > 0;
        }
        if ($v instanceof \stdClass) {
            return (array) $v !== [];
        }
        return (bool) $v;
    }

    // ------------------------------------------------------------------ str() / repr()

    /** `str(v)` */
    public static function str(mixed $v): string
    {
        return match (true) {
            $v === null => 'None',
            $v === true => 'True',
            $v === false => 'False',
            is_string($v) => $v,
            is_int($v) => (string) $v,
            is_float($v) => self::float_repr($v),
            $v instanceof \DateTimeInterface => self::datetime_str($v),
            $v instanceof \Throwable => $v->getMessage(),
            $v instanceof \Stringable => (string) $v,
            default => self::repr($v),
        };
    }

    /** `repr(v)` */
    public static function repr(mixed $v): string
    {
        if (is_string($v)) {
            return mb_check_encoding($v, 'UTF-8') ? self::str_repr($v) : self::bytes_repr($v);
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return '['.implode(', ', array_map(self::repr(...), $v)).']';
            }
            return self::dict_repr($v);
        }
        if ($v instanceof \stdClass) {
            return self::dict_repr((array) $v);
        }
        if ($v instanceof \SplFixedArray) {
            $items = array_map(self::repr(...), $v->toArray());
            return '('.implode(', ', $items).(count($items) === 1 ? ',' : '').')';
        }
        if ($v instanceof \Throwable) {
            return (new \ReflectionClass($v))->getShortName().'('.self::repr($v->getMessage()).')';
        }
        if (is_object($v)) {
            return '<'.$v::class.' object>';
        }
        return self::str($v);
    }

    /** `ascii(v)`: `repr()` with the non-ASCII characters escaped */
    public static function ascii(mixed $v): string
    {
        return preg_replace_callback('/[^\x00-\x7f]/u', fn ($m) => self::escape_codepoint(mb_ord($m[0])), self::repr($v)) ?? self::repr($v);
    }

    private static function dict_repr(array $v): string
    {
        $items = [];
        foreach ($v as $k => $x) {
            $items[] = self::repr($k).': '.self::repr($x);
        }
        return '{'.implode(', ', $items).'}';
    }

    private static function str_repr(string $s): string
    {
        // Python picks double quotes only when the string has a single quote and no double quote
        $q = (str_contains($s, "'") && !str_contains($s, '"')) ? '"' : "'";
        $out = preg_replace_callback('/[\\\\'.$q.']|\p{C}|(?!\x20)\p{Z}/u', function ($m) {
            return match ($m[0]) {
                '\\' => '\\\\',
                "'" => "\\'",
                '"' => '\\"',
                "\t" => '\\t',
                "\n" => '\\n',
                "\r" => '\\r',
                default => self::escape_codepoint(mb_ord($m[0])),
            };
        }, $s);
        return $q.$out.$q;
    }

    private static function bytes_repr(string $s): string
    {
        // PHP strings are also Python `bytes`: not valid UTF-8 means b'...'
        $q = (str_contains($s, "'") && !str_contains($s, '"')) ? '"' : "'";
        $out = '';
        foreach (str_split($s) as $c) {
            $o = ord($c);
            $out .= match (true) {
                $c === '\\' => '\\\\',
                $c === $q => '\\'.$q,
                $c === "\t" => '\\t',
                $c === "\n" => '\\n',
                $c === "\r" => '\\r',
                $o < 0x20 || $o >= 0x7f => sprintf('\\x%02x', $o),
                default => $c,
            };
        }
        return 'b'.$q.$out.$q;
    }

    private static function escape_codepoint(int $cp): string
    {
        return match (true) {
            $cp < 0x100 => sprintf('\\x%02x', $cp),
            $cp < 0x10000 => sprintf('\\u%04x', $cp),
            default => sprintf('\\U%08x', $cp),
        };
    }

    /** `repr(float)`: shortest round-trip digits, `1.0`, `1e+16`, `1e-05`, `inf`, `nan` */
    private static function float_repr(float $f): string
    {
        if (is_nan($f)) {
            return 'nan';
        }
        if (is_infinite($f)) {
            return $f > 0 ? 'inf' : '-inf';
        }
        if ($f == 0.0) {
            return fdiv(1.0, $f) < 0 ? '-0.0' : '0.0';
        }
        $sign = $f < 0 ? '-' : '';
        // shortest digits that round-trip (zend_dtoa mode 0, as Python's float repr), whatever php.ini says
        $old = ini_set('serialize_precision', '-1');
        $s = json_encode(abs($f));
        if ($old !== false) {
            ini_set('serialize_precision', $old);
        }
        preg_match('/^(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/', $s, $m);
        $digits = $m[1].($m[2] ?? '');
        $decpt = strlen($m[1]) + (int) ($m[3] ?? 0);
        $stripped = ltrim($digits, '0');
        $decpt -= strlen($digits) - strlen($stripped);
        $digits = rtrim($stripped, '0');
        $n = strlen($digits);
        if ($decpt <= -4 || $decpt > 16) {
            $exp = $decpt - 1;
            return $sign.$digits[0].($n > 1 ? '.'.substr($digits, 1) : '').sprintf('e%s%02d', $exp < 0 ? '-' : '+', abs($exp));
        }
        if ($decpt <= 0) {
            return $sign.'0.'.str_repeat('0', -$decpt).$digits;
        }
        if ($decpt >= $n) {
            return $sign.$digits.str_repeat('0', $decpt - $n).'.0';
        }
        return $sign.substr($digits, 0, $decpt).'.'.substr($digits, $decpt);
    }

    /** `str(datetime)`: `2018-07-01 03:04:05.000678+02:00` */
    private static function datetime_str(\DateTimeInterface $dt): string
    {
        $us = (int) $dt->format('u');
        $o = $dt->getOffset();
        $a = abs($o);
        return $dt->format('Y-m-d H:i:s').($us ? sprintf('.%06d', $us) : '')
            .sprintf('%s%02d:%02d', $o < 0 ? '-' : '+', intdiv($a, 3600), intdiv($a % 3600, 60)).($a % 60 ? sprintf(':%02d', $a % 60) : '');
    }

    // ------------------------------------------------------------------ strftime

    /**
     * `datetime.strftime(fmt)` / `time.strftime(fmt, struct_time)`.
     *
     * Names (`%a %A %b %B %p`) and `%c %x %X` follow the current `LC_TIME` locale (`setlocale()`), like
     * Python after `locale.setlocale(LC_ALL, "")`. An unknown code prints the code character, as the C
     * library of macOS does.
     */
    public static function strftime(\DateTimeInterface $dt, string $fmt): string
    {
        $out = '';
        $n = strlen($fmt);
        for ($i = 0; $i < $n; $i++) {
            $c = $fmt[$i];
            if ($c !== '%') {
                $out .= $c;
                continue;
            }
            if (++$i >= $n) {
                $out .= '%';
                break;
            }
            $c = $fmt[$i];
            $nopad = false;
            if ($c === '-' && $i + 1 < $n) {
                // glibc / macOS flag: no padding (%-d)
                $nopad = true;
                $c = $fmt[++$i];
            }
            if (($c === 'E' || $c === 'O') && $i + 1 < $n && ctype_alpha($fmt[$i + 1])) {
                // alternative era / digits modifiers: nothing to do in the C locale
                $c = $fmt[++$i];
            }
            $s = self::strftime_code($dt, $c);
            if ($nopad) {
                $s = ltrim($s, '0 ');
                $s = $s === '' ? '0' : $s;
            }
            $out .= $s;
        }
        return $out;
    }

    private static function strftime_code(\DateTimeInterface $dt, string $c): string
    {
        $w = (int) $dt->format('w'); // 0 = Sunday
        $yday = (int) $dt->format('z');
        $H = (int) $dt->format('G');
        $h12 = $H % 12 === 0 ? 12 : $H % 12;
        $year = (int) $dt->format('Y');
        return match ($c) {
            'a' => self::langinfo('ABDAY_', $w + 1, ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][$w]),
            'A' => self::langinfo('DAY_', $w + 1, ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$w]),
            'b', 'h' => self::langinfo('ABMON_', (int) $dt->format('n'), $dt->format('M')),
            'B' => self::langinfo('MON_', (int) $dt->format('n'), $dt->format('F')),
            'c' => self::strftime($dt, self::langinfo_const('D_T_FMT', '%a %b %e %H:%M:%S %Y')),
            'C' => sprintf('%02d', intdiv($year, 100)),
            'd' => $dt->format('d'),
            'D' => self::strftime($dt, '%m/%d/%y'),
            'e' => sprintf('%2d', (int) $dt->format('j')),
            'f' => $dt->format('u'),
            'F' => self::strftime($dt, '%Y-%m-%d'),
            'g' => sprintf('%02d', ((int) $dt->format('o')) % 100),
            'G' => sprintf('%04d', (int) $dt->format('o')),
            'H' => $dt->format('H'),
            'I' => sprintf('%02d', $h12),
            'j' => sprintf('%03d', $yday + 1),
            'k' => sprintf('%2d', $H),
            'l' => sprintf('%2d', $h12),
            'm' => $dt->format('m'),
            'M' => $dt->format('i'),
            'n' => "\n",
            'p' => self::langinfo_const($H < 12 ? 'AM_STR' : 'PM_STR', $H < 12 ? 'AM' : 'PM'),
            'r' => self::strftime($dt, '%I:%M:%S %p'),
            'R' => self::strftime($dt, '%H:%M'),
            's' => (string) $dt->getTimestamp(),
            'S' => $dt->format('s'),
            't' => "\t",
            'T' => self::strftime($dt, '%H:%M:%S'),
            'u' => (string) ($w === 0 ? 7 : $w),
            'U' => sprintf('%02d', intdiv($yday + 7 - $w, 7)),
            'V' => $dt->format('W'),
            'w' => (string) $w,
            'W' => sprintf('%02d', intdiv($yday + 7 - (($w + 6) % 7), 7)),
            'x' => self::strftime($dt, self::langinfo_const('D_FMT', '%m/%d/%y')),
            'X' => self::strftime($dt, self::langinfo_const('T_FMT', '%H:%M:%S')),
            'y' => sprintf('%02d', $year % 100),
            'Y' => sprintf('%04d', $year),
            'z' => self::utc_offset($dt, false),
            'Z' => self::tz_name($dt),
            '%' => '%',
            default => $c,
        };
    }

    /** `nl_langinfo(<prefix><index>)`, with an English fallback where the platform has no such constant */
    private static function langinfo(string $prefix, int $index, string $fallback): string
    {
        return defined($prefix.$index) ? nl_langinfo(constant($prefix.$index)) : $fallback;
    }

    private static function langinfo_const(string $name, string $fallback): string
    {
        return defined($name) ? nl_langinfo(constant($name)) : $fallback;
    }

    /** `+HHMM` (`strftime`) or `+HH:MM` (`str(datetime)`), seconds only when not zero */
    private static function utc_offset(\DateTimeInterface $dt, bool $colon): string
    {
        $o = $dt->getOffset();
        $a = abs($o);
        $sep = $colon ? ':' : '';
        return sprintf('%s%02d%s%02d', $o < 0 ? '-' : '+', intdiv($a, 3600), $sep, intdiv($a % 3600, 60)).($a % 60 ? sprintf('%s%02d', $sep, $a % 60) : '');
    }

    /** `tzname()`: `UTC`, `CEST`, and `UTC+02:00` for a fixed offset as `datetime.timezone` does */
    private static function tz_name(\DateTimeInterface $dt): string
    {
        $t = $dt->format('T');
        if ($t === 'Z') {
            return 'UTC';
        }
        if ($t[0] === '+' || $t[0] === '-') {
            return $dt->getOffset() === 0 ? 'UTC' : 'UTC'.self::utc_offset($dt, true);
        }
        return $t;
    }

    // ------------------------------------------------------------------ str.format

    /**
     * `fmt.format(*args, **kwargs)`: `{}`, `{0}`, `{name}`, `{0.year}`, `{0[1]}`, `{!r}` and the format
     * spec, which for a datetime is a `strftime` format (`{:%Y/%m/%d}`). Keyword arguments are PHP named
     * arguments: `Py::format('{a}', a: 1)`.
     */
    public static function format(string $fmt, mixed ...$args): string
    {
        $state = ['auto' => 0, 'mode' => null];
        return self::vformat($fmt, $args, $state);
    }

    private static function vformat(string $fmt, array $args, array &$state): string
    {
        $out = '';
        $n = strlen($fmt);
        $i = 0;
        while ($i < $n) {
            $c = $fmt[$i];
            if ($c === '{') {
                if ($i + 1 < $n && $fmt[$i + 1] === '{') {
                    $out .= '{';
                    $i += 2;
                    continue;
                }
                if ($i + 1 >= $n) {
                    throw new \ValueError("Single '{' encountered in format string");
                }
                $depth = 1;
                $j = $i + 1;
                while ($j < $n && $depth > 0) {
                    $depth += $fmt[$j] === '{' ? 1 : ($fmt[$j] === '}' ? -1 : 0);
                    $j++;
                }
                if ($depth > 0) {
                    throw new \ValueError("expected '}' before end of string");
                }
                $out .= self::format_field(substr($fmt, $i + 1, $j - $i - 2), $args, $state);
                $i = $j;
            } elseif ($c === '}') {
                if ($i + 1 < $n && $fmt[$i + 1] === '}') {
                    $out .= '}';
                    $i += 2;
                    continue;
                }
                throw new \ValueError("Single '}' encountered in format string");
            } else {
                $out .= $c;
                $i++;
            }
        }
        return $out;
    }

    private static function format_field(string $field, array $args, array &$state): string
    {
        // field_name [ "!" conversion ] [ ":" format_spec ]; ':' and '!' inside [...] belong to the name
        $name = $field;
        $conv = null;
        $spec = '';
        $in_bracket = false;
        for ($k = 0, $len = strlen($field); $k < $len; $k++) {
            $ch = $field[$k];
            if ($ch === '[') {
                $in_bracket = true;
            } elseif ($ch === ']') {
                $in_bracket = false;
            } elseif (!$in_bracket && ($ch === ':' || $ch === '!')) {
                $name = substr($field, 0, $k);
                if ($ch === '!') {
                    if ($k + 1 >= $len) {
                        throw new \ValueError('end of string while looking for conversion specifier');
                    }
                    $conv = $field[$k + 1];
                    if ($k + 2 < $len && $field[$k + 2] !== ':') {
                        throw new \ValueError("expected ':' after conversion specifier");
                    }
                    $spec = (string) substr($field, $k + 3);
                } else {
                    $spec = (string) substr($field, $k + 1);
                }
                break;
            }
        }

        preg_match('/^([^.\[]*)(.*)$/s', $name, $m);
        [$first, $rest] = [$m[1], $m[2]];
        if ($first === '') {
            if ($state['mode'] === 'manual') {
                throw new \ValueError('cannot switch from manual field specification to automatic field numbering');
            }
            $state['mode'] = 'auto';
            $key = $state['auto']++;
        } elseif (ctype_digit($first)) {
            if ($state['mode'] === 'auto') {
                throw new \ValueError('cannot switch from automatic field numbering to manual field specification');
            }
            $state['mode'] = 'manual';
            $key = (int) $first;
        } else {
            $key = $first;
        }
        if (!array_key_exists($key, $args)) {
            throw is_int($key)
                ? new \OutOfRangeException("Replacement index $key out of range for positional args tuple")
                : new \OutOfBoundsException(self::repr($key));
        }
        $obj = $args[$key];

        while ($rest !== '') {
            if (!preg_match('/^(?:\.([^.\[]+)|\[([^\]]*)\])/', $rest, $m)) {
                throw new \ValueError('Only \'.\' or \'[\' may follow \']\' in format field specifier');
            }
            $rest = (string) substr($rest, strlen($m[0]));
            if (($m[1] ?? '') !== '') {
                $obj = self::getattr($obj, $m[1]);
            } else {
                $idx = ctype_digit($m[2]) ? (int) $m[2] : $m[2];
                if ((!is_array($obj) && !$obj instanceof \ArrayAccess) || !(is_array($obj) ? array_key_exists($idx, $obj) : $obj->offsetExists($idx))) {
                    throw is_int($idx) ? new \OutOfRangeException('index out of range') : new \OutOfBoundsException(self::repr($idx));
                }
                $obj = $obj[$idx];
            }
        }

        if ($conv !== null) {
            $obj = match ($conv) {
                's' => self::str($obj),
                'r' => self::repr($obj),
                'a' => self::ascii($obj),
                default => throw new \ValueError("Unknown conversion specifier $conv"),
            };
        }
        if (str_contains($spec, '{')) {
            $spec = self::vformat($spec, $args, $state);
        }
        return self::format_spec($obj, $spec);
    }

    private static function getattr(mixed $obj, string $attr): mixed
    {
        if ($obj instanceof \DateTimeInterface) {
            $f = ['year' => 'Y', 'month' => 'n', 'day' => 'j', 'hour' => 'G', 'minute' => 'i', 'second' => 's', 'microsecond' => 'u'];
            if (isset($f[$attr])) {
                return (int) $obj->format($f[$attr]);
            }
        } elseif (is_object($obj) && property_exists($obj, $attr)) {
            return $obj->$attr;
        }
        throw new \Error("'".(is_object($obj) ? $obj::class : get_debug_type($obj))."' object has no attribute '$attr'");
    }

    /** `format(value, spec)` */
    private static function format_spec(mixed $v, string $spec): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $spec === '' ? self::str($v) : self::strftime($v, $spec);
        }
        if ($spec === '') {
            return self::str($v);
        }
        $re = '/^(?:(.)?([<>=^]))?([-+ ])?(z)?(#)?(0)?(\d+)?([,_])?(?:\.(\d+))?([a-zA-Z%])?$/su';
        if (!preg_match($re, $spec, $m, PREG_UNMATCHED_AS_NULL)) {
            throw new \ValueError('Invalid format specifier');
        }
        $m += array_fill(0, 11, null);
        [, $fill, $align, $sign, , $alt, $zero, $width, $group, $prec, $type] = $m;
        $prec = $prec === null ? null : (int) $prec;
        $width = (int) $width;
        $is_int = is_int($v) || is_bool($v);
        if (!is_string($v) && !$is_int && !is_float($v)) {
            throw new \TypeError('unsupported format string passed to '.get_debug_type($v).'.__format__');
        }
        $type ??= is_string($v) ? 's' : ($is_int ? 'd' : '');
        if ($zero !== null && $align === null) {
            $fill = '0';
            $align = '=';
        }
        $fill ??= ' ';

        if (is_string($v)) {
            if ($type !== 's') {
                throw new \ValueError("Unknown format code '$type' for object of type 'str'");
            }
            $body = $prec === null ? $v : mb_substr($v, 0, $prec);
            return self::align($body, $width, $fill, $align ?? '<');
        }

        if ($type === 'c') {
            return self::align(mb_chr((int) $v), $width, $fill, $align ?? '<');
        }
        $neg = $v < 0 || (is_float($v) && $v == 0.0 && fdiv(1.0, $v) < 0);
        $abs = is_float($v) ? abs($v) : abs((int) $v);
        $prefix = '';
        if (in_array($type, ['d', 'n', 'b', 'o', 'x', 'X'], true)) {
            if (is_float($v)) {
                throw new \ValueError("Unknown format code '$type' for object of type 'float'");
            }
            $body = match ($type) {
                'b' => decbin($abs),
                'o' => decoct($abs),
                'x' => dechex($abs),
                'X' => strtoupper(dechex($abs)),
                default => (string) $abs,
            };
            $prefix = $alt !== null ? ['b' => '0b', 'o' => '0o', 'x' => '0x', 'X' => '0X'][$type] ?? '' : '';
        } elseif ($type === '%') {
            $body = self::float_body((float) $abs * 100, 'f', $prec ?? 6).'%';
        } elseif ($type === '') {
            // ponytail: with a precision, Python's "no type" float format is not exactly 'g'
            $body = $prec === null ? self::float_repr((float) $abs) : self::float_body((float) $abs, 'g', $prec);
        } elseif (str_contains('eEfFgG', $type)) {
            $body = self::float_body((float) $abs, $type, $prec ?? 6);
        } else {
            throw new \ValueError("Unknown format code '$type' for object of type '".(is_float($v) ? 'float' : 'int')."'");
        }
        if ($group !== null && in_array($type, ['d', 'n', 'f', 'F', 'g', 'G', ''], true)) {
            $body = preg_replace_callback('/^\d+/', fn ($d) => strrev(implode($group, str_split(strrev($d[0]), 3))), $body);
        }
        $s = $neg ? '-' : ($sign === '+' ? '+' : ($sign === ' ' ? ' ' : ''));
        return self::align($body, $width, $fill, $align ?? '>', $s.$prefix);
    }

    /** Pads `$prefix.$body` to `$width` characters; `=` puts the padding between prefix and body */
    private static function align(string $body, int $width, string $fill, string $align, string $prefix = ''): string
    {
        $pad = max(0, $width - mb_strlen($prefix.$body));
        return match ($align) {
            '<' => $prefix.$body.str_repeat($fill, $pad),
            '^' => str_repeat($fill, intdiv($pad, 2)).$prefix.$body.str_repeat($fill, $pad - intdiv($pad, 2)),
            '=' => $prefix.str_repeat($fill, $pad).$body,
            default => str_repeat($fill, $pad).$prefix.$body,
        };
    }

    // ------------------------------------------------------------------ str % args

    /**
     * `fmt % args`: `%s %r %a %d %i %u %o %x %X %e %E %f %F %g %G %c %%`, the `- + space # 0` flags, width and
     * precision (also `*`), and `%(name)s` when `$args` is a dict. `$args` is the tuple of arguments; a
     * list as a single argument is therefore `[[1, 2]]`.
     */
    public static function mod_format(string $fmt, array $args): string
    {
        $dict = !array_is_list($args);
        $pos = $dict ? [$args] : $args; // a mapping alone is the single argument of a plain `%s`
        $argi = 0;
        $out = '';
        $n = strlen($fmt);
        $i = 0;
        $next = function () use (&$argi, $pos) {
            if ($argi >= count($pos)) {
                throw new \TypeError('not enough arguments for format string');
            }
            return $pos[$argi++];
        };
        while (($p = strpos($fmt, '%', $i)) !== false) {
            $out .= substr($fmt, $i, $p - $i);
            $i = $p + 1;
            $key = null;
            if ($i < $n && $fmt[$i] === '(') {
                $depth = 1;
                $j = $i + 1;
                while ($j < $n && $depth > 0) {
                    $depth += $fmt[$j] === '(' ? 1 : ($fmt[$j] === ')' ? -1 : 0);
                    $j++;
                }
                if ($depth > 0) {
                    throw new \ValueError('incomplete format key');
                }
                if (!$dict) {
                    throw new \TypeError('format requires a mapping');
                }
                $key = substr($fmt, $i + 1, $j - $i - 2);
                $i = $j;
            }
            $flags = '';
            while ($i < $n && str_contains('-+ #0', $fmt[$i])) {
                $flags .= $fmt[$i++];
            }
            $width = 0;
            if ($i < $n && $fmt[$i] === '*') {
                $width = (int) $next();
                if ($width < 0) {
                    $flags .= '-';
                    $width = -$width;
                }
                $i++;
            } elseif (preg_match('/\d+/A', $fmt, $m, 0, $i)) {
                $width = (int) $m[0];
                $i += strlen($m[0]);
            }
            $prec = null;
            if ($i < $n && $fmt[$i] === '.') {
                $i++;
                if ($i < $n && $fmt[$i] === '*') {
                    $prec = max(0, (int) $next());
                    $i++;
                } elseif (preg_match('/\d+/A', $fmt, $m, 0, $i)) {
                    $prec = (int) $m[0];
                    $i += strlen($m[0]);
                } else {
                    $prec = 0;
                }
            }
            while ($i < $n && str_contains('hlL', $fmt[$i])) {
                $i++;
            }
            if ($i >= $n) {
                throw new \ValueError('incomplete format');
            }
            $type = $fmt[$i++];
            if ($type === '%') {
                $out .= '%';
                continue;
            }
            if ($key !== null) {
                if (!array_key_exists($key, $args)) {
                    throw new \OutOfBoundsException(self::repr($key));
                }
                $arg = $args[$key];
            } else {
                $arg = $next();
            }
            $out .= self::mod_conv($type, $arg, $flags, $width, $prec, $i - 1);
        }
        $out .= substr($fmt, $i);
        if (!$dict && $argi < count($pos)) {
            throw new \TypeError('not all arguments converted during string formatting');
        }
        return $out;
    }

    private static function mod_conv(string $type, mixed $arg, string $flags, int $width, ?int $prec, int $index): string
    {
        $left = str_contains($flags, '-');
        $zero = str_contains($flags, '0') && !$left;
        $plus = str_contains($flags, '+') ? '+' : (str_contains($flags, ' ') ? ' ' : '');
        $alt = str_contains($flags, '#');
        if (in_array($type, ['s', 'r', 'a'], true)) {
            $s = match ($type) {
                's' => self::str($arg),
                'r' => self::repr($arg),
                default => self::ascii($arg),
            };
            return self::pad_field('', $prec === null ? $s : mb_substr($s, 0, $prec), $width, $left, false);
        }
        if ($type === 'c') {
            $c = is_string($arg) && mb_strlen($arg) === 1 ? $arg : (is_int($arg) ? mb_chr($arg) : throw new \TypeError('%c requires int or char'));
            return self::pad_field('', $c, $width, $left, false);
        }
        if (!is_int($arg) && !is_float($arg) && !is_bool($arg)) {
            throw new \TypeError("%$type format: a real number is required, not ".get_debug_type($arg));
        }
        if (str_contains('diuoxX', $type)) {
            if (is_float($arg) && (is_nan($arg) || is_infinite($arg))) {
                throw new \ValueError('cannot convert float '.self::float_repr($arg).' to integer');
            }
            $v = (int) $arg;
            $abs = abs($v);
            $body = match ($type) {
                'o' => decoct($abs),
                'x' => dechex($abs),
                'X' => strtoupper(dechex($abs)),
                default => (string) $abs,
            };
            if ($prec !== null) {
                $body = str_pad($body, $prec, '0', STR_PAD_LEFT);
            }
            $prefix = $alt ? ['o' => '0o', 'x' => '0x', 'X' => '0X'][$type] ?? '' : '';
            $sign = ($v < 0 ? '-' : $plus).$prefix;
        } elseif (str_contains('eEfFgG', $type)) {
            $v = (float) $arg;
            $neg = $v < 0 || ($v == 0.0 && fdiv(1.0, $v) < 0);
            $body = self::float_body(abs($v), $type, $prec ?? 6);
            $sign = $neg ? '-' : $plus;
            $zero = $zero && is_finite($v);
        } else {
            throw new \ValueError(sprintf("unsupported format character '%s' (0x%x) at index %d", $type, ord($type), $index));
        }
        return self::pad_field($sign, $body, $width, $left, $zero);
    }

    private static function pad_field(string $sign, string $body, int $width, bool $left, bool $zero): string
    {
        $fill = $width - mb_strlen($sign.$body);
        if ($fill <= 0) {
            return $sign.$body;
        }
        if ($left) {
            return $sign.$body.str_repeat(' ', $fill);
        }
        return $zero ? $sign.str_repeat('0', $fill).$body : str_repeat(' ', $fill).$sign.$body;
    }

    /** Digits of `abs($v)` for the float conversions `e E f F g G` (no sign) */
    private static function float_body(float $v, string $type, int $prec): string
    {
        $upper = ctype_upper($type);
        if (is_nan($v) || is_infinite($v)) {
            $s = is_nan($v) ? 'nan' : 'inf';
        } elseif (strtolower($type) === 'f') {
            $s = sprintf('%.'.$prec.'f', $v);
        } elseif (strtolower($type) === 'e') {
            $s = self::exp_fix(sprintf('%.'.$prec.'e', $v));
        } else {
            $p = $prec === 0 ? 1 : $prec;
            $e = sprintf('%.'.($p - 1).'e', $v);
            $x = (int) substr($e, strpos($e, 'e') + 1);
            $s = ($x < -4 || $x >= $p) ? self::exp_fix($e) : sprintf('%.'.($p - 1 - $x).'f', $v);
            // %g drops the trailing zeros
            [$mant, $exp] = array_pad(explode('e', $s, 2), 2, null);
            if (str_contains($mant, '.')) {
                $mant = rtrim(rtrim($mant, '0'), '.');
            }
            $s = $mant.($exp === null ? '' : 'e'.$exp);
        }
        return $upper ? strtoupper($s) : $s;
    }

    /** PHP prints `1.0e+5`, C and Python print `1.0e+05` */
    private static function exp_fix(string $s): string
    {
        return preg_replace('/e([+-])(\d)$/', 'e${1}0$2', $s);
    }

    // ------------------------------------------------------------------ json.dumps

    /**
     * `json.dumps(data, ensure_ascii=…, indent=…)`, byte for byte: separators `", "` and `": "` (`","` and
     * `": "` with an indent), `ensure_ascii`, floats as Python prints them, keys in insertion order.
     * A `list` is a PHP list, a `dict` an associative array, an empty `dict` a `\stdClass`, a tuple a `\SplFixedArray`.
     */
    public static function json_dumps(mixed $data, bool $ensure_ascii = true, ?int $indent = null): string
    {
        return self::json_enc($data, $ensure_ascii, $indent, 0);
    }

    private static function json_enc(mixed $v, bool $ascii, ?int $indent, int $level): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_nan($v) ? 'NaN' : (is_infinite($v) ? ($v > 0 ? 'Infinity' : '-Infinity') : self::float_repr($v));
        }
        if (is_string($v)) {
            // JsonException on malformed UTF-8: a Python str cannot hold it
            return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | ($ascii ? 0 : JSON_UNESCAPED_UNICODE));
        }
        if ($v instanceof \SplFixedArray) {
            $v = $v->toArray();
        } elseif ($v instanceof \ArrayObject) {
            $v = $v->getArrayCopy();
        }
        $is_dict = $v instanceof \stdClass;
        if ($is_dict) {
            $v = (array) $v;
        } elseif (!is_array($v)) {
            throw new \TypeError('Object of type '.get_debug_type($v).' is not JSON serializable');
        } else {
            $is_dict = !array_is_list($v);
        }
        if ($v === []) {
            return $is_dict ? '{}' : '[]';
        }
        $items = [];
        foreach ($v as $k => $x) {
            $s = self::json_enc($x, $ascii, $indent, $level + 1);
            $items[] = $is_dict ? self::json_enc((string) $k, $ascii, null, 0).': '.$s : $s;
        }
        [$open, $close] = $is_dict ? ['{', '}'] : ['[', ']'];
        if ($indent === null) {
            return $open.implode(', ', $items).$close;
        }
        $nl = "\n".str_repeat(' ', $indent * ($level + 1));
        return $open.$nl.implode(','.$nl, $items)."\n".str_repeat(' ', $indent * $level).$close;
    }
}
