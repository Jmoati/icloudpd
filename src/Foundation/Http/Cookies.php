<?php

namespace Foundation\Http;

/**
 * Module-level functions of ``requests.cookies``: compatibility code to be able to use `CookieJar` with requests.
 *
 * ponytail: no RequestsCookieJar (the dict-like jar): a plain CookieJar is the session's jar.
 */
final class Cookies
{
    /**
     * Extract the cookies from the response into a CookieJar.
     *
     * @param CookieJar $jar
     * @param Request $request our own Request object
     * @param Raw|null $response the raw response (what carries the Set-Cookie headers)
     */
    public static function extract_cookies_to_jar(CookieJar $jar, Request $request, ?Raw $response): void
    {
        if ($response === null) {
            return;
        }
        $req = new MockRequest($request);
        $jar->extract_cookies($response, $req);
    }

    /** Produce an appropriate Cookie header string to be sent with `request`, or null. */
    public static function get_cookie_header(CookieJar $jar, Request $request): ?string
    {
        $r = new MockRequest($request);
        $jar->add_cookie_header($r);

        return $r->get_new_headers()['Cookie'] ?? null;
    }

    /**
     * Make a cookie from underspecified parameters.
     *
     * By default, the pair of `name` and `value` will be set for the domain ''
     * and sent on every request (this is sometimes called a "supercookie").
     */
    public static function create_cookie(string $name, ?string $value, mixed ...$kwargs): Cookie
    {
        $result = [
            'version' => 0,
            'name' => $name,
            'value' => $value,
            'port' => null,
            'domain' => '',
            'path' => '/',
            'secure' => false,
            'expires' => null,
            'discard' => true,
            'comment' => null,
            'comment_url' => null,
            'rest' => ['HttpOnly' => null],
            'rfc2109' => false,
        ];

        $badargs = array_diff_key($kwargs, $result);
        if ($badargs) {
            throw new \TypeError(sprintf('create_cookie() got unexpected keyword arguments: %s', implode(', ', array_keys($badargs))));
        }

        $result = $kwargs + $result;

        $result['port_specified'] = $result['port'] !== null;
        $result['domain_specified'] = $result['domain'] !== '';
        $result['domain_initial_dot'] = str_starts_with($result['domain'], '.');
        $result['path_specified'] = $result['path'] !== '';

        return new Cookie(
            $result['version'], $result['name'], $result['value'],
            $result['port'], $result['port_specified'],
            $result['domain'], $result['domain_specified'], $result['domain_initial_dot'],
            $result['path'], $result['path_specified'],
            $result['secure'], $result['expires'], $result['discard'],
            $result['comment'], $result['comment_url'], $result['rest'], $result['rfc2109'],
        );
    }

    /**
     * Returns a CookieJar from a key/value dictionary.
     *
     * @param array<string, string>|null $cookie_dict dict of key/values to insert into CookieJar.
     */
    public static function cookiejar_from_dict(?array $cookie_dict, ?CookieJar $cookiejar = null, bool $overwrite = true): CookieJar
    {
        $cookiejar ??= new CookieJar();

        if ($cookie_dict !== null) {
            $names_from_jar = [];
            foreach ($cookiejar as $cookie) {
                $names_from_jar[] = $cookie->name;
            }
            foreach ($cookie_dict as $name => $value) {
                if ($overwrite || !in_array((string) $name, $names_from_jar, true)) {
                    $cookiejar->set_cookie(self::create_cookie((string) $name, $value));
                }
            }
        }

        return $cookiejar;
    }

    /**
     * Add cookies to cookiejar and returns a merged CookieJar.
     *
     * @param CookieJar|array<string, string> $cookies
     */
    public static function merge_cookies(CookieJar $cookiejar, CookieJar|array $cookies): CookieJar
    {
        if (is_array($cookies)) {
            $cookiejar = self::cookiejar_from_dict($cookies, $cookiejar, false);
        } else {
            $cookiejar->update($cookies);
        }

        return $cookiejar;
    }

    public static function _copy_cookie_jar(?CookieJar $jar): ?CookieJar
    {
        if ($jar === null) {
            return null;
        }
        $new_jar = clone $jar;
        $new_jar->clear();
        foreach ($jar as $cookie) {
            $new_jar->set_cookie(clone $cookie);
        }

        return $new_jar;
    }
}
