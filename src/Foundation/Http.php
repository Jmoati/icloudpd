<?php

namespace Foundation;

use Foundation\Http\CaseInsensitiveDict;
use Foundation\Http\Cookie;
use Foundation\Http\Request;
use Foundation\Http\Response;

/**
 * Port of `foundation/http.py`: helpers that turn requests and responses into HAR-like entries.
 *
 * ponytail: the point-free compositions of the upstream (`compose`, `partial`, `non_empty_pairs`, `flat_dict`) are
 * written as loops.
 */
final class Http
{
    /** @return array{0: string, 1: ?string} */
    public static function cookie_to_pair(Cookie $cookie): array
    {
        return [$cookie->name, $cookie->value];
    }

    /**
     * The (name, value) pairs of the cookies that have a value (``non_empty_pairs`` of ``map(cookie_to_pair)``).
     *
     * @param iterable<Cookie> $jar
     * @return list<array{0: string, 1: string}>
     */
    public static function jar_to_pairs(iterable $jar): array
    {
        $pairs = [];
        foreach ($jar as $cookie) {
            $pair = self::cookie_to_pair($cookie);
            if ($pair[1] !== null) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * replace cookie header with dict object
     *
     * `http.cookies.SimpleCookie().load(cookie_header)`: nothing at all when the header is not valid.
     *
     * @return array<string, string>
     */
    public static function cookie_str_to_dict(string $cookie_header): array
    {
        $legal_key_chars = '\w\d!#%&\'\~_`><@,:/\$\*\+\-\.\^\|\)\(\?\}\{\=';
        $legal_value_chars = $legal_key_chars.'\[\]';
        $pattern = '~\G\s*(?P<key>['.$legal_key_chars.']+?)(\s*=\s*(?P<val>"(?:[^\\\\"]|\\\\.)*"|\w{3},\s[\w\d\s-]{9,11}\s[\d:]{8}\sGMT|['.$legal_value_chars.']*))?\s*(\s+|;|$)~';
        $reserved = ['expires', 'path', 'comment', 'domain', 'max-age', 'secure', 'httponly', 'version', 'samesite'];
        $flags = ['secure', 'httponly'];

        $cookies = [];
        $morsel_seen = false;
        $offset = 0;
        $n = strlen($cookie_header);
        while ($offset < $n && preg_match($pattern, $cookie_header, $m, PREG_UNMATCHED_AS_NULL, $offset)) {
            $key = $m['key'];
            $value = $m['val'];
            $offset += strlen($m[0]);
            if ($key[0] === '$') {
                // We ignore attributes which pertain to the cookie mechanism as a whole, such as "$Version".
                continue;
            } elseif (in_array(strtolower($key), $reserved, true)) {
                if (!$morsel_seen || ($value === null && !in_array(strtolower($key), $flags, true))) {
                    return [];  // Invalid cookie string
                }
            } elseif ($value !== null) {
                $cookies[$key] = self::_unquote($value);
                $morsel_seen = true;
            } else {
                return [];  // Invalid cookie string
            }
        }

        return $cookies;
    }

    /** `http.cookies._unquote` */
    private static function _unquote(string $str): string
    {
        if (strlen($str) < 2 || $str[0] !== '"' || !str_ends_with($str, '"')) {
            return $str;
        }

        return preg_replace_callback(
            '~\\\\(?:([0-3][0-7][0-7])|(.))~s',
            static fn ($m) => ($m[1] ?? '') !== '' ? chr(octdec($m[1])) : $m[2],
            substr($str, 1, -1),
        );
    }

    /** Check if response was created with stream=True */
    public static function is_streaming_response(object $response): bool
    {
        try {
            return isset($response->raw) && !$response->raw->isclosed();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function response_body(Response $response): mixed
    {
        if (self::is_streaming_response($response)) {
            return null;
        } else {
            try {
                return $response->json();
            } catch (\Throwable) {
                return $response->text();
            }
        }
    }

    public static function request_body(Request $request): mixed
    {
        if ($request->body !== null) {
            try {
                return json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
            }
        }

        return $request->body;
    }

    /**
     * @param CaseInsensitiveDict|array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private static function items(CaseInsensitiveDict|array $headers): array
    {
        return $headers instanceof CaseInsensitiveDict ? $headers->items() : $headers;
    }

    /** @return array<string, mixed> */
    public static function response_to_har_entry(Response $response): array
    {
        // headers
        // cookies from request header
        $request_headers = [];
        $request_cookies = [];
        foreach (self::items($response->request->headers) as $name => $value) {
            if ($name === 'Cookie') {
                $request_cookies = array_merge($request_cookies, self::cookie_str_to_dict($value));
            } else {
                $request_headers[$name] = $value;
            }
        }

        $response_headers = [];
        foreach (self::items($response->headers) as $name => $value) {
            if ($name !== 'Set-Cookie') {
                $response_headers[$name] = $value;
            }
        }

        $response_cookies = [];
        foreach (self::jar_to_pairs($response->cookies) as [$name, $value]) {
            $response_cookies[$name] = $value;
        }

        return [
            'request' => [
                'method' => $response->request->method,
                'url' => $response->request->url,
                'headers' => $request_headers,
                'cookies' => $request_cookies,
                'content' => self::request_body($response->request),
            ],
            'response' => [
                'status_code' => $response->status_code,
                'headers' => $response_headers,
                'cookies' => $response_cookies,
                'content' => self::response_body($response),
            ],
        ];
    }
}
