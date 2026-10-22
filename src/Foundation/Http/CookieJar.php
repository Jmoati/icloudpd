<?php

namespace Foundation\Http;

/**
 * Collection of HTTP cookies (http.cookiejar.CookieJar).
 *
 * @implements \IteratorAggregate<int, Cookie>
 */
class CookieJar implements \IteratorAggregate, \Countable
{
    protected DefaultCookiePolicy $_policy;
    /** @var array<string, array<string, array<string, Cookie>>> domain => path => name => cookie */
    public array $_cookies = [];
    public int $_now = 0;

    public function __construct(?DefaultCookiePolicy $policy = null)
    {
        $this->_policy = $policy ?? new DefaultCookiePolicy();
    }

    public function set_policy(DefaultCookiePolicy $policy): void
    {
        $this->_policy = $policy;
    }

    /** @return list<Cookie> */
    private function _cookies_for_domain(string $domain, MockRequest $request): array
    {
        $cookies = [];
        if (!$this->_policy->domain_return_ok($domain, $request)) {
            return [];
        }
        $cookies_by_path = $this->_cookies[$domain];
        foreach ($cookies_by_path as $path => $cookies_by_name) {
            if (!$this->_policy->path_return_ok((string) $path, $request)) {
                continue;
            }
            foreach ($cookies_by_name as $cookie) {
                if (!$this->_policy->return_ok($cookie, $request)) {
                    continue;
                }
                $cookies[] = $cookie;
            }
        }

        return $cookies;
    }

    /**
     * Return a list of cookies to be returned to server.
     *
     * @return list<Cookie>
     */
    private function _cookies_for_request(MockRequest $request): array
    {
        $cookies = [];
        foreach (array_keys($this->_cookies) as $domain) {
            array_push($cookies, ...$this->_cookies_for_domain((string) $domain, $request));
        }

        return $cookies;
    }

    /**
     * Return a list of cookie-attributes to be returned to server.
     *
     * like ['foo="bar"; $Path="/"', ...]
     *
     * The $Version attribute is also added when appropriate (currently only
     * once per request).
     *
     * @param list<Cookie> $cookies
     * @return list<string>
     */
    private function _cookie_attrs(array $cookies): array
    {
        // add cookies in order of most specific (ie. longest) path first
        usort($cookies, static fn (Cookie $a, Cookie $b) => strlen($b->path) <=> strlen($a->path));

        $version_set = false;

        $attrs = [];
        foreach ($cookies as $cookie) {
            // set version of Cookie header
            // XXX
            // What should it be if multiple matching Set-Cookie headers have
            //  different versions themselves?
            // Answer: there is no answer; was supposed to be settled by
            //  RFC 2965 errata, but that may never appear...
            $version = $cookie->version;
            if (!$version_set) {
                $version_set = true;
                if ($version > 0) {
                    $attrs[] = "\$Version=$version";
                }
            }

            // quote cookie value if necessary
            // (not for Netscape protocol, which already has any quotes
            //  intact, due to the poorly-specified Netscape Cookie: syntax)
            if ($cookie->value !== null && preg_match('~\W~', $cookie->value) && $version > 0) {
                $value = preg_replace('~(["\\\\])~', '\\\\\1', $cookie->value);
            } else {
                $value = $cookie->value;
            }

            // add cookie-attributes to be returned in Cookie header
            if ($cookie->value === null) {
                $attrs[] = $cookie->name;
            } else {
                $attrs[] = "$cookie->name=$value";
            }
            if ($version > 0) {
                if ($cookie->path_specified) {
                    $attrs[] = "\$Path=\"$cookie->path\"";
                }
                if (str_starts_with($cookie->domain, '.')) {
                    $domain = $cookie->domain;
                    if (!$cookie->domain_initial_dot && str_starts_with($domain, '.')) {
                        $domain = substr($domain, 1);
                    }
                    $attrs[] = "\$Domain=\"$domain\"";
                }
                if ($cookie->port !== null) {
                    $p = '$Port';
                    if ($cookie->port_specified) {
                        $p = $p."=\"$cookie->port\"";
                    }
                    $attrs[] = $p;
                }
            }
        }

        return $attrs;
    }

