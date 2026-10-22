<?php

namespace Foundation\Http;

use Foundation\Patch;

/**
 * A Requests session (requests.sessions.Session, with SessionRedirectMixin folded in).
 *
 * Provides cookie persistence and configuration. The HTTP exchange itself goes through ``Session::$transport``
 * (curl unless a test replaced it, see ``Tests\Vcr``).
 *
 * Python's keyword arguments (``session.post(url, data=..., headers=...)``) are PHP named arguments or an array
 * (as the first extra positional argument): both ``$session->post($url, data: $d, headers: $h)`` and
 * ``$session->post($url, ['data' => $d, 'headers' => $h])`` work; a non-array positional argument after the
 * url is ``data`` (then ``json``) for post, ``data`` for put and patch, like in requests.
 *
 * ponytail: one transport call per request or redirect hop, no adapters (mount), hooks, auth, proxies argument,
 * files, or context manager; Python's ``Request`` (unprepared) is folded into ``Request::prepare``.
 */
class Session
{
    /**
     * Sends a prepared request and returns the ``Response`` with its headers read (the body is pulled from
     * ``$response->raw``): the replaceable part of ``HTTPAdapter.send``. null: curl (``Curl::send``).
     *
     * @var ?\Closure(Request): Response
     */
    public static ?\Closure $transport = null;

    /** A case-insensitive dictionary of headers to be sent on each ``Request`` sent from this ``Session``. */
    public CaseInsensitiveDict $headers;

    /** Dictionary of querystring data to attach to each request. The dictionary values may be lists for representing multivalued query parameters. */
    public array $params = [];

    /** Stream response content default. */
    public bool $stream = false;

    /**
     * SSL Verification default. Defaults to `true`, requiring requests to verify the TLS certificate at the
     * remote end. If verify is set to `false`, requests will accept any TLS certificate presented by the
     * server, and will ignore hostname mismatches and/or expired certificates, which will make your
     * application vulnerable to man-in-the-middle (MitM) attacks. Only set this to `false` for testing.
     */
    public bool|string $verify = true;

    /** SSL client certificate default, if String, path to ssl client cert file (.pem). If array, [cert, key] pair. */
    public mixed $cert = null;

    /** Maximum number of redirects allowed. If the request exceeds this limit, a TooManyRedirects is thrown. */
    public int $max_redirects = 30;

    /** Trust environment settings (REQUESTS_CA_BUNDLE, CURL_CA_BUNDLE). */
    public bool $trust_env = true;

    /** A CookieJar containing all currently outstanding cookies set on this session. */
    public CookieJar $cookies;

    public function __construct()
    {
        $this->headers = Utils::default_headers();
        $this->cookies = Cookies::cookiejar_from_dict([]);
    }

    /**
     * Determines appropriate setting for a given request, taking into account the explicit setting on that
     * request, and the setting in the session. If a setting is a dictionary, they will be merged together.
     */
    public static function merge_setting(mixed $request_setting, mixed $session_setting, bool $case_insensitive = false): mixed
    {
        if ($session_setting === null) {
            return $request_setting;
        }

        if ($request_setting === null) {
            return $session_setting;
        }

        // Bypass if not a dictionary (e.g. verify)
        if (!(is_iterable($session_setting) && is_iterable($request_setting))) {
            return $request_setting;
        }

        if ($case_insensitive) {
            $merged = new CaseInsensitiveDict($session_setting);
            $merged->update($request_setting);
            // Remove keys that are set to None.
            foreach ($merged->items() as $key => $value) {
                if ($value === null) {
                    unset($merged[$key]);
                }
            }

            return $merged;
        }

        $merged = [];
        foreach ([$session_setting, $request_setting] as $setting) {
            foreach ($setting as $key => $value) {
                $merged[$key] = $value;
            }
        }

        // Remove keys that are set to None.
        return array_filter($merged, static fn ($v) => $v !== null);
    }

