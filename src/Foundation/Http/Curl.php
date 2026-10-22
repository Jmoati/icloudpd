<?php

namespace Foundation\Http;

/**
 * The default transport of ``Session`` (``Session::$transport``): sends one ``Request`` with ext-curl, the role of
 * requests' ``HTTPAdapter`` + urllib3.
 *
 * Redirects, cookies and decompression of the body are not done here by curl's own logic: redirects and cookies
 * belong to ``Session`` (one transport call per hop, like requests), ``CURLOPT_ENCODING`` decodes the body like
 * urllib3 does. The response is returned as soon as its headers are in; the body is pulled from curl by the
 * ``Raw`` when it is read (``stream=true`` responses are not buffered).
 *
 * ponytail: HTTP/1.1 only (like requests: a reason phrase on the status line), no pool limits, no retries, no
 * proxy options (curl reads the proxy environment variables itself).
 */
final class Curl
{
    private static ?\CurlShareHandle $share = null;

    private \CurlHandle $ch;
    private \CurlMultiHandle $mh;
    private bool $attached = false;
    /** @var list<array{0: string, 1: string}> */
    private array $msg = [];
    private int $status = 0;
    private string $reason = '';
    private bool $headers_done = false;
    private string $buffer = '';
    private bool $done = false;
    private float $last_progress;
    /** counts the pieces of the response that arrived (headers, body): what `step()` waits for */
    private int $progress = 0;
    private ?float $read_timeout = null;

    public static function send(Request $request): Response
    {
        return (new self($request))->start();
    }

