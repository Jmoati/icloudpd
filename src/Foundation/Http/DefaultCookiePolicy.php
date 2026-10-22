<?php

namespace Foundation\Http;

/**
 * Implements the standard rules for accepting and returning cookies (http.cookiejar.DefaultCookiePolicy).
 *
 * Python's abstract ``CookiePolicy`` base is folded in here (it is its only subclass).
 */
class DefaultCookiePolicy
{
    public const DomainStrictNoDots = 1;
    public const DomainStrictNonDomain = 2;
    public const DomainRFC2965Match = 4;

    public const DomainLiberal = 0;
    public const DomainStrict = self::DomainStrictNoDots | self::DomainStrictNonDomain;

    /** set by CookieJar before each use (``policy._now``) */
    public int $_now = 0;

    /** @var list<string> */
    private array $_blocked_domains;
    /** @var ?list<string> */
    private ?array $_allowed_domains;

    /** Constructor arguments should be passed as named arguments only. */
    public function __construct(
        ?array $blocked_domains = null,
        ?array $allowed_domains = null,
        public bool $netscape = true,
        public bool $rfc2965 = false,
        public ?bool $rfc2109_as_netscape = null,
        public bool $hide_cookie2 = false,
        public bool $strict_domain = false,
        public bool $strict_rfc2965_unverifiable = true,
        public bool $strict_ns_unverifiable = false,
        public int $strict_ns_domain = self::DomainLiberal,
        public bool $strict_ns_set_initial_dollar = false,
        public bool $strict_ns_set_path = false,
        public array $secure_protocols = ['https', 'wss'],
    ) {
        $this->_blocked_domains = $blocked_domains !== null ? array_values($blocked_domains) : [];
        $this->_allowed_domains = $allowed_domains !== null ? array_values($allowed_domains) : null;
    }

    /** Return the sequence of blocked domains. */
    public function blocked_domains(): array
    {
        return $this->_blocked_domains;
    }

    /** Set the sequence of blocked domains. */
    public function set_blocked_domains(array $blocked_domains): void
    {
        $this->_blocked_domains = array_values($blocked_domains);
    }

    public function is_blocked(string $domain): bool
    {
        foreach ($this->_blocked_domains as $blocked_domain) {
            if (HttpCookiejar::user_domain_match($domain, $blocked_domain)) {
                return true;
            }
        }

        return false;
    }

    /** Return null, or the sequence of allowed domains. */
    public function allowed_domains(): ?array
    {
        return $this->_allowed_domains;
    }

    /** Set the sequence of allowed domains, or null. */
    public function set_allowed_domains(?array $allowed_domains): void
    {
        $this->_allowed_domains = $allowed_domains !== null ? array_values($allowed_domains) : null;
    }