    /**
     * Add correct Cookie: header to request (a MockRequest object).
     *
     * The Cookie2 header is also added unless policy.hide_cookie2 is true.
     */
    public function add_cookie_header(MockRequest $request): void
    {
        $this->_policy->_now = $this->_now = time();

        $cookies = $this->_cookies_for_request($request);

        $attrs = $this->_cookie_attrs($cookies);
        if ($attrs) {
            if (!$request->has_header('Cookie')) {
                $request->add_unredirected_header('Cookie', implode('; ', $attrs));
            }
        }

        // if necessary, advertise that we know RFC 2965
        if ($this->_policy->rfc2965 && !$this->_policy->hide_cookie2 && !$request->has_header('Cookie2')) {
            foreach ($cookies as $cookie) {
                if ($cookie->version !== 1) {
                    $request->add_unredirected_header('Cookie2', '$Version="1"');
                    break;
                }
            }
        }

        $this->clear_expired_cookies();
    }

    /**
     * Return list of tuples containing normalised cookie information.
     *
     * attrs_set is the list of lists of key,value pairs extracted from
     * the Set-Cookie or Set-Cookie2 headers.
     *
     * Tuples are name, value, standard, rest, where name and value are the
     * cookie name and value, standard is a dictionary containing the standard
     * cookie-attributes (discard, secure, version, expires or max-age,
     * domain, path and port) and rest is a dictionary containing the rest of
     * the cookie-attributes.
     *
     * @return list<array{0: string, 1: ?string, 2: array<string, mixed>, 3: array<string, mixed>}>
     */
    private function _normalized_cookie_tuples(array $attrs_set): array
    {
        $cookie_tuples = [];

        $boolean_attrs = ['discard', 'secure'];
        $value_attrs = ['version',
            'expires', 'max-age',
            'domain', 'path', 'port',
            'comment', 'commenturl'];

        foreach ($attrs_set as $cookie_attrs) {
            [$name, $value] = $cookie_attrs[0];

            // Build dictionary of standard cookie-attributes (standard) and
            // dictionary of other cookie-attributes (rest).

            // Note: expiry time is normalised to seconds since epoch.  V0
            // cookies should have the Expires cookie-attribute, and V1 cookies
            // should have Max-Age, but since V1 includes RFC 2109 cookies (and
            // since V0 cookies may be a mish-mash of Netscape and RFC 2109), we
            // accept either (but prefer Max-Age).
            $max_age_set = false;

            $bad_cookie = false;

            $standard = [];
            $rest = [];
            foreach (array_slice($cookie_attrs, 1) as [$k, $v]) {
                $lc = strtolower($k);
                // don't lose case distinction for unknown fields
                if (in_array($lc, $value_attrs, true) || in_array($lc, $boolean_attrs, true)) {
                    $k = $lc;
                }
                if (in_array($k, $boolean_attrs, true) && $v === null) {
                    // boolean cookie-attribute is present, but has no value
                    // (like "discard", rather than "port=80")
                    $v = true;
                }
                if (array_key_exists($k, $standard)) {
                    // only first value is significant
                    continue;
                }
                if ($k === 'domain') {
                    if ($v === null) {
                        $bad_cookie = true;
                        break;
                    }
                    // RFC 2965 section 3.3.3
                    $v = strtolower($v);
                }
                if ($k === 'expires') {
                    if ($max_age_set) {
                        // Prefer max-age to expires (like Mozilla)
                        continue;
                    }
                    if ($v === null) {
                        // missing or invalid value for expires attribute: treating as session cookie
                        continue;
                    }
                }
                if ($k === 'max-age') {
                    $max_age_set = true;
                    if (!preg_match('/^\s*[-+]?\d+\s*$/', (string) $v)) {
                        // missing or invalid (non-numeric) value for max-age attribute
                        $bad_cookie = true;
                        break;
                    }
                    $v = (int) $v;
                    // convert RFC 2965 Max-Age to seconds since epoch
                    // XXX Strictly you're supposed to follow RFC 2616
                    //   age-calculation rules.  Remember that zero Max-Age
                    //   is a request to discard (old and new) cookie, though.
                    $k = 'expires';
                    $v = $this->_now + $v;
                }
                if (in_array($k, $value_attrs, true) || in_array($k, $boolean_attrs, true)) {
                    if ($v === null && !in_array($k, ['port', 'comment', 'commenturl'], true)) {
                        // missing value for attribute
                        $bad_cookie = true;
                        break;
                    }
                    $standard[$k] = $v;
                } else {
                    $rest[$k] = $v;
                }
            }

            if ($bad_cookie) {
                continue;
            }

            $cookie_tuples[] = [$name, $value, $standard, $rest];
        }

        return $cookie_tuples;
    }

