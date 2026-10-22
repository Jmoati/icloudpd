<?php

namespace Foundation\Http;

/**
 * File-like object representation of a response (``Response.raw``): what urllib3's ``HTTPResponse`` is to requests.
 *
 * It carries the header lines as received (``msg``, an ``http.client.HTTPMessage``: the cookie jar reads
 * every ``Set-Cookie`` line from it) and the body, either whole or pulled piece by piece from a transport.
 * The body is already decoded (``Content-Encoding``).
 */
final class Raw
{
    /** @var list<array{0: string, 1: string}> header lines as received: [name, value] */
    public array $msg;
    private string $buffer;
    private int $offset = 0;
    private bool $eof;
    private bool $closed = false;

    /**
     * @param list<array{0: string, 1: string}> $msg
     * @param ?\Closure(): ?string $pull the next piece of the body, null at the end (null: ``$body`` is everything)
     * @param ?\Closure(): void $on_close called once when the response is closed before the end
     */
    public function __construct(
        array $msg = [],
        string $body = '',
        private ?\Closure $pull = null,
        private ?\Closure $on_close = null,
    ) {
        $this->msg = $msg;
        $this->buffer = $body;
        $this->eof = $pull === null;
    }

    /** ``HTTPResponse.msg`` is the message and ``_original_response.msg.get_all`` its accessor: both are this object. */
    public function info(): self
    {
        return $this;
    }

    /**
     * Return a list of all the values for the named header (case-insensitive), or ``$failobj`` if there are none.
     *
     * @return list<string>
     */
    public function get_all(string $name, array $failobj = []): array
    {
        $name = strtolower($name);
        $values = [];
        foreach ($this->msg as [$k, $v]) {
            if (strtolower($k) === $name) {
                $values[] = $v;
            }
        }

        return $values ?: $failobj;
    }

    /** The headers as requests exposes them: a ``CaseInsensitiveDict``, repeated headers joined with ", ". */
    public function headers(): CaseInsensitiveDict
    {
        $combined = [];
        foreach ($this->msg as [$k, $v]) {
            $lower = strtolower($k);
            if (isset($combined[$lower])) {
                $combined[$lower][1] .= ', '.$v;
            } else {
                $combined[$lower] = [$k, $v];
            }
        }
        $headers = new CaseInsensitiveDict();
        foreach ($combined as [$k, $v]) {
            $headers[$k] = $v;
        }

        return $headers;
    }

    /** Read up to ``$amt`` bytes (everything when null); '' at the end of the body. */
    public function read(?int $amt = null): string
    {
        if ($this->closed) {
            return '';
        }
        while (!$this->eof && ($amt === null || $this->available() < $amt)) {
            $piece = ($this->pull)();
            if ($piece === null) {
                $this->eof = true;
                $this->pull = $this->on_close = null;
            } else {
                // only the unread rest is copied, the consumer reads small chunks of a big buffer
                $this->buffer = substr($this->buffer, $this->offset).$piece;
                $this->offset = 0;
            }
        }
        $amt = $amt === null ? $this->available() : min($amt, $this->available());
        $data = substr($this->buffer, $this->offset, $amt);
        $this->offset += $amt;
        if ($this->offset >= strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        }

        return $data;
    }

    private function available(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    /**
     * A generator of chunks of ``$amt`` bytes (the last one may be shorter) until the body is exhausted.
     *
     * @return \Generator<int, string>
     */
    public function stream(int $amt): \Generator
    {
        while (!$this->isclosed()) {
            $data = $this->read($amt);
            if ($data !== '') {
                yield $data;
            }
            if ($this->eof && $this->available() === 0) {
                break;
            }
        }
    }

    /** True when the body was read to the end (or the response was closed). */
    public function isclosed(): bool
    {
        return $this->closed || ($this->eof && $this->available() === 0);
    }

    public function close(): void
    {
        if (!$this->closed && !$this->eof) {
            $on_close = $this->on_close;
            $this->pull = $this->on_close = null;
            $this->eof = true;
            if ($on_close !== null) {
                $on_close();
            }
        }
        $this->closed = true;
        $this->buffer = '';
        $this->offset = 0;
    }

    /** Release the connection back to the pool: it is released as soon as the body is read to the end or closed. */
    public function release_conn(): void
    {
    }
}
