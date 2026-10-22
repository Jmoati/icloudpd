<?php

namespace Foundation\Http;

use Foundation\Py;

/**
 * The fully mutable ``PreparedRequest`` object (requests.models.PreparedRequest), containing the exact bytes
 * that will be sent to the server. Python's unprepared ``Request`` is not needed: ``Session::request`` builds
 * this directly, and ``(new Request())->prepare(...)`` replaces ``Request(...).prepare()``.
 *
 * Instances are generated from a ``Request`` object, and should not be instantiated manually; doing so may
 * produce undesirable effects.
 *
 *     $req = (new Request())->prepare(method: 'GET', url: 'https://httpbin.org/get');
 *     $req->method === 'GET';
 *
 * ponytail: no files (multipart), auth, hooks, file-like or generator bodies, nor ``;params`` in the URL.
 */
class Request
{
    /** HTTP verb to send to the server. */
    public ?string $method = null;
    /** HTTP URL to send the request to. */
    public ?string $url = null;
    /** dictionary of HTTP headers. */
    public ?CaseInsensitiveDict $headers = null;
    /** The ``CookieJar`` used to create the Cookie header will be stored here after prepare_cookies is called */
    public ?CookieJar $_cookies = null;
    /** request body to send to the server. */
    public ?string $body = null;
    /** integer denoting starting position of a readable file-like body. */
    public mixed $_body_position = null;

    // What ``requests.adapters.HTTPAdapter.send(request, stream, timeout, verify, cert)`` gets next to the
    // request: set by ``Session::send``, read by the transport.
    public bool $stream = false;
    /** @var float|int|array{0: float|int|null, 1: float|int|null}|null seconds, or [connect, read] */
    public float|int|array|null $timeout = null;
    public bool|string $verify = true;
    public mixed $cert = null;

    /** Prepares the entire request with the given parameters. */
    public function prepare(
        ?string $method = null,
        ?string $url = null,
        iterable|null $headers = null,
        mixed $files = null,
        mixed $data = null,
        mixed $params = null,
        mixed $auth = null,
        CookieJar|array|null $cookies = null,
        mixed $hooks = null,
        mixed $json = null,
    ): static {
        $this->prepare_method($method);
        $this->prepare_url($url, $params);
        $this->prepare_headers($headers);
        $this->prepare_cookies($cookies);
        $this->prepare_body($data, $files, $json);

        return $this;
    }

    public function __toString(): string
    {
        return "<PreparedRequest [$this->method]>";
    }

    public function __clone()
    {
        $this->headers = $this->headers?->copy();
        $this->_cookies = Cookies::_copy_cookie_jar($this->_cookies);
    }

    public function copy(): static
    {
        return clone $this;
    }

    /** Prepares the given HTTP method. */
    public function prepare_method(?string $method): void
    {
        $this->method = $method;
        if ($this->method !== null) {
            $this->method = strtoupper($this->method);
        }
    }

    /** Prepares the given HTTP URL. */
    public function prepare_url(?string $url, mixed $params): void
    {
        // Remove leading whitespaces from url
        $url = ltrim((string) $url);

        // Don't do any URL preparation for non-HTTP schemes like `mailto`,
        // `data` etc to work around exceptions from `url_parse`, which
        // handles RFC 3986 only.
        if (str_contains($url, ':') && !str_starts_with(strtolower($url), 'http')) {
            $this->url = $url;

            return;
        }

        // Support for unicode domain names and paths.
        [$scheme, $netloc, $path, $query, $fragment] = Urllib::urlsplit($url);

        if ($scheme === '') {
            throw new MissingSchema("Invalid URL '$url': No scheme supplied. Perhaps you meant https://$url?");
        }

        $at = strrpos($netloc, '@');
        $auth = $at === false ? '' : substr($netloc, 0, $at);
        $hostport = $at === false ? $netloc : substr($netloc, $at + 1);
        $host = Urllib::hostname($netloc);
        if ($host === null || $host === '') {
            throw new InvalidURL("Invalid URL '$url': No host supplied");
        }

        // In general, we want to try IDNA encoding the hostname if the string contains
        // non-ASCII characters. This allows users to automatically get the correct IDNA
        // behaviour. For strings containing only ASCII characters, we need to also verify
        // it doesn't start with a wildcard (*), before allowing the unencoded hostname.
        $port = Urllib::port($netloc);
        if (preg_match('/[^\x00-\x7f]/', $host)) {
            // ponytail: IDNA needs ext-intl, an iCloud host is ASCII
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                throw new InvalidURL('URL has an invalid label.');
            }
            $host = $ascii;
        } elseif (str_starts_with($host, '*') || str_starts_with($host, '.')) {
            throw new InvalidURL('URL has an invalid label.');
        }
        if (str_starts_with($hostport, '[')) {
            // IPv6 literal: keep the brackets
            $host = '['.$host.']';
        }