    private function _cookie_from_cookie_tuple(array $tup, MockRequest $request): ?Cookie
    {
        // standard is dict of standard cookie-attributes, rest is dict of the
        // rest of them
        [$name, $value, $standard, $rest] = $tup;

        $has_domain = array_key_exists('domain', $standard);
        $domain = $standard['domain'] ?? null;
        $path = $standard['path'] ?? null;
        $has_path = array_key_exists('path', $standard);
        $has_port = array_key_exists('port', $standard);
        $port = $standard['port'] ?? null;
        $has_expires = array_key_exists('expires', $standard);
        $expires = $standard['expires'] ?? null;

        // set the easy defaults
        $version = $standard['version'] ?? null;
        if ($version !== null) {
            if (!preg_match('/^\s*[-+]?\d+\s*$/', (string) $version)) {
                return null;  // invalid version, ignore cookie
            }
            $version = (int) $version;
        }
        $secure = $standard['secure'] ?? false;
        // (discard is also set if expires is Absent)
        $discard = $standard['discard'] ?? false;
        $comment = $standard['comment'] ?? null;
        $comment_url = $standard['commenturl'] ?? null;

        // set default path
        if ($has_path && $path !== '') {
            $path_specified = true;
            $path = HttpCookiejar::escape_path($path);
        } else {
            $path_specified = false;
            $path = HttpCookiejar::request_path($request);
            $i = strrpos($path, '/');
            if ($i !== false) {
                if ($version === 0) {
                    // Netscape spec parts company from reality here
                    $path = substr($path, 0, $i);
                } else {
                    $path = substr($path, 0, $i + 1);
                }
            }
            if (strlen($path) === 0) {
                $path = '/';
            }
        }

        // set default domain
        $domain_specified = $has_domain;
        // but first we have to remember whether it starts with a dot
        $domain_initial_dot = false;
        if ($domain_specified) {
            $domain_initial_dot = str_starts_with($domain, '.');
        }
        if (!$has_domain) {
            [, $erhn] = HttpCookiejar::eff_request_host($request);
            $domain = $erhn;
        } elseif (!str_starts_with($domain, '.')) {
            $domain = '.'.$domain;
        }

        // set default port
        $port_specified = false;
        if ($has_port) {
            if ($port === null) {
                // Port attr present, but has no value: default to request port.
                // Cookie should then only be sent back on that port.
                $port = HttpCookiejar::request_port($request);
            } else {
                $port_specified = true;
                $port = preg_replace('~\s+~', '', $port);
            }
        } else {
            // No port attr present.  Cookie can be sent back on any port.
            $port = null;
        }

        // set default expires and discard
        if (!$has_expires) {
            $expires = null;
            $discard = true;
        } elseif ($expires <= $this->_now) {
            // Expiry date in past is request to delete cookie.  This can't be
            // in DefaultCookiePolicy, because can't delete cookies there.
            try {
                $this->clear($domain, $path, $name);
            } catch (\OutOfBoundsException) {
            }

            return null;
        }

        return new Cookie(
            $version,
            $name, $value,
            $port, $port_specified,
            $domain, $domain_specified, $domain_initial_dot,
            $path, $path_specified,
            $secure,
            $expires,
            $discard,
            $comment,
            $comment_url,
            $rest,
        );
    }

