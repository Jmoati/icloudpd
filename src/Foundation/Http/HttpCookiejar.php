<?php

namespace Foundation\Http;

/**
 * Module-level functions of Python's ``http.cookiejar`` (HTTP cookie handling for web clients).
 *
 * Named HttpCookiejar because PHP class names are case-insensitive: ``Cookiejar`` would be ``CookieJar``.
 *
 * This module has (now fairly distant) origins in Gisle Aas' Perl module
 * HTTP::Cookies, from the libwww-perl library.
 *
 * ponytail: no MozillaCookieJar/MSIE jars and no time2netscape (nothing here reads or writes them).
 */
final class HttpCookiejar
{
    public const HTTPONLY_ATTR = 'HTTPOnly';
    public const HTTPONLY_PREFIX = '#HttpOnly_';
    public const DEFAULT_HTTP_PORT = '80';
    public const MISSING_FILENAME_TEXT = 'a filename was not supplied (nor was the CookieJar instance initialised with one)';

    public const EPOCH_YEAR = 1970;
    public const MONTHS_LOWER = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    public const UTC_ZONES = ['GMT', 'UTC', 'UT', 'Z'];

    // Characters in addition to A-Z, a-z, 0-9, '_', '.', and '-' that don't
    // need to be escaped to form a valid HTTP URL (RFCs 2396 and 1738).
    public const HTTP_PATH_SAFE = "%/;:@&=+$,!~*'()";

    private const STRICT_DATE_RE = '~^[SMTWF][a-z][a-z], (\d\d) ([JFMASOND][a-z][a-z]) (\d\d\d\d) (\d\d):(\d\d):(\d\d) GMT$~';
    private const WEEKDAY_RE = '~^(?:Sun|Mon|Tue|Wed|Thu|Fri|Sat)[a-z]*,?\s*~i';
    // (day) (month) (year) [(hour):(min)[:(sec)]] [(timezone)] [(zone name in parens)]
    private const LOOSE_HTTP_DATE_RE = '~^(\d\d?)(?:\s+|[-/])(\w+)(?:\s+|[-/])(\d+)(?:(?:\s+|:)(\d\d?):(\d\d)(?::(\d\d))?)?\s*(?:([-+]?\d{2,4}|(?![APap][Mm]\b)[A-Za-z]+)\s*)?(?:\(\w+\)\s*)?$~';
    // (year) (numerical month) (day) [(hour):(min)[:(sec)]] [(timezone) (Z is "zero meridian", i.e. GMT)]
    private const ISO_DATE_RE = '~^(\d{4})[-/]?(\d\d?)[-/]?(\d\d?)(?:(?:\s+|[-:Tt])(\d\d?):?(\d\d)(?::?(\d\d(?:\.\d*)?))?)?\s*(?:([-+]?\d\d?:?(:?\d\d)?|Z|z)\s*)?$~';
    private const TIMEZONE_RE = '~^([-+])?(\d\d?):?(\d\d)?$~';
    private const IPV4_RE = '~\.\d+$~';

    // Date/time conversion
    // -----------------------------------------------------------------------------

    private static function _timegm(array $tt): ?int
    {
        [$year, $month, $mday, $hour, $min, $sec] = $tt;
        if ($year >= self::EPOCH_YEAR && 1 <= $month && $month <= 12 && 1 <= $mday && $mday <= 31
            && 0 <= $hour && $hour <= 24 && 0 <= $min && $min <= 59 && 0 <= $sec && $sec <= 61) {
            return gmmktime((int) $hour, (int) $min, (int) $sec, (int) $month, (int) $mday, (int) $year);
        }

        return null;
    }

    /**
     * Return a string representing time in seconds since epoch, t.
     *
     * If the function is called without an argument, it will use the current
     * time.
     *
     * The format of the returned string is like "YYYY-MM-DD hh:mm:ssZ",
     * representing Universal Time (UTC, aka GMT).  An example of this format is:
     *
     * 1994-11-24 08:49:37Z
     */
    public static function time2isoz(?float $t = null): string
    {
        return gmdate('Y-m-d H:i:s', (int) ($t ?? time())).'Z';
    }