    private function __construct(private readonly Request $request)
    {
        $this->ch = curl_init();
        $this->mh = curl_multi_init();

        $method = $request->method;
        $headers = [];
        $accept_encoding = '';
        foreach ($request->headers as $name => $value) {
            if (strtolower($name) === 'accept-encoding') {
                // curl decodes what it announces
                $accept_encoding = (string) $value;
                continue;
            }
            $headers[] = $value === '' ? "$name;" : "$name: $value";
        }
        $headers[] = 'Expect:';

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => $accept_encoding,
            CURLOPT_BUFFERSIZE => 524288,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SHARE => self::share(),
            CURLOPT_HEADERFUNCTION => $this->header_function(...),
            CURLOPT_WRITEFUNCTION => $this->write_function(...),
        ];
        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        }

        // timeout: seconds, or (connect, read). The read timeout is the idle time between two pieces of the
        // response, like urllib3's: curl has no such option, `step()` enforces it.
        $timeout = $request->timeout;
        [$connect, $read] = is_array($timeout) ? $timeout : [$timeout, $timeout];
        if ($connect !== null && $connect > 0) {
            $options[CURLOPT_CONNECTTIMEOUT_MS] = max(1, (int) round($connect * 1000));
        }
        $this->read_timeout = $read !== null && $read > 0 ? (float) $read : null;

        $verify = $request->verify;
        if ($verify === false) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif (is_string($verify) && $verify !== '') {
            $options[is_dir($verify) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $verify;
        }
        $cert = $request->cert;
        if (is_string($cert)) {
            $options[CURLOPT_SSLCERT] = $cert;
        } elseif (is_array($cert) && count($cert) === 2) {
            [$options[CURLOPT_SSLCERT], $options[CURLOPT_SSLKEY]] = $cert;
        }

        curl_setopt_array($this->ch, $options);
    }

    /** One share for the process: connections (and DNS, TLS sessions) are reused between requests like a pool. */
    private static function share(): \CurlShareHandle
    {
        if (self::$share === null) {
            self::$share = curl_share_init();
            foreach ([CURL_LOCK_DATA_CONNECT, CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_SSL_SESSION] as $data) {
                curl_share_setopt(self::$share, CURLSHOPT_SHARE, $data);
            }
        }

        return self::$share;
    }

    private function header_function(\CurlHandle $ch, string $line): int
    {
        $length = strlen($line);
        $this->last_progress = microtime(true);
        ++$this->progress;
        $line = rtrim($line, "\r\n");
        if (str_starts_with($line, 'HTTP/')) {
            // a status line: the final one, or an interim 1xx response
            [, $code, $reason] = array_pad(explode(' ', $line, 3), 3, '');
            $this->msg = [];
            $this->headers_done = false;
            $this->status = (int) $code;
            $this->reason = $reason;
        } elseif ($line === '') {
            if ($this->status >= 200) {
                $this->headers_done = true;
            }
        } elseif (($i = strpos($line, ':')) !== false) {
            $this->msg[] = [substr($line, 0, $i), trim(substr($line, $i + 1), " \t")];
        }

        return $length;
    }

    private function write_function(\CurlHandle $ch, string $data): int
    {
        $this->last_progress = microtime(true);
        ++$this->progress;
        $this->buffer .= $data;

        return strlen($data);
    }

    /** Send the request and wait for the headers. */
    private function start(): Response
    {
        $this->last_progress = microtime(true);
        curl_multi_add_handle($this->mh, $this->ch);
        $this->attached = true;
        while (!$this->headers_done && !$this->done) {
            $this->step();
        }

        $raw = $this->done
            ? new Raw($this->msg, $this->take())
            : new Raw($this->msg, $this->take(), $this->pull(...), $this->release(...));
        $response = new Response();
        $response->status_code = $this->status;
        $response->reason = $this->reason;
        $response->headers = $raw->headers();
        $response->raw = $raw;
        if ($this->done) {
            $this->release();
        }

        return $response;
    }

    private function take(): string
    {
        $data = $this->buffer;
        $this->buffer = '';

        return $data;
    }

    /** The next piece of the body, null at the end (``Raw``'s pull). */
    private function pull(): ?string
    {
        $this->last_progress = microtime(true);
        while ($this->buffer === '' && !$this->done) {
            $this->step();
        }
        if ($this->buffer !== '') {
            return $this->take();
        }
        $this->release();

        return null;
    }

    /** Let curl work once, wait for the socket; the transfer ends here (``done``) with its error, if any. */
    private function step(): void
    {
        $progress = $this->progress;
        do {
            $status = curl_multi_exec($this->mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($status !== CURLM_OK) {
            $this->release();
            throw new ConnectionError(curl_multi_strerror($status));
        }
        if (!$running) {
            $this->done = true;
            $info = curl_multi_info_read($this->mh);
            if ($info !== false && $info['result'] !== CURLE_OK) {
                $error = $this->error($info['result'], curl_error($this->ch) ?: curl_strerror($info['result']));
                $this->release();
                throw $error;
            }

            return;
        }
        if ($this->progress !== $progress) {
            return;
        }
        $wait = 1.0;
        if ($this->read_timeout !== null) {
            $left = $this->read_timeout - (microtime(true) - $this->last_progress);
            if ($left <= 0) {
                $this->release();
                throw $this->headers_done ? new ConnectionError('Read timed out.') : new Timeout('Read timed out.');
            }
            $wait = min($wait, $left);
        }
        if (curl_multi_select($this->mh, $wait) === -1) {
            usleep(1000);
        }
    }

    /** Map a curl error to the requests exception urllib3 and requests raise for it. */
    private function error(int $errno, string $message): RequestException
    {
        $message = "$message (curl error $errno)";
        // after the headers: what urllib3 reports while reading the body
        if ($this->headers_done) {
            return match ($errno) {
                28 => new ConnectionError($message),  // CURLE_OPERATION_TIMEDOUT: ReadTimeoutError
                61 => new ContentDecodingError($message),  // CURLE_BAD_CONTENT_ENCODING
                default => new ChunkedEncodingError($message),  // ProtocolError
            };
        }

        return match (true) {
            $errno === 28 => new Timeout($message),  // CURLE_OPERATION_TIMEDOUT
            in_array($errno, [35, 51, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91], true) => new SSLError($message),  // CURLE_SSL_*
            $errno === 61 => new ContentDecodingError($message),  // CURLE_BAD_CONTENT_ENCODING
            default => new ConnectionError($message),
        };
    }

    /** Drop the transfer: the connection goes back to the share (finished) or is closed (abandoned). */
    private function release(): void
    {
        if ($this->attached) {
            curl_multi_remove_handle($this->mh, $this->ch);
            $this->attached = false;
        }
        $this->done = true;
    }
}