    /** @return list<Cookie> */
    private function _cookies_from_attrs_set(array $attrs_set, MockRequest $request): array
    {
        $cookie_tuples = $this->_normalized_cookie_tuples($attrs_set);

        $cookies = [];
        foreach ($cookie_tuples as $tup) {
            $cookie = $this->_cookie_from_cookie_tuple($tup, $request);
            if ($cookie) {
                $cookies[] = $cookie;
            }
        }

        return $cookies;
    }

    /** @param list<Cookie> $cookies */
    private function _process_rfc2109_cookies(array $cookies): void
    {
        $rfc2109_as_ns = $this->_policy->rfc2109_as_netscape;
        if ($rfc2109_as_ns === null) {
            $rfc2109_as_ns = !$this->_policy->rfc2965;
        }
        foreach ($cookies as $cookie) {
            if ($cookie->version === 1) {
                $cookie->rfc2109 = true;
                if ($rfc2109_as_ns) {
                    // treat 2109 cookies as Netscape cookies rather than
                    // as RFC2965 cookies
                    $cookie->version = 0;
                }
            }
        }
    }

    /**
     * Return sequence of Cookie objects extracted from response object.
     *
     * @param object $response something whose ``info()`` has ``get_all(name)`` (``Raw``)
     * @return list<Cookie>
     */
    public function make_cookies(object $response, MockRequest $request): array
    {
        // get cookie-attributes for RFC 2965 and Netscape protocols
        $headers = $response->info();
        $rfc2965_hdrs = $headers->get_all('Set-Cookie2', []);
        $ns_hdrs = $headers->get_all('Set-Cookie', []);
        $this->_policy->_now = $this->_now = time();

        $rfc2965 = $this->_policy->rfc2965;
        $netscape = $this->_policy->netscape;

        if ((!$rfc2965_hdrs && !$ns_hdrs)
            || (!$ns_hdrs && !$rfc2965)
            || (!$rfc2965_hdrs && !$netscape)
            || (!$netscape && !$rfc2965)) {
            return [];  // no relevant cookie headers: quick exit
        }

        $cookies = $this->_cookies_from_attrs_set(HttpCookiejar::split_header_words($rfc2965_hdrs), $request);

        if ($ns_hdrs && $netscape) {
            // RFC 2109 and Netscape cookies
            $ns_cookies = $this->_cookies_from_attrs_set(HttpCookiejar::parse_ns_headers($ns_hdrs), $request);
            $this->_process_rfc2109_cookies($ns_cookies);

            // Look for Netscape cookies (from Set-Cookie headers) that match
            // corresponding RFC 2965 cookies (from Set-Cookie2 headers).
            // For each match, keep the RFC 2965 cookie and ignore the Netscape
            // cookie (RFC 2965 section 9.1).  Actually, RFC 2109 cookies are
            // bundled in with the Netscape cookies for this purpose, which is
            // reasonable behaviour.
            if ($rfc2965) {
                $lookup = [];
                foreach ($cookies as $cookie) {
                    $lookup[json_encode([$cookie->domain, $cookie->path, $cookie->name])] = null;
                }
                $ns_cookies = array_filter(
                    $ns_cookies,
                    static fn (Cookie $ns_cookie) => !array_key_exists(json_encode([$ns_cookie->domain, $ns_cookie->path, $ns_cookie->name]), $lookup),
                );
            }

            if ($ns_cookies) {
                array_push($cookies, ...$ns_cookies);
            }
        }

        return $cookies;
    }

