<?php

namespace Foundation\Http;

/**
 * HTTP Cookie (http.cookiejar.Cookie).
 *
 * This class represents both Netscape and RFC 2965 cookies.
 *
 * This is deliberately a very simple class.  It just holds attributes.  It's
 * possible to construct Cookie instances that don't comply with the cookie
 * standards.  CookieJar::make_cookies is the factory function for Cookie
 * objects -- it deals with cookie parsing, supplying defaults, and
 * normalising to the representation used in this class.  CookiePolicy is
 * responsible for checking them to see whether they should be accepted from
 * and returned to the server.
 *
 * Note that the port may be present in the headers, but unspecified ("Port"
 * rather than"Port=80", for example); if this is the case, port is null.
 */
class Cookie
{
    public ?int $version;
    public string $name;
    public ?string $value;
    public ?string $port;
    public bool $port_specified;
    public string $domain;
    public bool $domain_specified;
    public bool $domain_initial_dot;
    public string $path;
    public bool $path_specified;
    public bool $secure;
    public ?int $expires;
    public bool $discard;
    public ?string $comment;
    public ?string $comment_url;
    public bool $rfc2109;
    /** @var array<string, mixed> */
    public array $_rest;

    public function __construct(
        mixed $version,
        string $name,
        ?string $value,
        ?string $port,
        bool $port_specified,
        string $domain,
        bool $domain_specified,
        bool $domain_initial_dot,
        string $path,
        bool $path_specified,
        bool $secure,
        mixed $expires,
        bool $discard,
        ?string $comment,
        ?string $comment_url,
        array $rest,
        bool $rfc2109 = false,
    ) {
        if ($version !== null) {
            $version = (int) $version;
        }
        if ($expires !== null) {
            $expires = (int) (float) $expires;
        }
        if ($port === null && $port_specified === true) {
            throw new \ValueError('if port is None, port_specified must be false');
        }

        $this->version = $version;
        $this->name = $name;
        $this->value = $value;
        $this->port = $port;
        $this->port_specified = $port_specified;
        // normalise case, as per RFC 2965 section 3.3.3
        $this->domain = strtolower($domain);
        $this->domain_specified = $domain_specified;
        // Sigh.  We need to know whether the domain given in the
        // cookie-attribute had an initial dot, in order to follow RFC 2965
        // (as clarified in draft errata).  Needed for the returned $Domain
        // value.
        $this->domain_initial_dot = $domain_initial_dot;
        $this->path = $path;
        $this->path_specified = $path_specified;
        $this->secure = $secure;
        $this->expires = $expires;
        $this->discard = $discard;
        $this->comment = $comment;
        $this->comment_url = $comment_url;
        $this->rfc2109 = $rfc2109;

        $this->_rest = $rest;
    }

    public function has_nonstandard_attr(string $name): bool
    {
        return array_key_exists($name, $this->_rest);
    }

    public function get_nonstandard_attr(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->_rest) ? $this->_rest[$name] : $default;
    }

    public function set_nonstandard_attr(string $name, mixed $value): void
    {
        $this->_rest[$name] = $value;
    }

    public function is_expired(?float $now = null): bool
    {
        $now ??= time();
        if ($this->expires !== null && $this->expires <= $now) {
            return true;
        }

        return false;
    }

    public function __toString(): string
    {
        $p = $this->port === null ? '' : ':'.$this->port;
        $limit = $this->domain.$p.$this->path;
        $namevalue = $this->value !== null ? $this->name.'='.$this->value : $this->name;

        return "<Cookie $namevalue for $limit>";
    }
}