    public function is_not_allowed(string $domain): bool
    {
        if ($this->_allowed_domains === null) {
            return false;
        }
        foreach ($this->_allowed_domains as $allowed_domain) {
            if (HttpCookiejar::user_domain_match($domain, $allowed_domain)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return true if (and only if) cookie should be accepted from server.
     *
     * Currently, pre-expired cookies never get this far -- the CookieJar
     * class deletes such cookies itself.
     */
    public function set_ok(Cookie $cookie, MockRequest $request): bool
    {
        foreach (['version', 'verifiability', 'name', 'path', 'domain', 'port'] as $n) {
            $fn_name = 'set_ok_'.$n;
            if (!$this->$fn_name($cookie, $request)) {
                return false;
            }
        }

        return true;
    }

    public function set_ok_version(Cookie $cookie, MockRequest $request): bool
    {
        if ($cookie->version === null) {
            // Version is always set to 0 by parse_ns_headers if it's a Netscape
            // cookie, so this must be an invalid RFC 2965 cookie.
            return false;
        }
        if ($cookie->version > 0 && !$this->rfc2965) {
            // RFC 2965 cookies are switched off
            return false;
        } elseif ($cookie->version === 0 && !$this->netscape) {
            // Netscape cookies are switched off
            return false;
        }

        return true;
    }

    public function set_ok_verifiability(Cookie $cookie, MockRequest $request): bool
    {
        if ($request->unverifiable && HttpCookiejar::is_third_party($request)) {
            if ($cookie->version > 0 && $this->strict_rfc2965_unverifiable) {
                // third-party RFC 2965 cookie during unverifiable transaction
                return false;
            } elseif ($cookie->version === 0 && $this->strict_ns_unverifiable) {
                // third-party Netscape cookie during unverifiable transaction
                return false;
            }
        }

        return true;
    }

    public function set_ok_name(Cookie $cookie, MockRequest $request): bool
    {
        // Try and stop servers setting V0 cookies designed to hack other
        // servers that know both V0 and V1 protocols.
        if ($cookie->version === 0 && $this->strict_ns_set_initial_dollar && str_starts_with($cookie->name, '$')) {
            // illegal name (starts with '$')
            return false;
        }

        return true;
    }

    public function set_ok_path(Cookie $cookie, MockRequest $request): bool
    {
        if ($cookie->path_specified) {
            if (($cookie->version > 0 || ($cookie->version === 0 && $this->strict_ns_set_path))
                && !$this->path_return_ok($cookie->path, $request)) {
                // path attribute is not a prefix of request path
                return false;
            }
        }

        return true;
    }

    public function set_ok_domain(Cookie $cookie, MockRequest $request): bool
    {
        if ($this->is_blocked($cookie->domain)) {
            // domain is in user block-list
            return false;
        }
        if ($this->is_not_allowed($cookie->domain)) {
            // domain is not in user allow-list
            return false;
        }
        if ($cookie->domain_specified) {
            [$req_host, $erhn] = HttpCookiejar::eff_request_host($request);
            $domain = $cookie->domain;
            if ($this->strict_domain && substr_count($domain, '.') >= 2) {
                // XXX This should probably be compared with the Konqueror
                // (kcookiejar.cpp) and Mozilla implementations, but it's a
                // losing battle.
                $i = strrpos($domain, '.');
                $j = strrpos(substr($domain, 0, $i), '.');
                if ($j === 0) {  // domain like .foo.bar
                    $tld = substr($domain, $i + 1);
                    $sld = substr($domain, $j + 1, $i - $j - 1);
                    if (in_array(strtolower($sld), ['co', 'ac', 'com', 'edu', 'org', 'net',
                        'gov', 'mil', 'int', 'aero', 'biz', 'cat', 'coop',
                        'info', 'jobs', 'mobi', 'museum', 'name', 'pro',
                        'travel', 'eu'], true) && strlen($tld) === 2) {
                        // domain like .co.uk
                        return false;
                    }
                }
            }
            if (str_starts_with($domain, '.')) {
                $undotted_domain = substr($domain, 1);
            } else {
                $undotted_domain = $domain;
            }
            $embedded_dots = str_contains($undotted_domain, '.');
            if (!$embedded_dots && !str_ends_with($erhn, '.local')) {
                // non-local domain contains no embedded dot
                return false;
            }
            if ($cookie->version === 0) {
                if (!(str_ends_with($erhn, $domain) || str_ends_with($erhn, "$undotted_domain.local"))
                    && (!str_starts_with($erhn, '.') && !str_ends_with('.'.$erhn, $domain))) {
                    // effective request-host (even with added initial dot) does not end with domain
                    return false;
                }
            }
            if ($cookie->version > 0 || ($this->strict_ns_domain & self::DomainRFC2965Match)) {
                if (!HttpCookiejar::domain_match($erhn, $domain)) {
                    // effective request-host does not domain-match domain
                    return false;
                }
            }
            if ($cookie->version > 0 || ($this->strict_ns_domain & self::DomainStrictNoDots)) {
                $host_prefix = strlen($domain) > 0 && strlen($domain) <= strlen($req_host)
                    ? substr($req_host, 0, strlen($req_host) - strlen($domain))
                    : '';
                if (str_contains($host_prefix, '.') && !preg_match('~\.\d+$~', $req_host)) {
                    // host prefix for domain contains a dot
                    return false;
                }
            }
        }

        return true;
    }

    public function set_ok_port(Cookie $cookie, MockRequest $request): bool
    {
        if ($cookie->port_specified) {
            $req_port = HttpCookiejar::request_port($request);
            if ($req_port === null) {
                $req_port = '80';
            } else {
                $req_port = (string) $req_port;
            }
            $found = false;
            foreach (explode(',', $cookie->port) as $p) {
                if (!preg_match('/^\s*[-+]?\d+\s*$/', $p)) {
                    // bad port (not numeric)
                    return false;
                }
                if ($p === $req_port) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                // request port not found in cookie port
                return false;
            }
        }

        return true;
    }

    /** Return true if (and only if) cookie should be returned to server. */
    public function return_ok(Cookie $cookie, MockRequest $request): bool
    {
        // Path has already been checked by .path_return_ok(), and domain
        // blocking done by .domain_return_ok().
        foreach (['version', 'verifiability', 'secure', 'expires', 'port', 'domain'] as $n) {
            $fn_name = 'return_ok_'.$n;
            if (!$this->$fn_name($cookie, $request)) {
                return false;
            }
        }

        return true;
    }

    public function return_ok_version(Cookie $cookie, MockRequest $request): bool
    {
        if ($cookie->version > 0 && !$this->rfc2965) {
            // RFC 2965 cookies are switched off
            return false;
        } elseif ($cookie->version === 0 && !$this->netscape) {
            // Netscape cookies are switched off
            return false;
        }

        return true;
    }

    public function return_ok_verifiability(Cookie $cookie, MockRequest $request): bool
    {
        if ($request->unverifiable && HttpCookiejar::is_third_party($request)) {
            if ($cookie->version > 0 && $this->strict_rfc2965_unverifiable) {
                // third-party RFC 2965 cookie during unverifiable transaction
                return false;
            } elseif ($cookie->version === 0 && $this->strict_ns_unverifiable) {
                // third-party Netscape cookie during unverifiable transaction
                return false;
            }
        }

        return true;
    }

    public function return_ok_secure(Cookie $cookie, MockRequest $request): bool
    {
        if ($cookie->secure && !in_array($request->type, $this->secure_protocols, true)) {
            // secure cookie with non-secure request
            return false;
        }

        return true;
    }

    public function return_ok_expires(Cookie $cookie, MockRequest $request): bool
    {
        if ($cookie->is_expired($this->_now)) {
            // cookie expired
            return false;
        }

        return true;
    }

    public function return_ok_port(Cookie $cookie, MockRequest $request): bool
    {
        if ($cookie->port !== null && $cookie->port !== '') {
            $req_port = HttpCookiejar::request_port($request);
            if ($req_port === null) {
                $req_port = '80';
            }
            $found = false;
            foreach (explode(',', $cookie->port) as $p) {
                if ($p === $req_port) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                // request port does not match cookie port
                return false;
            }
        }

        return true;
    }

    public function return_ok_domain(Cookie $cookie, MockRequest $request): bool
    {
        [$req_host, $erhn] = HttpCookiejar::eff_request_host($request);
        $domain = $cookie->domain;

        if ($domain !== '' && !str_starts_with($domain, '.')) {
            $dotdomain = '.'.$domain;
        } else {
            $dotdomain = $domain;
        }

        // strict check of non-domain cookies: Mozilla does this, MSIE5 doesn't
        if ($cookie->version === 0
            && ($this->strict_ns_domain & self::DomainStrictNonDomain)
            && !$cookie->domain_specified && $domain !== $erhn) {
            // cookie with unspecified domain does not string-compare equal to request domain
            return false;
        }

        if ($cookie->version > 0 && !HttpCookiejar::domain_match($erhn, $domain)) {
            // effective request-host name does not domain-match RFC 2965 cookie domain
            return false;
        }
        if ($cookie->version === 0 && !str_ends_with('.'.$erhn, $dotdomain)) {
            // request-host does not match Netscape cookie domain
            return false;
        }

        return true;
    }

    /** Return false if cookies should not be returned, given cookie domain. */
    public function domain_return_ok(string $domain, MockRequest $request): bool
    {
        // Liberal check of.  This is here as an optimization to avoid
        // having to load lots of MSIE cookie files unless necessary.
        [$req_host, $erhn] = HttpCookiejar::eff_request_host($request);
        if (!str_starts_with($req_host, '.')) {
            $req_host = '.'.$req_host;
        }
        if (!str_starts_with($erhn, '.')) {
            $erhn = '.'.$erhn;
        }
        if ($domain !== '' && !str_starts_with($domain, '.')) {
            $dotdomain = '.'.$domain;
        } else {
            $dotdomain = $domain;
        }
        if (!(str_ends_with($req_host, $dotdomain) || str_ends_with($erhn, $dotdomain))) {
            return false;
        }

        if ($this->is_blocked($domain)) {
            // domain is in user block-list
            return false;
        }
        if ($this->is_not_allowed($domain)) {
            // domain is not in user allow-list
            return false;
        }

        return true;
    }

    /** Return false if cookies should not be returned, given cookie path. */
    public function path_return_ok(string $path, MockRequest $request): bool
    {
        $req_path = HttpCookiejar::request_path($request);
        $pathlen = strlen($path);
        if ($req_path === $path) {
            return true;
        } elseif (str_starts_with($req_path, $path) && (str_ends_with($path, '/') || substr($req_path, $pathlen, 1) === '/')) {
            return true;
        }

        // req_path does not path-match path
        return false;
    }
}