    /** Set a cookie if policy says it's OK to do so. */
    public function set_cookie_if_ok(Cookie $cookie, MockRequest $request): void
    {
        $this->_policy->_now = $this->_now = time();

        if ($this->_policy->set_ok($cookie, $request)) {
            $this->set_cookie($cookie);
        }
    }

    /** Set a cookie, without checking whether or not it should be set. */
    public function set_cookie(Cookie $cookie): void
    {
        $this->_cookies[$cookie->domain][$cookie->path][$cookie->name] = $cookie;
    }

    /** Extract cookies from response, where allowable given the request. */
    public function extract_cookies(object $response, MockRequest $request): void
    {
        foreach ($this->make_cookies($response, $request) as $cookie) {
            if ($this->_policy->set_ok($cookie, $request)) {
                $this->set_cookie($cookie);
            }
        }
    }

    /**
     * Clear some cookies.
     *
     * Invoking this method without arguments will clear all cookies.  If
     * given a single argument, only cookies belonging to that domain will be
     * removed.  If given two arguments, cookies belonging to the specified
     * path within that domain are removed.  If given three arguments, then
     * the cookie with the specified name, path and domain is removed.
     *
     * @throws \OutOfBoundsException (Python: KeyError) if no matching cookie exists.
     */
    public function clear(?string $domain = null, ?string $path = null, ?string $name = null): void
    {
        if ($name !== null) {
            if ($domain === null || $path === null) {
                throw new \ValueError('domain and path must be given to remove a cookie by name');
            }
            if (!isset($this->_cookies[$domain][$path][$name])) {
                throw new \OutOfBoundsException($name);
            }
            unset($this->_cookies[$domain][$path][$name]);
        } elseif ($path !== null) {
            if ($domain === null) {
                throw new \ValueError('domain must be given to remove cookies by path');
            }
            if (!isset($this->_cookies[$domain][$path])) {
                throw new \OutOfBoundsException($path);
            }
            unset($this->_cookies[$domain][$path]);
        } elseif ($domain !== null) {
            if (!isset($this->_cookies[$domain])) {
                throw new \OutOfBoundsException($domain);
            }
            unset($this->_cookies[$domain]);
        } else {
            $this->_cookies = [];
        }
    }

    /**
     * Discard all session cookies.
     *
     * Note that the .save() method won't save session cookies anyway, unless
     * you ask otherwise by passing a true ignore_discard argument.
     */
    public function clear_session_cookies(): void
    {
        foreach ($this as $cookie) {
            if ($cookie->discard) {
                $this->clear($cookie->domain, $cookie->path, $cookie->name);
            }
        }
    }

    /**
     * Discard all expired cookies.
     *
     * You probably don't need to call this method: expired cookies are never
     * sent back to the server (provided you're using DefaultCookiePolicy),
     * this method is called by CookieJar itself every so often, and the
     * .save() method won't save expired cookies anyway (unless you ask
     * otherwise by passing a true ignore_expires argument).
     */
    public function clear_expired_cookies(): void
    {
        $now = time();
        foreach ($this as $cookie) {
            if ($cookie->is_expired($now)) {
                $this->clear($cookie->domain, $cookie->path, $cookie->name);
            }
        }
    }

    /** Add (a copy of) every cookie of another jar (RequestsCookieJar.update). */
    public function update(CookieJar $other): void
    {
        foreach ($other as $cookie) {
            $this->set_cookie(clone $cookie);
        }
    }

    /** @return \Generator<int, Cookie> depth-first over domain, path, name (Python: ``deepvalues``) */
    public function getIterator(): \Generator
    {
        foreach ($this->_cookies as $by_path) {
            foreach ($by_path as $by_name) {
                foreach ($by_name as $cookie) {
                    yield $cookie;
                }
            }
        }
    }

    /** Return number of contained cookies. */
    public function count(): int
    {
        $i = 0;
        foreach ($this as $_) {
            ++$i;
        }

        return $i;
    }
}
