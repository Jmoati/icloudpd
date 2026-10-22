<?php

namespace Foundation\Http;

use Foundation\Patch;

/**
 * The ``Response`` object, which contains a server's response to an HTTP request (requests.models.Response).
 *
 * ``ok()``, ``text()`` and ``content()`` are also readable as the properties ``ok``, ``text`` and ``content``
 * (Python's ``@property``).
 *
 * ponytail: no pickling, ``links``, ``iter_lines``, ``decode_unicode``, nor ``__iter__``/``__bool__``.
 */
class Response
{
    public const REDIRECT_STATI = [301, 302, 303, 307, 308];
    public const CONTENT_CHUNK_SIZE = 10 * 1024;

    /** @var string|false|null false: not read yet */
    public string|false|null $_content = false;
    public bool $_content_consumed = false;
    public ?Request $_next = null;

    /** Integer Code of responded HTTP Status, e.g. 404 or 200. */
    public ?int $status_code = null;

    /**
     * Case-insensitive Dictionary of Response Headers.
     * For example, ``headers['content-encoding']`` will return the value of a ``'Content-Encoding'`` response header.
     * (an array is converted)
     */
    public CaseInsensitiveDict $headers {
        set(CaseInsensitiveDict|array $value) => $value instanceof CaseInsensitiveDict ? $value : new CaseInsensitiveDict($value);
    }

    /**
     * File-like object representation of response (for advanced usage), a ``Raw`` (or anything with ``isclosed()`` and ``stream()``/``read()``).
     * Use of ``raw`` requires that ``stream=true`` be set on the request.
     */
    public ?object $raw = null;

    /** Final URL location of Response. */
    public ?string $url = null;

    /** Encoding to decode with when accessing r.text. */
    public ?string $encoding = null;

    /**
     * A list of ``Response`` objects from the history of the Request. Any redirect responses will end up here.
     * The list is sorted from the oldest to the most recent request.
     *
     * @var list<Response>
     */
    public array $history = [];

    /** Textual reason of responded HTTP Status, e.g. "Not Found" or "OK". */
    public ?string $reason = null;

    /** A CookieJar of Cookies the server sent back. */
    public iterable $cookies;

    /** The amount of time elapsed between sending the request and finishing parsing the headers (seconds). */
    public float $elapsed = 0.0;

    /** The ``Request`` object to which this is a response. */
    public ?Request $request = null;

    /**
     * ``new Response()`` is requests' empty ``Response()``; ``new Response(200, [], '')`` is a response that was
     * already read (the upstream tests' ``MagicMock`` responses).
     */
    public function __construct(?int $status_code = null, iterable $headers = [], ?string $content = null)
    {
        $this->headers = new CaseInsensitiveDict($headers);
        $this->cookies = new CookieJar();
        $this->status_code = $status_code;
        if ($content !== null) {
            $this->_content = $content;
            $this->_content_consumed = true;
        }
    }

    public function __toString(): string
    {
        return "<Response [$this->status_code]>";
    }