    public static function offset_from_tz_string(string $tz): ?int
    {
        $offset = null;
        if (in_array($tz, self::UTC_ZONES, true)) {
            $offset = 0;
        } elseif (preg_match(self::TIMEZONE_RE, $tz, $m)) {
            $offset = 3600 * (int) $m[2];
            if (($m[3] ?? '') !== '') {
                $offset = $offset + 60 * (int) $m[3];
            }
            if ($m[1] === '-') {
                $offset = -$offset;
            }
        }

        return $offset;
    }

    private static function _str2time(mixed $day, mixed $mon, mixed $yr, mixed $hr, mixed $min, mixed $sec, ?string $tz): ?int
    {
        $yr = (int) $yr;
        if ($yr > 9999) {
            return null;
        }

        // translate month name to number
        // month numbers start with 1 (January)
        $i = array_search(strtolower($mon), self::MONTHS_LOWER, true);
        if ($i !== false) {
            $mon = $i + 1;
        } else {
            // maybe it's already a number
            if (!preg_match('/^\s*[-+]?\d+\s*$/', $mon)) {
                return null;
            }
            $imon = (int) $mon;
            if (1 <= $imon && $imon <= 12) {
                $mon = $imon;
            } else {
                return null;
            }
        }

        // make sure clock elements are defined
        $hr ??= 0;
        $min ??= 0;
        $sec ??= 0;

        $day = (int) $day;
        $hr = (int) $hr;
        $min = (int) $min;
        $sec = (int) $sec;

        if ($yr < 1000) {
            // find "obvious" year
            $cur_yr = (int) date('Y');
            $m = $cur_yr % 100;
            $tmp = $yr;
            $yr = $yr + $cur_yr - $m;
            $m = $m - $tmp;
            if (abs($m) > 50) {
                if ($m > 0) {
                    $yr = $yr + 100;
                } else {
                    $yr = $yr - 100;
                }
            }
        }

        // convert UTC time tuple to seconds since epoch (not timezone-adjusted)
        $t = self::_timegm([$yr, $mon, $day, $hr, $min, $sec, $tz]);

        if ($t !== null) {
            // adjust time using timezone string, to get absolute time since epoch
            $tz ??= 'UTC';
            $tz = strtoupper($tz);
            $offset = self::offset_from_tz_string($tz);
            if ($offset === null) {
                return null;
            }
            $t = $t - $offset;
        }

        return $t;
    }

    /**
     * Returns time in seconds since epoch of time represented by a string.
     *
     * Return value is an integer.
     *
     * null is returned if the format of str is unrecognized, the time is outside
     * the representable range, or the timezone string is not recognized.  If the
     * string contains no timezone, UTC is assumed.
     *
     * The timezone in the string may be numerical (like "-0800" or "+0100") or a
     * string timezone (like "UTC", "GMT", "BST" or "EST").  Currently, only the
     * timezone strings equivalent to UTC (zero offset) are known to the function.
     *
     * The function loosely parses the following formats:
     *
     * Wed, 09 Feb 1994 22:23:32 GMT       -- HTTP format
     * Tuesday, 08-Feb-94 14:15:29 GMT     -- old rfc850 HTTP format
     * Tuesday, 08-Feb-1994 14:15:29 GMT   -- broken rfc850 HTTP format
     * 09 Feb 1994 22:23:32 GMT            -- HTTP format (no weekday)
     * 08-Feb-94 14:15:29 GMT              -- rfc850 format (no weekday)
     * 08-Feb-1994 14:15:29 GMT            -- broken rfc850 format (no weekday)
     *
     * The parser ignores leading and trailing whitespace.  The time may be
     * absent.
     *
     * If the year is given with only 2 digits, the function will select the
     * century that makes the year closest to the current date.
     */
    public static function http2time(string $text): ?int
    {
        // fast exit for strictly conforming string
        if (preg_match(self::STRICT_DATE_RE, $text, $m)) {
            $i = array_search(strtolower($m[2]), self::MONTHS_LOWER, true);
            if ($i === false) {
                throw new \ValueError('substring not found');
            }

            return self::_timegm([(int) $m[3], $i + 1, (int) $m[1], (int) $m[4], (int) $m[5], (float) $m[6]]);
        }

        // No, we need some messy parsing...

        // clean up
        $text = ltrim($text);
        $text = preg_replace(self::WEEKDAY_RE, '', $text, 1);  // Useless weekday

        // tz is time zone specifier string
        // loose regexp parse
        if (preg_match(self::LOOSE_HTTP_DATE_RE, $text, $m)) {
            $m = array_pad(array_map(static fn ($g) => $g === '' ? null : $g, array_slice($m, 1)), 7, null);
            [$day, $mon, $yr, $hr, $min, $sec, $tz] = $m;
        } else {
            return null;  // bad format
        }

        return self::_str2time($day, $mon, $yr, $hr, $min, $sec, $tz);
    }

