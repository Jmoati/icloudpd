<?php

namespace Foundation\Http;

/**
 * The part of ``requests.utils`` that the session, request and response use.
 *
 * ponytail: no proxy/netrc/auth helpers (curl reads the proxy environment itself, nothing here uses netrc or auth).
 */
final class Utils
{
    // The unreserved URI characters (RFC 3986)
    public const UNRESERVED_SET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    public const DEFAULT_ACCEPT_ENCODING = 'gzip, deflate';
    public const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public static function default_user_agent(string $name = 'python-requests'): string
    {
        return "$name/2.32.3";
    }

    /** @return CaseInsensitiveDict */
    public static function default_headers(): CaseInsensitiveDict
    {
        return new CaseInsensitiveDict([
            'User-Agent' => self::default_user_agent(),
            'Accept-Encoding' => self::DEFAULT_ACCEPT_ENCODING,
            'Accept' => '*/*',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Un-escape any percent-escape sequences in a URI that are unreserved
     * characters. This leaves all reserved, illegal and non-ASCII bytes encoded.
     */
    public static function unquote_unreserved(string $uri): string
    {
        $parts = explode('%', $uri);
        for ($i = 1; $i < count($parts); ++$i) {
            $h = substr($parts[$i], 0, 2);
            if (strlen($h) === 2 && ctype_alnum($h)) {
                if (!ctype_xdigit($h)) {
                    throw new InvalidURL("Invalid percent-escape sequence: '$h'");
                }
                $c = chr(hexdec($h));

                if (str_contains(self::UNRESERVED_SET, $c)) {
                    $parts[$i] = $c.substr($parts[$i], 2);
                } else {
                    $parts[$i] = '%'.$parts[$i];
                }
            } else {
                $parts[$i] = '%'.$parts[$i];
            }
        }

        return implode('', $parts);
    }

    /**
     * Re-quote the given URI.
     *
     * This function passes the given URI through an unquote/quote cycle to
     * ensure that it is fully and consistently quoted.
     */
    public static function requote_uri(string $uri): string
    {
        $safe_with_percent = "!#$%&'()*+,/:;=?@[]~";
        $safe_without_percent = "!#$&'()*+,/:;=?@[]~";
        try {
            // Unquote only the unreserved characters
            // Then quote only illegal characters (do not quote reserved,
            // unreserved, or '%')
            return Urllib::quote(self::unquote_unreserved($uri), $safe_with_percent);
        } catch (InvalidURL) {
            // We couldn't unquote the given URI, so let's try quoting it, but
            // there may be unquoted '%'s in the URI. We need to make sure they're
            // properly quoted so they do not cause issues elsewhere.
            return Urllib::quote($uri, $safe_without_percent);
        }
    }

    /**
     * Returns content type and parameters from given header
     *
     * @return array{0: string, 1: array<string, string|true>}
     */
    public static function _parse_content_type_header(string $header): array
    {
        $tokens = explode(';', $header);
        $content_type = trim($tokens[0]);
        $params = array_slice($tokens, 1);
        $params_dict = [];
        $items_to_strip = "\"' ";

        foreach ($params as $param) {
            $param = trim($param);
            if ($param !== '') {
                $key = $param;
                $value = true;
                $index_of_equals = strpos($param, '=');
                if ($index_of_equals !== false) {
                    $key = trim(substr($param, 0, $index_of_equals), $items_to_strip);
                    $value = trim(substr($param, $index_of_equals + 1), $items_to_strip);
                }
                $params_dict[strtolower($key)] = $value;
            }
        }

        return [$content_type, $params_dict];
    }

    /** Returns encodings from given HTTP Header Dict. */
    public static function get_encoding_from_headers(CaseInsensitiveDict $headers): ?string
    {
        $content_type = $headers->get('content-type');

        if (!$content_type) {
            return null;
        }

        [$content_type, $params] = self::_parse_content_type_header($content_type);

        if (isset($params['charset'])) {
            return trim((string) $params['charset'], "'\"");
        }

        if (str_contains($content_type, 'text')) {
            return 'ISO-8859-1';
        }

        if (str_contains($content_type, 'application/json')) {
            // Assume UTF-8 based on RFC 4627: https://www.ietf.org/rfc/rfc4627.txt since the charset was unset
            return 'utf-8';
        }

        return null;
    }

    /**
     * Iterate over slices of a string.
     *
     * @return \Generator<int, string>
     */
    public static function iter_slices(string $string, ?int $slice_length): \Generator
    {
        $pos = 0;
        if ($slice_length === null || $slice_length <= 0) {
            $slice_length = strlen($string);
        }
        while ($pos < strlen($string)) {
            yield substr($string, $pos, $slice_length);
            $pos += $slice_length;
        }
    }

    public static function guess_json_utf(string $data): ?string
    {
        // JSON always starts with two ASCII characters, so detection is as
        // easy as counting the nulls and from their location and count
        // determine the encoding. Also detect a BOM, if present.
        $sample = substr($data, 0, 4);
        if ($sample === "\xFF\xFE\x00\x00" || $sample === "\x00\x00\xFE\xFF") {
            return 'UTF-32';  // BOM included
        }
        if (substr($sample, 0, 3) === "\xEF\xBB\xBF") {
            return 'UTF-8';  // BOM included, MS style (discouraged)
        }
        if (in_array(substr($sample, 0, 2), ["\xFF\xFE", "\xFE\xFF"], true)) {
            return 'UTF-16';  // BOM included
        }
        $nullcount = substr_count($sample, "\0");
        if ($nullcount === 0) {
            return 'UTF-8';
        }
        if ($nullcount === 2) {
            if (($sample[0] ?? '') === "\0" && ($sample[2] ?? '') === "\0") {  // 1st and 3rd are null
                return 'UTF-16BE';
            }
            if (($sample[1] ?? '') === "\0" && ($sample[3] ?? '') === "\0") {  // 2nd and 4th are null
                return 'UTF-16LE';
            }
            // Did not detect 2 valid UTF-16 ascii-range characters
        }
        if ($nullcount === 3) {
            if (substr($sample, 0, 3) === "\0\0\0") {
                return 'UTF-32BE';
            }
            if (substr($sample, 1) === "\0\0\0") {
                return 'UTF-32LE';
            }
            // Did not detect a valid UTF-32 ascii-range character
        }

        return null;
    }
}