    /**
     * Returns true if :attr:`status_code` is less than 400, false if not.
     *
     * This attribute checks if the status code of the response is between
     * 400 and 600 to see if there was a client error or a server error. If
     * the status code is between 200 and 400, this will return true. This
     * is **not** a check to see if the response code is ``200 OK``.
     */
    public function ok(): bool
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this);
        }
        try {
            $this->raise_for_status();
        } catch (HTTPError) {
            return false;
        }

        return true;
    }

    public bool $ok {
        get => $this->ok();
    }

    /**
     * True if this Response is a well-formed HTTP redirect that could have
     * been processed automatically (by ``Session::resolve_redirects``).
     */
    public bool $is_redirect {
        get => isset($this->headers['location']) && in_array($this->status_code, self::REDIRECT_STATI, true);
    }

    /** True if this Response one of the permanent versions of redirect. */
    public bool $is_permanent_redirect {
        get => isset($this->headers['location']) && in_array($this->status_code, [301, 308], true);
    }

    /** Returns a Request for the next request in a redirect chain, if there is one. */
    public ?Request $next {
        get => $this->_next;
    }

    /** The apparent encoding (ponytail: ``mb_detect_encoding`` for chardet). */
    public string $apparent_encoding {
        get => mb_detect_encoding((string) $this->content(), ['UTF-8', 'Windows-1252'], true) ?: 'utf-8';
    }

    /**
     * Iterates over the response data.  When stream=true is set on the
     * request, this avoids reading the content at once into memory for
     * large responses.  The chunk size is the number of bytes it should
     * read into memory.  This is not necessarily the length of each item
     * returned as decoding can take place.
     *
     * chunk_size must be an int or null. A value of null will
     * function differently depending on the value of `stream`.
     * stream=true will read data as it arrives in whatever size the
     * chunks are received. If stream=false, data is returned as
     * a single chunk.
     *
     * @return \Generator<int, string>
     */
    public function iter_content(?int $chunk_size = 1, bool $decode_unicode = false): \Generator
    {
        $generate = function () use ($chunk_size): \Generator {
            if ($this->raw !== null) {
                // Special case for urllib3.
                if (method_exists($this->raw, 'stream')) {
                    yield from $this->raw->stream($chunk_size ?? 65536);
                } else {
                    // Standard file-like object.
                    while (true) {
                        $chunk = $this->raw->read($chunk_size);
                        if ($chunk === '' || $chunk === null) {
                            break;
                        }
                        yield $chunk;
                    }
                }
            }

            $this->_content_consumed = true;
        };

        if ($this->_content_consumed && is_bool($this->_content)) {
            throw new StreamConsumedError();
        }
        if ($decode_unicode) {
            throw new \BadMethodCallException('ponytail: decode_unicode is not ported');
        }
        // simulate reading small chunks of the content
        $reused_chunks = Utils::iter_slices(is_string($this->_content) ? $this->_content : '', $chunk_size);

        return $this->_content_consumed ? $reused_chunks : $generate();
    }

    /** Content of the response, in bytes. */
    public function content(): ?string
    {
        if ($this->_content === false) {
            // Read the contents.
            if ($this->_content_consumed) {
                throw new \RuntimeException('The content for this response was already consumed');
            }

            if ($this->status_code === 0 || $this->raw === null) {
                $this->_content = null;
            } else {
                $content = '';
                foreach ($this->iter_content(self::CONTENT_CHUNK_SIZE) as $chunk) {
                    $content .= $chunk;
                }
                $this->_content = $content;
            }
        }

        $this->_content_consumed = true;

        // don't need to release the connection; that's been handled by urllib3
        // since we exhausted the data.
        return $this->_content;
    }

    public ?string $content {
        get => $this->content();
    }

    /**
     * Content of the response, in unicode.
     *
     * If Response.encoding is null, encoding will be guessed.
     *
     * The encoding of the response content is determined based solely on HTTP
     * headers, following RFC 2616 to the letter. If you can take advantage of
     * non-HTTP knowledge to make a better guess at the encoding, you should
     * set ``r.encoding`` appropriately before accessing this property.
     */
    public function text(): string
    {
        // Try charset from content-type
        $encoding = $this->encoding;

        $content = $this->content();
        if ($content === null || $content === '') {
            return '';
        }

        // Fallback to auto-detected encoding.
        if ($this->encoding === null) {
            $encoding = $this->apparent_encoding;
        }

        // Decode unicode from given encoding.
        if (in_array(strtolower($encoding), ['utf-8', 'utf8'], true) && mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        $replacement = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_convert_encoding($content, 'UTF-8', $encoding);
        } catch (\ValueError) {
            // A ValueError is thrown if the encoding was not found which could
            // indicate a misspelling or similar mistake.
            //
            // So we try blindly encoding.
            return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($replacement);
        }
    }

    public string $text {
        get => $this->text();
    }

    /**
     * Returns the json-encoded content of a response, if any (JSON objects are associative arrays).
     *
     * @throws JSONDecodeError If the response body does not contain valid json.
     */
    public function json(mixed ...$kwargs): mixed
    {
        $content = $this->content();
        if (!$this->encoding && $content !== null && strlen($content) > 3) {
            // No encoding set. JSON RFC 4627 section 3 states we should expect
            // UTF-8, -16 or -32. Detect which one to use; If the detection or
            // decoding fails, fall back to `self.text` (using the detected encoding to make
            // a best guess).
            $encoding = Utils::guess_json_utf($content);
            if ($encoding !== null) {
                if ($encoding === 'UTF-8') {
                    $decoded = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
                } else {
                    $decoded = mb_convert_encoding($content, 'UTF-8', $encoding);
                }
                try {
                    return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    if ($e->getCode() !== JSON_ERROR_UTF8) {
                        throw new JSONDecodeError($e->getMessage(), 0, $e);
                    }
                    // Wrong UTF codec detected; usually because it's not UTF-8
                    // but some other 8-bit codec.  This is an RFC violation,
                    // and the server didn't bother to tell us what codec *was*
                    // used.
                }
            }
        }

        try {
            return json_decode($this->text(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Catch JSON-related errors and raise as requests.JSONDecodeError
            throw new JSONDecodeError($e->getMessage(), 0, $e);
        }
    }

    /** Raises :class:`HTTPError`, if one occurred. */
    public function raise_for_status(): void
    {
        $http_error_msg = '';
        $reason = $this->reason;

        if ($this->status_code !== null && 400 <= $this->status_code && $this->status_code < 500) {
            $http_error_msg = "$this->status_code Client Error: $reason for url: $this->url";
        } elseif ($this->status_code !== null && 500 <= $this->status_code && $this->status_code < 600) {
            $http_error_msg = "$this->status_code Server Error: $reason for url: $this->url";
        }

        if ($http_error_msg !== '') {
            throw new HTTPError($http_error_msg, null, $this);
        }
    }

    /**
     * Releases the connection back to the pool. Once this method has been
     * called the underlying ``raw`` object must not be accessed again.
     *
     * *Note: Should not normally need to be called explicitly.*
     */
    public function close(): void
    {
        if (!$this->_content_consumed) {
            $this->raw?->close();
        }

        if ($this->raw !== null && method_exists($this->raw, 'release_conn')) {
            $this->raw->release_conn();
        }
    }
}
