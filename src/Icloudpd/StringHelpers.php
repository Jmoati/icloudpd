<?php

namespace Icloudpd;

/** String helper functions */
final class StringHelpers
{
    /** Truncates a string to a maximum length, inserting "..." in the middle */
    public static function truncate_middle(string $string, int $length): string
    {
        if (mb_strlen($string) <= $length) {
            return $string;
        }
        if ($length < 0) {
            throw new \ValueError('n must be greater than or equal to 1');
        }
        if ($length <= 3) {
            return mb_substr('...', 0, $length);
        }
        $end_length = intdiv($length, 2) - 2;
        $start_length = $length - $end_length - 4;
        $end_length = max($end_length, 1);
        return mb_substr($string, 0, $start_length) . '...' . mb_substr($string, -$end_length);
    }

    /**
     * Parses timedelta days. null if non-parsable
     * >>> parse_timedelta("2d")
     * datetime.timedelta(days=2)
     * >>> parse_timedelta("2d")
     * datetime.timedelta(days=2)
     */
    public static function parse_timedelta(string $formatted): ?\DateInterval
    {
        // re.match: anchored at the start only
        if (preg_match('/^([0-9]+)([dD]{1})/', $formatted, $m)) {
            return new \DateInterval('P' . $m[1] . 'D');
        }
        return null;
    }

    /**
     * Parses ISO timestamp. null it non-parsable
     * >>> parse_timestamp("2025-01-02")
     * datetime.datetime(2025, 1, 2, 0, 0)
     *
     * `datetime.fromisoformat` of Python 3.13 (`_pydatetime.py`), a timestamp without a time zone being
     * in the local one (PHP has no naive datetime, see PORTING.md).
     * ponytail: no ISO week dates (2025-W01-1), no time zone offsets with seconds.
     */
    public static function parse_timestamp(string $formatted): ?\DateTimeImmutable
    {
        try {
            return self::fromisoformat($formatted);
        } catch (\Throwable) {
            return null;
        }
    }

    /** parses ISO datetime and time interval params. Returns null if non-parsable */
    public static function parse_timestamp_or_timedelta(string $formatted): \DateTimeImmutable|\DateInterval|null
    {
        $p1 = self::parse_timedelta($formatted);
        if ($p1 === null) {
            return self::parse_timestamp($formatted);
        }
        return $p1;
    }

