<?php

namespace Foundation\Http;

/**
 * Wraps a `Request` to mimic a `urllib2.Request` (requests.cookies.MockRequest).
 *
 * The code in `CookieJar` expects this interface in order to correctly
 * manage cookie policies, i.e., determine whether a cookie can be set, given the
 * domains of the request and the cookie.
 *
 * The original request object is read-only. The client is responsible for collecting
 * the new headers via `get_new_headers()` and interpreting them appropriately. You
 * probably want `get_cookie_header`, defined in `Cookies`.
 */
final class MockRequest
{
    public string $type;
    /** @var array<string, string> */
    private array $_new_headers = [];

    public function __construct(private readonly Request $_r)
    {
        $this->type = Urllib::urlparse($this->_r->url)[0];
    }

    public function get_type(): string
    {
        return $this->type;
    }

    public function get_host(): string
    {
        return Urllib::urlparse($this->_r->url)[1];
    }

    public function get_origin_req_host(): string
    {
        return $this->get_host();
    }

    public function get_full_url(): string
    {
        // Only return the response's URL if the user hadn't set the Host header
        if (!$this->_r->headers->get('Host')) {
            return $this->_r->url;
        }
        // If they did set it, retrieve it and reconstruct the expected domain
        $host = $this->_r->headers['Host'];
        [$scheme, , $path, $query, $fragment] = Urllib::urlparse($this->_r->url);

        // Reconstruct the URL as we expect it
        return Urllib::urlunsplit([$scheme, $host, $path, $query, $fragment]);
    }

    public function is_unverifiable(): bool
    {
        return true;
    }

    public function has_header(string $name): bool
    {
        return isset($this->_r->headers[$name]) || array_key_exists($name, $this->_new_headers);
    }

    public function get_header(string $name, mixed $default = null): mixed
    {
        return $this->_r->headers->get($name, $this->_new_headers[$name] ?? $default);
    }

    /** cookiejar has no legitimate use for this method; add it back if you find one. */
    public function add_header(string $key, string $val): never
    {
        throw new \BadMethodCallException('Cookie headers should be added with add_unredirected_header()');
    }

    public function add_unredirected_header(string $name, string $value): void
    {
        $this->_new_headers[$name] = $value;
    }

    /** @return array<string, string> */
    public function get_new_headers(): array
    {
        return $this->_new_headers;
    }

    public bool $unverifiable {
        get => $this->is_unverifiable();
    }

    public string $origin_req_host {
        get => $this->get_origin_req_host();
    }

    public string $host {
        get => $this->get_host();
    }
}
