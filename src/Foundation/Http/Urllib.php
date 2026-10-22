<?php

namespace Foundation\Http;

/**
 * The part of Python's ``urllib.parse`` used by requests, http.cookiejar and vcr.
 *
 * ponytail: no ``;params`` splitting (urlparse) and no IPv6 netloc handling beyond hostname/port; add when needed.
 */
final class Urllib
{
    private const ALWAYS_SAFE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-~';

    /**
     * Parse a URL into 5 components: <scheme>://<netloc>/<path>?<query>#<fragment>.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} scheme, netloc, path, query, fragment
     */
    public static function urlsplit(string $url, string $scheme = ''): array
    {
        $url = ltrim($url, "\0..\x20");
        $url = str_replace(["\t", "\r", "\n"], '', $url);
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $m)) {
            $scheme = strtolower($m[1]);
            $url = substr($url, strlen($m[0]));
        }
        $netloc = $query = $fragment = '';
        if (str_starts_with($url, '//')) {
            $end = 2 + strcspn($url, '/?#', 2);
            $netloc = substr($url, 2, $end - 2);
            $url = substr($url, $end);
        }
        if (($i = strpos($url, '#')) !== false) {
            $fragment = substr($url, $i + 1);
            $url = substr($url, 0, $i);
        }
        if (($i = strpos($url, '?')) !== false) {
            $query = substr($url, $i + 1);
            $url = substr($url, 0, $i);
        }

        return [$scheme, $netloc, $url, $query, $fragment];
    }

    /** Same as urlparse for the URLs seen here (no ``;params``): ponytail, see class comment. */
    public static function urlparse(string $url, string $scheme = ''): array
    {
        return self::urlsplit($url, $scheme);
    }

    /** Combine the elements of a urlsplit() tuple back into a URL. */
    public static function urlunsplit(array $parts): string
    {
        [$scheme, $netloc, $url, $query, $fragment] = $parts;
        if ($netloc !== '') {
            if ($url !== '' && $url[0] !== '/') {
                $url = '/'.$url;
            }
            $url = '//'.$netloc.$url;
        } elseif (str_starts_with($url, '//')) {
            $url = '//'.$url;
        } elseif ($scheme !== '' && in_array($scheme, ['http', 'https', 'ftp', 'file', 'ws', 'wss'], true) && ($url === '' || $url[0] === '/')) {
            $url = '//'.$url;
        }
        if ($scheme !== '') {
            $url = $scheme.':'.$url;
        }
        if ($query !== '') {
            $url = $url.'?'.$query;
        }
        if ($fragment !== '') {
            $url = $url.'#'.$fragment;
        }

        return $url;
    }

    /** Lower-cased host name of a netloc (``urlparse(url).hostname``). */
    public static function hostname(string $netloc): ?string
    {
        $host = substr(strrchr('@'.$netloc, '@'), 1);
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } else {
            $host = explode(':', $host, 2)[0];
        }

        return $host === '' ? null : strtolower($host);
    }

    /** Port of a netloc (``urlparse(url).port``), null if absent. */
    public static function port(string $netloc): ?int
    {
        $host = substr(strrchr('@'.$netloc, '@'), 1);
        $i = strrpos($host, ':');
        if ($i === false || str_contains(substr($host, $i), ']')) {
            return null;
        }
        $port = substr($host, $i + 1);

        return ctype_digit($port) ? (int) $port : null;
    }

    /** Join a base URL and a possibly relative URL to form an absolute interpretation of the latter. */
    public static function urljoin(string $base, string $url, bool $allow_fragments = true): string
    {
        if ($base === '') {
            return $url;
        }
        if ($url === '') {
            return $base;
        }
        [$bscheme, $bnetloc, $bpath, $bquery, $bfragment] = self::urlsplit($base);
        [$scheme, $netloc, $path, $query, $fragment] = self::urlsplit($url, $bscheme);
        if ($scheme !== $bscheme) {
            return $url;
        }
        if ($netloc !== '') {
            return self::urlunsplit([$scheme, $netloc, $path, $query, $fragment]);
        }
        $netloc = $bnetloc;
        if ($path === '') {
            $path = $bpath;
            if ($query === '') {
                $query = $bquery;
            }

            return self::urlunsplit([$scheme, $netloc, $path, $query, $fragment]);
        }
        $base_parts = explode('/', $bpath);
        if (end($base_parts) !== '') {
            // the last item is not a directory, so will not be taken into account in resolving the relative path
            array_pop($base_parts);
        }
        // for rfc3986, ignore all base path should the first character be root.
        if ($path[0] === '/') {
            $segments = explode('/', $path);
        } else {
            $segments = array_merge($base_parts, explode('/', $path));
            // filter out elements that would cause redundant slashes on re-joining the resolved_path
            $count = count($segments);
            $segments = array_merge(
                [$segments[0]],
                array_values(array_filter(array_slice($segments, 1, max(0, $count - 2)), static fn ($s) => $s !== '')),
                $count > 1 ? [$segments[$count - 1]] : [],
            );
        }
        $resolved_path = [];
        foreach ($segments as $seg) {
            if ($seg === '..') {
                array_pop($resolved_path);
            } elseif ($seg === '.') {
                continue;
            } else {
                $resolved_path[] = $seg;
            }
        }
        if (in_array(end($segments), ['.', '..'], true)) {
            // do some post-processing here. if the last segment was a relative dir, then we need to append the trailing '/'
            $resolved_path[] = '';
        }

        return self::urlunsplit([$scheme, $netloc, implode('/', $resolved_path) ?: '/', $query, $fragment]);
    }

    /** Percent-encode a string, ``safe`` characters (and the unreserved ones) are left alone. */
    public static function quote(string $string, string $safe = '/'): string
    {
        $safe .= self::ALWAYS_SAFE;

        return preg_replace_callback(
            '/[^'.preg_quote($safe, '/').']/',
            static fn ($m) => sprintf('%%%02X', ord($m[0])),
            $string,
        );
    }

    public static function quote_plus(string $string, string $safe = ''): string
    {
        return str_replace('%20', '+', self::quote($string, $safe));
    }

    public static function unquote(string $string): string
    {
        return rawurldecode($string);
    }

    public static function unquote_plus(string $string): string
    {
        return urldecode($string);
    }

    /**
     * Encode a dict (or a list of pairs) into a percent-encoded ASCII text string (``urlencode(query, doseq=True)``).
     *
     * @param array<array-key, mixed>|list<array{0: string, 1: mixed}> $query
     */
    public static function urlencode(array $query, bool $pairs = false): string
    {
        $l = [];
        foreach ($pairs ? $query : array_map(null, array_keys($query), array_values($query)) as [$k, $v]) {
            $k = self::quote_plus((string) $k);
            foreach (is_array($v) ? $v : [$v] as $elt) {
                $l[] = $k.'='.self::quote_plus(self::str($elt));
            }
        }

        return implode('&', $l);
    }

    /**
     * Parse a query given as a string argument into a list of (name, value) pairs (``keep_blank_values=False``).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parse_qsl(string $qs, bool $keep_blank_values = false): array
    {
        $r = [];
        foreach (explode('&', $qs) as $name_value) {
            if ($name_value === '' ) {
                continue;
            }
            $nv = explode('=', $name_value, 2);
            if (count($nv) !== 2) {
                if (!$keep_blank_values) {
                    continue;
                }
                $nv[] = '';
            }
            if ($nv[1] !== '' || $keep_blank_values) {
                $r[] = [self::unquote_plus($nv[0]), self::unquote_plus($nv[1])];
            }
        }

        return $r;
    }

    /** ``str(value)`` for the types that end up in a query string. */
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