    /** @return list<string> */
    public static function splitlines(string $inp): array
    {
        // str.splitlines(): \n, \r, \r\n, \v, \f, \x1c-\x1e, \x85,   and   end a line
        $lines = preg_split('/\r\n|[\n\r\x0b\x0c\x1c-\x1e\x{85}\x{2028}\x{2029}]/u', $inp);
        if ($lines === false) {
            return [$inp];
        }
        // a final line ending does not start a line
        if (end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    private static function fromisoformat(string $date_string): \DateTimeImmutable
    {
        if (strlen($date_string) < 7) {
            throw new \ValueError("Invalid isoformat string: '{$date_string}'");
        }

        // Split this at the separator
        $separator_location = self::_find_isoformat_datetime_separator($date_string);
        $dstr = substr($date_string, 0, $separator_location);
        $tstr = substr($date_string, $separator_location + 1);

        [$year, $month, $day] = self::_parse_isoformat_date($dstr);

        if ($tstr !== '') {
            [$hour, $minute, $second, $microsecond, $tzi] = self::_parse_isoformat_time($tstr);
        } else {
            [$hour, $minute, $second, $microsecond, $tzi] = [0, 0, 0, 0, null];
        }

        // datetime(...) validates its fields
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59 || $microsecond > 999999) {
            throw new \ValueError('datetime field out of range');
        }
        return new \DateTimeImmutable(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d.%06d', $year, $month, $day, $hour, $minute, $second, $microsecond),
            $tzi ?? new \DateTimeZone(date_default_timezone_get()),
        );
    }

    private static function _is_ascii_digit(string $c): bool
    {
        return $c !== '' && str_contains('0123456789', $c);
    }

    /** `int(string)` for a string of ASCII digits only */
    private static function _int(string $digits): int
    {
        if (!ctype_digit($digits)) {
            throw new \ValueError("invalid literal for int(): '{$digits}'");
        }
        return (int) $digits;
    }

    private static function _find_isoformat_datetime_separator(string $dtstr): int
    {
        $len_dtstr = strlen($dtstr);
        if ($len_dtstr == 7) {
            return 7;
        }

        // See the comment in _datetimemodule.c:_find_isoformat_datetime_separator
        if ($dtstr[4] === '-') {
            if ($dtstr[5] === 'W') {
                throw new \ValueError('ISO week dates are not supported');
            }
            // YYYY-MM-DD (10)
            return 10;
        } else {
            if ($dtstr[4] === 'W') {
                throw new \ValueError('ISO week dates are not supported');
            }
            // YYYYMMDD (8)
            return 8;
        }
    }

    /** @return array{int, int, int} */
    private static function _parse_isoformat_date(string $dtstr): array
    {
        // It is assumed that this is an ASCII-only string of lengths 7, 8 or 10
        if (!in_array(strlen($dtstr), [7, 8, 10], true)) {
            throw new \ValueError('Invalid isoformat date');
        }
        $year = self::_int(substr($dtstr, 0, 4));
        $has_sep = $dtstr[4] === '-';

        $pos = 4 + (int) $has_sep;
        $month = self::_int(substr($dtstr, $pos, 2));
        $pos += 2;
        if ((substr($dtstr, $pos, 1) === '-') != $has_sep) {
            throw new \ValueError('Inconsistent use of dash separator');
        }

        $pos += (int) $has_sep;
        $day = self::_int(substr($dtstr, $pos, 2));

        return [$year, $month, $day];
    }

    private const _FRACTION_CORRECTION = [100000, 10000, 1000, 100, 10];

    /**
     * Parses things of the form HH[:?MM[:?SS[{.,}fff[fff]]]]
     *
     * @return array{int, int, int, int}
     */
    private static function _parse_hh_mm_ss_ff(string $tstr): array
    {
        $len_str = strlen($tstr);

        $time_comps = [0, 0, 0, 0];
        $pos = 0;
        $has_sep = false;
        for ($comp = 0; $comp < 3; $comp++) {
            if (($len_str - $pos) < 2) {
                throw new \ValueError('Incomplete time component');
            }

            $time_comps[$comp] = self::_int(substr($tstr, $pos, 2));

            $pos += 2;
            $next_char = substr($tstr, $pos, 1);

            if ($comp == 0) {
                $has_sep = $next_char === ':';
            }

            if ($next_char === '' || $comp >= 2) {
                break;
            }

            if ($has_sep && $next_char !== ':') {
                throw new \ValueError("Invalid time separator: {$next_char}");
            }

            $pos += (int) $has_sep;
        }

        if ($pos < $len_str) {
            if (!str_contains('.,', $tstr[$pos])) {
                throw new \ValueError('Invalid microsecond component');
            } else {
                $pos += 1;
                if (!self::_all_digits(substr($tstr, $pos))) {
                    throw new \ValueError('Non-digit values in fraction');
                }

                $len_remainder = $len_str - $pos;

                if ($len_remainder >= 6) {
                    $to_parse = 6;
                } else {
                    $to_parse = $len_remainder;
                }

                $time_comps[3] = self::_int(substr($tstr, $pos, $to_parse));
                if ($to_parse < 6) {
                    $time_comps[3] *= self::_FRACTION_CORRECTION[$to_parse - 1];
                }
            }
        }

        return $time_comps;
    }

    private static function _all_digits(string $s): bool
    {
        return $s === '' || ctype_digit($s);
    }

    /**
     * Format supported is HH[:MM[:SS[.fff[fff]]]][+HH:MM[:SS[.ffffff]]]
     *
     * @return array{int, int, int, int, ?\DateTimeZone}
     */
    private static function _parse_isoformat_time(string $tstr): array
    {
        $len_str = strlen($tstr);
        if ($len_str < 2) {
            throw new \ValueError('Isoformat time too short');
        }

        // This is equivalent to re.search('[+-Z]', tstr), but faster
        $tz_pos = (int) (strpos($tstr, '-') !== false ? strpos($tstr, '-') + 1
            : (strpos($tstr, '+') !== false ? strpos($tstr, '+') + 1
                : (strpos($tstr, 'Z') !== false ? strpos($tstr, 'Z') + 1 : 0)));
        $timestr = $tz_pos > 0 ? substr($tstr, 0, $tz_pos - 1) : $tstr;

        $time_comps = self::_parse_hh_mm_ss_ff($timestr);

        $tzi = null;
        if ($tz_pos == $len_str && $tstr[-1] === 'Z') {
            $tzi = new \DateTimeZone('UTC');
        } elseif ($tz_pos > 0) {
            $tzstr = substr($tstr, $tz_pos);

            // Valid time zone strings are:
            // HH                  len: 2
            // HHMM                len: 4
            // HH:MM               len: 5
            // HHMMSS              len: 6
            // HHMMSS.f+           len: 7+
            // HH:MM:SS            len: 8
            // HH:MM:SS.f+         len: 10+

            if (in_array(strlen($tzstr), [0, 1, 3], true)) {
                throw new \ValueError('Malformed time zone string');
            }

            $tz_comps = self::_parse_hh_mm_ss_ff($tzstr);

            if (!array_filter($tz_comps)) {
                $tzi = new \DateTimeZone('UTC');
            } else {
                $tzsign = $tstr[$tz_pos - 1] === '-' ? -1 : 1;

                // timezone(timedelta) must be strictly between -24h and 24h
                $seconds = $tz_comps[0] * 3600 + $tz_comps[1] * 60 + $tz_comps[2];
                if ($seconds >= 86400) {
                    throw new \ValueError('offset must be a timedelta strictly between -timedelta(hours=24) and timedelta(hours=24)');
                }
                $seconds *= $tzsign;
                // ponytail: the offset is kept to the minute
                $tzi = new \DateTimeZone(sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60)));
            }
        }

        $time_comps[] = $tzi;

        return $time_comps;
    }
}