    /**
     * As for http2time, but parses the ISO 8601 formats:
     *
     * 1994-02-03 14:15:29 -0100    -- ISO 8601 format
     * 1994-02-03 14:15:29          -- zone is optional
     * 1994-02-03                   -- only date
     * 1994-02-03T14:15:29          -- Use T as separator
     * 19940203T141529Z             -- ISO 8601 compact format
     * 19940203                     -- only date
     */
    public static function iso2time(string $text): ?int
    {
        // clean up
        $text = ltrim($text);

        // tz is time zone specifier string
        // loose regexp parse
        if (preg_match(self::ISO_DATE_RE, $text, $m)) {
            // XXX there's an extra bit of the timezone I'm ignoring here: is
            //   this the right thing to do?
            $m = array_pad(array_map(static fn ($g) => $g === '' ? null : $g, array_slice($m, 1)), 8, null);
            [$yr, $mon, $day, $hr, $min, $sec, $tz] = $m;
        } else {
            return null;  // bad format
        }

        return self::_str2time($day, $mon, $yr, $hr, $min, $sec, $tz);
    }

    // Header parsing
    // -----------------------------------------------------------------------------

    /**
     * Parse header values into a list of lists containing key,value pairs.
     *
     * The function knows how to deal with ",", ";" and "=" as well as quoted
     * values after "=".  A list of space separated tokens are parsed as if they
     * were separated by ";".
     *
     * If the header_values passed as argument contains multiple values, then they
     * are treated as if they were a single value separated by comma ",".
     *
     * Each header is represented by a list of key/value pairs.  The value for a
     * simple token (not part of a parameter) is null.  Syntactically incorrect
     * headers will not necessarily be parsed as you would want.
     *
     * split_header_words(['foo="bar"; port="80,81"; discard, bar=baz'])
     * [[['foo', 'bar'], ['port', '80,81'], ['discard', null]], [['bar', 'baz']]]
     *
     * @param list<string> $header_values
     * @return list<list<array{0: string, 1: ?string}>>
     */
    public static function split_header_words(array $header_values): array
    {
        $result = [];
        foreach ($header_values as $text) {
            $pairs = [];
            while ($text !== '') {
                if (preg_match('~^\s*([^=\s;,]+)~', $text, $m)) {
                    $text = substr($text, strlen($m[0]));
                    $name = $m[1];
                    if (preg_match('~^\s*=\s*"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"~', $text, $m)) {  // quoted value
                        $text = substr($text, strlen($m[0]));
                        $value = preg_replace('~\\\\(.)~', '\1', $m[1]);
                    } elseif (preg_match('~^\s*=\s*([^\s;,]*)~', $text, $m)) {  // unquoted value
                        $text = substr($text, strlen($m[0]));
                        $value = rtrim($m[1]);
                    } else {
                        // no value, a lone token
                        $value = null;
                    }
                    $pairs[] = [$name, $value];
                } elseif (str_starts_with(ltrim($text), ',')) {
                    // concatenated headers, as per RFC 2616 section 4.2
                    $text = substr(ltrim($text), 1);
                    if ($pairs) {
                        $result[] = $pairs;
                    }
                    $pairs = [];
                } else {
                    // skip junk
                    $text = preg_replace('~^[=\s;]*~', '', $text);
                }
            }
            if ($pairs) {
                $result[] = $pairs;
            }
        }

        return $result;
    }

