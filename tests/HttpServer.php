<?php

namespace Tests;

/**
 * A throw-away HTTP server on localhost for the tests of the HTTP layer (the `HTTPServer` of the upstream tests):
 * a child PHP process on a free port, one connection at a time, `Connection: close` after each response.
 *
 * Modes: `http` (`/json`, `/text`, `/stream?size_mb=`, `/redirect`, `/echo-cookie`, `/gzip`, `/post`, else 404), `normal` (200 json), `connection_error` (the
 * connection is closed without a response), `timeout` (no answer for 2 seconds).
 */
final class HttpServer
{
    private const CODE = <<<'PHP'
        $mode = $argv[1];
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";
        while (true) {
            $c = @stream_socket_accept($server, 60);
            if (!$c) {
                continue;
            }
            $request = '';
            while (!str_contains($request, "\r\n\r\n")) {
                $data = fread($c, 8192);
                if ($data === '' || $data === false) {
                    break;
                }
                $request .= $data;
            }
            preg_match('~^(\w+) (\S+)~', $request, $m);
            $path = $m[2] ?? '/';
            $reply = static function ($status, $type, $body) use ($c) {
                @fwrite($c, "HTTP/1.1 $status\r\nContent-Type: $type\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n$body");
            };
            if ($mode === 'connection_error') {
                // closed abruptly
            } elseif ($mode === 'timeout') {
                sleep(2);
            } elseif ($mode === 'normal') {
                $reply('200 OK', 'application/json', '{"success": true}');
            } elseif ($path === '/json') {
                $reply('200 OK', 'application/json', json_encode(['message' => 'Hello, World!', 'status' => 'success', 'data' => [1, 2, 3]]));
            } elseif ($path === '/text') {
                $reply('200 OK', 'text/plain', 'This is a plain text response for testing purposes.');
            } elseif ($path === '/redirect') {
                @fwrite($c, "HTTP/1.1 302 Found\r\nLocation: /echo-cookie\r\nSet-Cookie: hop=1; Path=/\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
            } elseif ($path === '/echo-cookie') {
                preg_match('~^Cookie: (.*)$~mi', $request, $cookie);
                $reply('200 OK', 'text/plain', trim($cookie[1] ?? 'none'));
            } elseif ($path === '/gzip') {
                $body = gzencode('compressed hello');
                @fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Encoding: gzip\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n$body");
            } elseif ($path === '/post' || str_starts_with($path, '/post?')) {
                $body = substr($request, strpos($request, "\r\n\r\n") + 4);
                preg_match('~^Content-Type: (.*)$~mi', $request, $type);
                $reply('200 OK', 'text/plain', trim($m[1]).' '.$path.' '.trim($type[1] ?? '').' '.$body);
            } elseif (str_starts_with($path, '/stream')) {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                $size_mb = (float) ($query['size_mb'] ?? 0.0005);
                @fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nConnection: close\r\nTransfer-Encoding: chunked\r\n\r\n");
                if ($size_mb >= 1) {
                    $chunk_size = 1024 * 1024;
                    $num_chunks = (int) $size_mb;
                } else {
                    $chunk_size = 100;
                    $num_chunks = max(1, (int) ($size_mb * 1024 * 1024 / $chunk_size));
                }
                for ($i = 0; $i < $num_chunks; ++$i) {
                    $chunk = "chunk_{$i}_".str_repeat('x', $chunk_size - strlen("chunk_{$i}_"));
                    // the client closes early in the tests that do not read it all
                    if (@fwrite($c, dechex(strlen($chunk))."\r\n$chunk\r\n") === false) {
                        break;
                    }
                }
                @fwrite($c, "0\r\n\r\n");
            } else {
                $reply('404 Not Found', 'text/plain', 'Not Found');
            }
            @fclose($c);
        }
        PHP;

    /** @var resource */
    private $process;
    public readonly string $base_url;

    public function __construct(string $mode = 'http')
    {
        $this->process = proc_open([PHP_BINARY, '-r', self::CODE, $mode], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $port = (int) trim((string) fgets($pipes[1]));
        $this->base_url = "http://127.0.0.1:$port";
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