        // Carefully reconstruct the network location
        $netloc = $auth;
        if ($netloc !== '') {
            $netloc .= '@';
        }
        $netloc .= $host;
        if ($port) {
            $netloc .= ":$port";
        }

        // Bare domains aren't valid URLs.
        if ($path === '') {
            $path = '/';
        }

        $enc_params = $this->_encode_params($params);
        if ($enc_params !== '') {
            if ($query !== '') {
                $query = "$query&$enc_params";
            } else {
                $query = $enc_params;
            }
        }

        $this->url = Utils::requote_uri(Urllib::urlunsplit([$scheme, $netloc, $path, $query, $fragment]));
    }

    /** Prepares the given HTTP headers. */
    public function prepare_headers(iterable|null $headers): void
    {
        $this->headers = new CaseInsensitiveDict();
        if ($headers) {
            foreach ($headers as $name => $value) {
                // ponytail: no check_header_validity (InvalidHeader); scalars are sent as strings
                $this->headers[$name] = is_scalar($value) ? (string) $value : $value;
            }
        }
    }

    /** Prepares the given HTTP body data. */
    public function prepare_body(mixed $data, mixed $files, mixed $json = null): void
    {
        // Nottin' on you.
        $body = null;
        $content_type = null;

        if (!Py::truthy($data) && $json !== null) {
            $content_type = 'application/json';
            // json.dumps(json, allow_nan=False)
            $body = Py::json_dumps($json);
        }

        if ($files) {
            throw new \BadMethodCallException('ponytail: multipart uploads are not ported');
        }
        if (Py::truthy($data)) {
            $body = $this->_encode_params($data);
            if (is_string($data)) {
                $content_type = null;
            } else {
                $content_type = 'application/x-www-form-urlencoded';
            }
        }

        $this->prepare_content_length($body);

        // Add content-type if it wasn't explicitly provided.
        if ($content_type && !isset($this->headers['content-type'])) {
            $this->headers['Content-Type'] = $content_type;
        }

        $this->body = $body;
    }

    /** Prepare Content-Length header based on request method and body */
    public function prepare_content_length(?string $body): void
    {
        if ($body !== null) {
            $length = strlen($body);
            if ($length) {
                // If length exists, set it. Otherwise, we fallback
                // to Transfer-Encoding: chunked.
                $this->headers['Content-Length'] = (string) $length;
            }
        } elseif (!in_array($this->method, ['GET', 'HEAD'], true) && $this->headers->get('Content-Length') === null) {
            // Set Content-Length to 0 for methods that can have a body
            // but don't provide one. (i.e. not GET or HEAD)
            $this->headers['Content-Length'] = '0';
        }
    }

    /**
     * Prepares the given HTTP cookie data.
     *
     * This function eventually generates a ``Cookie`` header from the
     * given cookies using cookielib. Due to cookielib's design, the header
     * will not be regenerated if it already exists, meaning this function
     * can only be called once for the life of the
     * ``Request`` object. Any subsequent calls
     * to ``prepare_cookies`` will have no actual effect, unless the "Cookie"
     * header is removed beforehand.
     */
    public function prepare_cookies(CookieJar|array|null $cookies): void
    {
        if ($cookies instanceof CookieJar) {
            $this->_cookies = $cookies;
        } else {
            $this->_cookies = Cookies::cookiejar_from_dict($cookies);
        }

        $cookie_header = Cookies::get_cookie_header($this->_cookies, $this);
        if ($cookie_header !== null) {
            $this->headers['Cookie'] = $cookie_header;
        }
    }

    /**
     * Encode parameters in a piece of data.
     *
     * Will successfully encode parameters when passed as a dict or a list of
     * 2-tuples. Order is retained if data is a list of 2-tuples but arbitrary
     * if parameters are supplied as a dict.
     */
    public function _encode_params(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }
        if (is_iterable($data)) {
            $result = [];
            foreach ($data as $k => $vs) {
                if (is_string($vs) || !is_iterable($vs)) {
                    $vs = [$vs];
                }
                foreach ($vs as $v) {
                    if ($v !== null) {
                        $result[] = [$k, $v];
                    }
                }
            }

            return Urllib::urlencode($result, pairs: true);
        }

        return (string) $data;
    }
}