    /**
     * Normalizes the keyword arguments of a call (named arguments, an array, and the positional ones of requests).
     *
     * @param array<array-key, mixed> $args what ``...$kwargs`` collected
     * @param list<string> $positional the names of the positional arguments after the url (``data``, ``json``)
     * @return array<string, mixed>
     */
    protected static function kwargs(array $args, array $positional = []): array
    {
        $kwargs = [];
        foreach ($args as $key => $value) {
            if (is_string($key)) {
                $kwargs[$key] = $value;
            } elseif (is_array($value)) {
                $kwargs = array_merge($kwargs, $value);
            } elseif ($positional) {
                $kwargs[array_shift($positional)] = $value;
            } else {
                throw new \ArgumentCountError('Too many positional arguments: use named arguments or an array of keyword arguments');
            }
        }

        return $kwargs;
    }

    /**
     * Constructs a ``Request``, prepares it and sends it. Returns ``Response`` object.
     *
     * Keyword arguments:
     *  - params: (optional) Dictionary or string to be sent in the query string for the request.
     *  - data: (optional) Dictionary or string to send in the body of the request.
     *  - json: (optional) json to send in the body of the request.
     *  - headers: (optional) Dictionary of HTTP Headers to send with the request.
     *  - cookies: (optional) Dict or CookieJar object to send with the request.
     *  - timeout: (optional) How long to wait for the server to send data before giving up, as a float, or a
     *    [connect timeout, read timeout] array.
     *  - allow_redirects: (optional) Set to true by default.
     *  - stream: (optional) whether to immediately download the response content. Defaults to ``false``.
     *  - verify: (optional) Either a boolean, in which case it controls whether we verify the server's TLS
     *    certificate, or a string, in which case it must be a path to a CA bundle to use. Defaults to ``true``.
     *  - cert: (optional) if String, path to ssl client cert file (.pem). If array, [cert, key] pair.
     */
    public function request(string $method, string $url, mixed ...$kwargs): Response
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this, $method, $url, ...$kwargs);
        }
        $kwargs = self::kwargs($kwargs);
        $unknown = array_diff_key($kwargs, array_flip(['params', 'data', 'headers', 'cookies', 'files', 'auth', 'timeout', 'allow_redirects', 'proxies', 'hooks', 'stream', 'verify', 'cert', 'json']));
        if ($unknown) {
            throw new \TypeError(sprintf("request() got an unexpected keyword argument '%s'", array_key_first($unknown)));
        }

        // Create the Request.
        $prep = $this->prepare_request(
            strtoupper($method),
            $url,
            $kwargs['headers'] ?? null,
            $kwargs['data'] ?? [],
            $kwargs['json'] ?? null,
            $kwargs['params'] ?? [],
            $kwargs['cookies'] ?? null,
        );

        $settings = $this->merge_environment_settings(
            $kwargs['stream'] ?? null,
            $kwargs['verify'] ?? null,
            $kwargs['cert'] ?? null,
        );

        // Send the request.
        $send_kwargs = [
            'timeout' => $kwargs['timeout'] ?? null,
            'allow_redirects' => $kwargs['allow_redirects'] ?? true,
        ];
        $send_kwargs = $settings + $send_kwargs;

        return $this->send($prep, ...$send_kwargs);
    }

    /** Sends a GET request. Returns ``Response`` object. */
    public function get(string $url, mixed ...$kwargs): Response
    {
        $kwargs = self::kwargs($kwargs);
        $kwargs += ['allow_redirects' => true];

        return $this->request('GET', $url, ...$kwargs);
    }

    /** Sends a OPTIONS request. Returns ``Response`` object. */
    public function options(string $url, mixed ...$kwargs): Response
    {
        $kwargs = self::kwargs($kwargs);
        $kwargs += ['allow_redirects' => true];

        return $this->request('OPTIONS', $url, ...$kwargs);
    }

    /** Sends a HEAD request. Returns ``Response`` object. */
    public function head(string $url, mixed ...$kwargs): Response
    {
        $kwargs = self::kwargs($kwargs);
        $kwargs += ['allow_redirects' => false];

        return $this->request('HEAD', $url, ...$kwargs);
    }

    /** Sends a POST request. Returns ``Response`` object. */
    public function post(string $url, mixed ...$kwargs): Response
    {
        return $this->request('POST', $url, ...self::kwargs($kwargs, ['data', 'json']));
    }

    /** Sends a PUT request. Returns ``Response`` object. */
    public function put(string $url, mixed ...$kwargs): Response
    {
        return $this->request('PUT', $url, ...self::kwargs($kwargs, ['data']));
    }

    /** Sends a PATCH request. Returns ``Response`` object. */
    public function patch(string $url, mixed ...$kwargs): Response
    {
        return $this->request('PATCH', $url, ...self::kwargs($kwargs, ['data']));
    }

    /** Sends a DELETE request. Returns ``Response`` object. */
    public function delete(string $url, mixed ...$kwargs): Response
    {
        return $this->request('DELETE', $url, ...self::kwargs($kwargs));
    }

    /**
     * Constructs a ``Request`` for transmission and returns it. The ``Request`` has settings merged from the
     * parameters and those of the ``Session``.
     */
    public function prepare_request(string $method, string $url, mixed $headers = null, mixed $data = null, mixed $json = null, mixed $params = null, mixed $cookies = null): Request
    {
        $cookies ??= [];

        // Bootstrap CookieJar.
        if (!($cookies instanceof CookieJar)) {
            $cookies = Cookies::cookiejar_from_dict($cookies);
        }

        // Merge with session cookies
        $merged_cookies = Cookies::merge_cookies(
            Cookies::merge_cookies(new CookieJar(), $this->cookies),
            $cookies,
        );

        $p = new Request();
        $p->prepare(
            method: strtoupper($method),
            url: $url,
            data: $data,
            json: $json,
            headers: self::merge_setting($headers, $this->headers, case_insensitive: true),
            params: self::merge_setting($params, $this->params),
            cookies: $merged_cookies,
        );

        return $p;
    }

    /**
     * Send a given prepared ``Request``.
     *
     * Keyword arguments: stream, timeout, verify, cert, allow_redirects.
     */
    public function send(Request $request, mixed ...$kwargs): Response
    {
        $kwargs = self::kwargs($kwargs);
        // Set defaults that the hooks can utilize to ensure they always have
        // the correct parameters to reproduce the previous request.
        $kwargs += ['stream' => $this->stream, 'verify' => $this->verify, 'cert' => $this->cert];

        // Set up variables needed for resolve_redirects and dispatching of hooks
        $allow_redirects = $kwargs['allow_redirects'] ?? true;
        unset($kwargs['allow_redirects']);
        $stream = $kwargs['stream'];

        // Start time (approximately) of the request
        $start = microtime(true);

        // Send the request
        $r = $this->adapter_send($request, $kwargs);

        // Total elapsed time of the request (approximately)
        $r->elapsed = microtime(true) - $start;

        // Persist cookies
        foreach ($r->history as $resp) {
            Cookies::extract_cookies_to_jar($this->cookies, $resp->request, $resp->raw);
        }

        Cookies::extract_cookies_to_jar($this->cookies, $request, $r->raw);

        // Resolve redirects if allowed.
        if ($allow_redirects) {
            // Redirect resolving generator.
            $history = iterator_to_array($this->resolve_redirects($r, $request, ...$kwargs), false);
        } else {
            $history = [];
        }

        // Shuffle things around if there's history.
        if ($history) {
            // Insert the first (original) request at the start
            array_unshift($history, $r);
            // Get the last request made
            $r = array_pop($history);
            $r->history = $history;
        }

        // If redirects aren't being followed, store the response on the Request for Response.next().
        if (!$allow_redirects) {
            $next = $this->resolve_redirects($r, $request, ...$kwargs, yield_requests: true);
            if ($next->valid()) {
                $r->_next = $next->current();
            }
        }

        if (!$stream) {
            $r->content();
        }

        return $r;
    }

    /** ``HTTPAdapter.send`` and ``build_response``: the transport sends the request, the response gets what requests adds. */
    private function adapter_send(Request $request, array $kwargs): Response
    {
        $request->stream = (bool) ($kwargs['stream'] ?? false);
        $request->timeout = $kwargs['timeout'] ?? null;
        $request->verify = $kwargs['verify'] ?? true;
        $request->cert = $kwargs['cert'] ?? null;

        $response = (self::$transport ?? Curl::send(...))($request);

        // Set encoding.
        $response->encoding = Utils::get_encoding_from_headers($response->headers);
        $response->url = $request->url;

        // Add new cookies from the server.
        Cookies::extract_cookies_to_jar($response->cookies, $request, $response->raw);

        // Give the Response some context.
        $response->request = $request;

        return $response;
    }

    /**
     * Check the environment and merge it with some settings.
     *
     * @return array{stream: mixed, verify: mixed, cert: mixed}
     */
    public function merge_environment_settings(mixed $stream, mixed $verify, mixed $cert): array
    {
        // Gather clues from the surrounding environment.
        if ($this->trust_env) {
            // Look for requests environment configuration and be compatible with cURL.
            if ($verify === true || $verify === null) {
                $verify = getenv('REQUESTS_CA_BUNDLE') ?: getenv('CURL_CA_BUNDLE') ?: $verify;
            }
        }

        // Merge all the kwargs.
        $stream = self::merge_setting($stream, $this->stream);
        $verify = self::merge_setting($verify, $this->verify);
        $cert = self::merge_setting($cert, $this->cert);

        return ['stream' => $stream, 'verify' => $verify, 'cert' => $cert];
    }

    /** Closes all adapters and as such the session */
    public function close(): void
    {
    }

    // SessionRedirectMixin

    /** Receives a Response. Returns a redirect URI or ``null`` */
    public function get_redirect_target(Response $resp): ?string
    {
        if ($resp->is_redirect) {
            // The header bytes are used as they come: UTF-8 (requests re-encodes latin1 to get the same bytes).
            return $resp->headers['location'];
        }

        return null;
    }

    /** Decide whether Authorization header should be removed when redirecting */
    public function should_strip_auth(string $old_url, string $new_url): bool
    {
        $old_parsed = Urllib::urlparse($old_url);
        $new_parsed = Urllib::urlparse($new_url);
        if (Urllib::hostname($old_parsed[1]) !== Urllib::hostname($new_parsed[1])) {
            return true;
        }
        $old_port = Urllib::port($old_parsed[1]);
        $new_port = Urllib::port($new_parsed[1]);
        // Special case: allow http -> https redirect when using the standard
        // ports. This isn't specified by RFC 7235, but is kept to avoid
        // breaking backwards compatibility with older versions of requests
        // that allowed any redirects on the same host.
        if ($old_parsed[0] === 'http' && in_array($old_port, [80, null], true)
            && $new_parsed[0] === 'https' && in_array($new_port, [443, null], true)) {
            return false;
        }

        // Handle default port usage corresponding to scheme.
        $changed_port = $old_port !== $new_port;
        $changed_scheme = $old_parsed[0] !== $new_parsed[0];
        $default_port = [Utils::DEFAULT_PORTS[$old_parsed[0]] ?? null, null];
        if (!$changed_scheme && in_array($old_port, $default_port, true) && in_array($new_port, $default_port, true)) {
            return false;
        }

        // Standard case: root URI must match
        return $changed_port || $changed_scheme;
    }

    /**
     * Receives a Response. Returns a generator of Responses or Requests.
     *
     * @return \Generator<int, Response|Request>
     */
    public function resolve_redirects(
        Response $resp,
        Request $req,
        bool $stream = false,
        mixed $timeout = null,
        bool|string $verify = true,
        mixed $cert = null,
        bool $yield_requests = false,
    ): \Generator {
        $hist = [];  // keep track of history

        $url = $this->get_redirect_target($resp);
        $previous_fragment = Urllib::urlparse($req->url)[4];
        while ($url) {
            $prepared_request = $req->copy();

            // Update history and keep track of redirects.
            // resp.history must ignore the original request in this loop
            $hist[] = $resp;
            $resp->history = array_slice($hist, 1);

            try {
                $resp->content();  // Consume socket so it can be released
            } catch (ChunkedEncodingError|ContentDecodingError|\RuntimeException) {
                $resp->raw->read();
            }

            if (count($resp->history) >= $this->max_redirects) {
                throw new TooManyRedirects("Exceeded {$this->max_redirects} redirects.", null, $resp);
            }

            // Release the connection back into the pool.
            $resp->close();

            // Handle redirection without scheme (see: RFC 1808 Section 4)
            if (str_starts_with($url, '//')) {
                $parsed_rurl = Urllib::urlparse($resp->url);
                $url = implode(':', [$parsed_rurl[0], $url]);
            }

            // Normalize url case and attach previous fragment if needed (RFC 7231 7.1.2)
            $parsed = Urllib::urlparse($url);
            if ($parsed[4] === '' && $previous_fragment !== '') {
                $parsed[4] = $previous_fragment;
            } elseif ($parsed[4] !== '') {
                $previous_fragment = $parsed[4];
            }
            $url = Urllib::urlunsplit($parsed);

            // Facilitate relative 'location' headers, as allowed by RFC 7231.
            // (e.g. '/path/to/resource' instead of 'http://domain.tld/path/to/resource')
            // Compliant with RFC3986, we percent encode the url.
            if ($parsed[1] === '') {
                $url = Urllib::urljoin($resp->url, Utils::requote_uri($url));
            } else {
                $url = Utils::requote_uri($url);
            }

            $prepared_request->url = $url;

            $this->rebuild_method($prepared_request, $resp);

            // https://github.com/psf/requests/issues/1084
            if (!in_array($resp->status_code, [307, 308], true)) {
                // https://github.com/psf/requests/issues/3490
                $purged_headers = ['Content-Length', 'Content-Type', 'Transfer-Encoding'];
                foreach ($purged_headers as $header) {
                    $prepared_request->headers->pop($header);
                }
                $prepared_request->body = null;
            }

            $headers = $prepared_request->headers;
            $headers->pop('Cookie');

            // Extract any cookies sent on the response to the cookiejar
            // in the new request. Because we've mutated our copied prepared
            // request, use the old one that we haven't yet touched.
            Cookies::extract_cookies_to_jar($prepared_request->_cookies, $req, $resp->raw);
            Cookies::merge_cookies($prepared_request->_cookies, $this->cookies);
            $prepared_request->prepare_cookies($prepared_request->_cookies);

            // Rebuild auth and proxy information.
            $this->rebuild_auth($prepared_request, $resp);

            // Override the original request.
            $req = $prepared_request;

            if ($yield_requests) {
                yield $req;
            } else {
                $resp = $this->send(
                    $req,
                    stream: $stream,
                    timeout: $timeout,
                    verify: $verify,
                    cert: $cert,
                    allow_redirects: false,
                );

                Cookies::extract_cookies_to_jar($this->cookies, $prepared_request, $resp->raw);

                // extract redirect url, if any, for the next loop
                $url = $this->get_redirect_target($resp);
                yield $resp;
            }
        }
    }

    /**
     * When being redirected we may want to strip authentication from the
     * request to avoid leaking credentials. This method intelligently removes
     * and reapplies authentication where possible to avoid credential loss.
     */
    public function rebuild_auth(Request $prepared_request, Response $response): void
    {
        $headers = $prepared_request->headers;
        $url = $prepared_request->url;

        if (isset($headers['Authorization']) && $this->should_strip_auth($response->request->url, $url)) {
            // If we get redirected to a new host, we should strip out any
            // authentication headers.
            unset($headers['Authorization']);
        }
    }

    /**
     * When being redirected we may want to change the method of the request
     * based on certain specs or browser behavior.
     */
    public function rebuild_method(Request $prepared_request, Response $response): void
    {
        $method = $prepared_request->method;

        // https://tools.ietf.org/html/rfc7231#section-6.4.4
        if ($response->status_code === 303 && $method !== 'HEAD') {
            $method = 'GET';
        }

        // Do what the browsers do, despite standards...
        // First, turn 302s into GETs.
        if ($response->status_code === 302 && $method !== 'HEAD') {
            $method = 'GET';
        }

        // Second, if a POST is responded to with a 301, turn it into a GET.
        // This bizarre behaviour is explained in Issue 1704.
        if ($response->status_code === 301 && $method === 'POST') {
            $method = 'GET';
        }

        $prepared_request->method = $method;
    }
}