    /**
     * Do the inverse (almost) of the conversion done by split_header_words.
     *
     * Takes a list of lists of (key, value) pairs and produces a single header
     * value.  Attribute values are quoted if needed.
     *
     * join_header_words([[['text/plain', null], ['charset', 'iso-8859/1']]])
     * 'text/plain; charset="iso-8859/1"'
     */
    public static function join_header_words(array $lists): string
    {
        $headers = [];
        foreach ($lists as $pairs) {
            $attr = [];
            foreach ($pairs as [$k, $v]) {
                if ($v !== null) {
                    if (!preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $v)) {
                        $v = preg_replace('~(["\\\\])~', '\\\\\1', $v);  // escape " and \
                        $v = '"'.$v.'"';
                    }
                    $k = "$k=$v";
                }
                $attr[] = $k;
            }
            if ($attr) {
                $headers[] = implode('; ', $attr);
            }
        }

        return implode(', ', $headers);
    }

    public static function strip_quotes(string $text): string
    {
        if (str_starts_with($text, '"')) {
            $text = substr($text, 1);
        }
        if (str_ends_with($text, '"')) {
            $text = substr($text, 0, -1);
        }

        return $text;
    }

    /**
     * Ad-hoc parser for Netscape protocol cookie-attributes.
     *
     * The old Netscape cookie format for Set-Cookie can for instance contain
     * an unquoted "," in the expires field, so we have to use this ad-hoc
     * parser instead of split_header_words.
     *
     * XXX This may not make the best possible effort to parse all the crap
     * that Netscape Cookie headers contain.  Ronald Tschalar's HTTPClient
     * parser is probably better, so could do worse than following that if
     * this ever gives any trouble.
     *
     * Currently, this is also used for parsing RFC 2109 cookies.
     *
     * @param list<string> $ns_headers
     * @return list<list<array{0: string, 1: mixed}>>
     */
    public static function parse_ns_headers(array $ns_headers): array
    {
        $known_attrs = ['expires', 'domain', 'path', 'secure',
            // RFC 2109 attrs (may turn up in Netscape cookies, too)
            'version', 'port', 'max-age'];

        $result = [];
        foreach ($ns_headers as $ns_header) {
            $pairs = [];
            $version_set = false;

            // XXX: The following does not strictly adhere to RFCs in that empty
            // names and values are legal (the former will only appear once and will
            // be overwritten if multiple occurrences are present). This is
            // mostly to deal with backwards compatibility.
            foreach (explode(';', $ns_header) as $ii => $param) {
                $param = trim($param);

                $i = strpos($param, '=');
                $sep = $i !== false;
                $key = trim($sep ? substr($param, 0, $i) : $param);
                $val = $sep ? substr($param, $i + 1) : '';

                if ($key === '') {
                    if ($ii === 0) {
                        break;
                    } else {
                        continue;
                    }
                }

                // allow for a distinction between present and empty and missing
                // altogether
                $val = $sep ? trim($val) : null;

                if ($ii !== 0) {
                    $lc = strtolower($key);
                    if (in_array($lc, $known_attrs, true)) {
                        $key = $lc;
                    }

                    if ($key === 'version') {
                        // This is an RFC 2109 cookie.
                        if ($val !== null) {
                            $val = self::strip_quotes($val);
                        }
                        $version_set = true;
                    } elseif ($key === 'expires') {
                        // convert expires date to seconds since epoch
                        if ($val !== null) {
                            $val = self::http2time(self::strip_quotes($val));  // null if invalid
                        }
                    }
                }
                $pairs[] = [$key, $val];
            }

            if ($pairs) {
                if (!$version_set) {
                    $pairs[] = ['version', '0'];
                }
                $result[] = $pairs;
            }
        }

        return $result;
    }

    /** Return true if text is a host domain name. */
    public static function is_HDN(string $text): bool
    {
        // XXX
        // This may well be wrong.  Which RFC is HDN defined in, if any (for
        //  the purposes of RFC 2965)?
        // For the current implementation, what about IPv6?  Remember to look
        //  at other uses of IPV4_RE also, if change this.
        if (preg_match(self::IPV4_RE, $text)) {
            return false;
        }
        if ($text === '') {
            return false;
        }
        if ($text[0] === '.' || str_ends_with($text, '.')) {
            return false;
        }

        return true;
    }

    /**
     * Return true if domain A domain-matches domain B, according to RFC 2965.
     *
     * A and B may be host domain names or IP addresses.
     *
     * RFC 2965, section 1:
     *
     * Host names can be specified either as an IP address or a HDN string.
     * Sometimes we compare one host name with another.  (Such comparisons SHALL
     * be case-insensitive.)  Host A's name domain-matches host B's if
     *
     *      *  their host name strings string-compare equal; or
     *
     *      * A is a HDN string and has the form NB, where N is a non-empty
     *         name string, B has the form .B', and B' is a HDN string.  (So,
     *         x.y.com domain-matches .Y.com but not Y.com.)
     *
     * Note that domain-match is not a commutative operation: a.b.c.com
     * domain-matches .c.com, but not the reverse.
     */
    public static function domain_match(string $A, string $B): bool
    {
        // Note that, if A or B are IP addresses, the only relevant part of the
        // definition of the domain-match algorithm is the direct string-compare.
        $A = strtolower($A);
        $B = strtolower($B);
        if ($A === $B) {
            return true;
        }
        if (!self::is_HDN($A)) {
            return false;
        }
        $i = strrpos($A, $B);
        if ($i === false || $i === 0) {
            // A does not have form NB, or N is the empty string
            return false;
        }
        if (!str_starts_with($B, '.')) {
            return false;
        }
        if (!self::is_HDN(substr($B, 1))) {
            return false;
        }

        return true;
    }

    /** Return true if text is a sort-of-like a host domain name. For accepting/blocking domains. */
    public static function liberal_is_HDN(string $text): bool
    {
        if (preg_match(self::IPV4_RE, $text)) {
            return false;
        }

        return true;
    }

    /** For blocking/accepting domains. A and B may be host domain names or IP addresses. */
    public static function user_domain_match(string $A, string $B): bool
    {
        $A = strtolower($A);
        $B = strtolower($B);
        if (!(self::liberal_is_HDN($A) && self::liberal_is_HDN($B))) {
            if ($A === $B) {
                // equal IP addresses
                return true;
            }

            return false;
        }
        $initial_dot = str_starts_with($B, '.');
        if ($initial_dot && str_ends_with($A, $B)) {
            return true;
        }
        if (!$initial_dot && $A === $B) {
            return true;
        }

        return false;
    }

    /**
     * Return request-host, as defined by RFC 2965.
     *
     * Variation from RFC: returned value is lowercased, for convenient comparison.
     */
    public static function request_host(MockRequest $request): string
    {
        $url = $request->get_full_url();
        $host = Urllib::urlparse($url)[1];
        if ($host === '') {
            $host = $request->get_header('Host', '');
        }

        // remove port, if present
        $host = preg_replace('~:\d+$~', '', $host, 1);

        return strtolower($host);
    }

    /**
     * Return a tuple (request-host, effective request-host name).
     *
     * As defined by RFC 2965, except both are lowercased.
     *
     * @return array{0: string, 1: string}
     */
    public static function eff_request_host(MockRequest $request): array
    {
        $erhn = $req_host = self::request_host($request);
        if (!str_contains($req_host, '.')) {
            $erhn = $req_host.'.local';
        }

        return [$req_host, $erhn];
    }

    /** Path component of request-URI, as defined by RFC 2965. */
    public static function request_path(MockRequest $request): string
    {
        $url = $request->get_full_url();
        $parts = Urllib::urlsplit($url);
        $path = self::escape_path($parts[2]);
        if (!str_starts_with($path, '/')) {
            // fix bad RFC 2396 absoluteURI
            $path = '/'.$path;
        }

        return $path;
    }

    public static function request_port(MockRequest $request): ?string
    {
        $host = $request->host;
        $i = strpos($host, ':');
        if ($i !== false) {
            $port = substr($host, $i + 1);
            if (!preg_match('/^\s*[-+]?\d+\s*$/', $port)) {
                return null;
            }
        } else {
            $port = self::DEFAULT_HTTP_PORT;
        }

        return $port;
    }

    /** Escape any invalid characters in HTTP URL, and uppercase all escapes. */
    public static function escape_path(string $path): string
    {
        // There's no knowing what character encoding was used to create URLs
        // containing %-escapes, but since we have to pick one to escape invalid
        // path characters, we pick UTF-8, as recommended in the HTML 4.0
        // specification:
        // http://www.w3.org/TR/REC-html40/appendix/notes.html#h-B.2.1
        // And here, kind of: draft-fielding-uri-rfc2396bis-03
        // (And in draft IRI specification: draft-duerst-iri-05)
        // (And here, for new URI schemes: RFC 2718)
        $path = Urllib::quote($path, self::HTTP_PATH_SAFE);

        return preg_replace_callback('~%([0-9a-fA-F][0-9a-fA-F])~', static fn ($m) => '%'.strtoupper($m[1]), $path);
    }

    /**
     * Return reach of host h, as defined by RFC 2965, section 1.
     *
     * The reach R of a host name H is defined as follows:
     *
     *    *  If
     *
     *       -  H is the host domain name of a host; and,
     *
     *       -  H has the form A.B; and
     *
     *       -  A has no embedded (that is, interior) dots; and
     *
     *       -  B has at least one embedded dot, or B is the string "local".
     *          then the reach of H is .B.
     *
     *    *  Otherwise, the reach of H is H.
     *
     * reach("www.acme.com") == '.acme.com'; reach("acme.com") == 'acme.com'; reach("acme.local") == '.local'
     */
    public static function reach(string $h): string
    {
        $i = strpos($h, '.');
        if ($i !== false) {
            // a = h[:i]  # this line is only here to show what a is
            $b = substr($h, $i + 1);
            $i = strpos($b, '.');
            if (self::is_HDN($h) && ($i !== false || $b === 'local')) {
                return '.'.$b;
            }
        }

        return $h;
    }

    /**
     * RFC 2965, section 3.3.6:
     *
     *     An unverifiable transaction is to a third-party host if its request-
     *     host U does not domain-match the reach R of the request-host O in the
     *     origin transaction.
     */
    public static function is_third_party(MockRequest $request): bool
    {
        $req_host = self::request_host($request);
        if (!self::domain_match($req_host, self::reach($request->origin_req_host))) {
            return true;
        }

        return false;
    }

    /**
     * Return string representation of Cookie in the LWP cookie file format.
     *
     * Actually, the format is extended a bit -- see module docstring.
     */
    public static function lwp_cookie_str(Cookie $cookie): string
    {
        $h = [[$cookie->name, $cookie->value],
            ['path', $cookie->path],
            ['domain', $cookie->domain]];
        if ($cookie->port !== null) {
            $h[] = ['port', $cookie->port];
        }
        if ($cookie->path_specified) {
            $h[] = ['path_spec', null];
        }
        if ($cookie->port_specified) {
            $h[] = ['port_spec', null];
        }
        if ($cookie->domain_initial_dot) {
            $h[] = ['domain_dot', null];
        }
        if ($cookie->secure) {
            $h[] = ['secure', null];
        }
        if ($cookie->expires) {
            $h[] = ['expires', self::time2isoz((float) $cookie->expires)];
        }
        if ($cookie->discard) {
            $h[] = ['discard', null];
        }
        if ($cookie->comment) {
            $h[] = ['comment', $cookie->comment];
        }
        if ($cookie->comment_url) {
            $h[] = ['commenturl', $cookie->comment_url];
        }

        $keys = array_map('strval', array_keys($cookie->_rest));
        sort($keys, SORT_STRING);
        foreach ($keys as $k) {
            $h[] = [$k, self::str($cookie->_rest[$k])];
        }

        $h[] = ['version', self::str($cookie->version)];

        return self::join_header_words([$h]);
    }

    /** ``str(value)`` for what ends up in a cookie attribute (``HttpOnly=None``). */
    private static function str(mixed $v): string
    {
        return match (true) {
            $v === true => 'True',
            $v === false => 'False',
            $v === null => 'None',
            default => (string) $v,
        };
    }
}
