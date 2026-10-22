<?php

namespace Tests\Vcr;

use Foundation\Http\Raw;
use Foundation\Http\Request;
use Foundation\Http\Response;
use Foundation\Http\Urllib;

/**
 * A cassette played back like vcrpy does with its defaults: a request is matched on method, scheme, host, port,
 * path and query; each recorded interaction is played once, the first unplayed match in recording order; a
 * request without a match fails (`record_mode="none"`).
 */
final class Cassette
{
    /** @var list<array{0: array, 1: array}> [request, response] as recorded */
    private array $data;
    /** @var list<array> the matching key of each recorded request */
    private array $keys;
    /** @var list<int> */
    private array $play_counts;

    public bool $all_played {
        get => !in_array(0, $this->play_counts, true);
    }

    public function __construct(public readonly string $path)
    {
        $json = json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        $this->data = [];
        foreach ($json['interactions'] as $interaction) {
            $this->data[] = [$interaction['request'], $interaction['response']];
        }
        $this->keys = array_map(fn ($interaction) => self::key($interaction[0]['method'], $interaction[0]['uri']), $this->data);
        $this->play_counts = array_fill(0, count($this->data), 0);
    }

    /** `vcr.matchers`: method, scheme, host, port, path, query (sorted `parse_qsl`) */
    private static function key(string $method, string $uri): array
    {
        [$scheme, $netloc, $path, $query] = Urllib::urlsplit($uri);
        $port = Urllib::port($netloc) ?? ['https' => 443, 'http' => 80][$scheme] ?? null;
        $pairs = Urllib::parse_qsl($query);
        sort($pairs);

        return [$method, $scheme, Urllib::hostname($netloc), $port, $path, $pairs];
    }

    /** The transport: the recorded response for the request */
    public function play(Request $request): Response
    {
        $key = self::key($request->method, $request->url);
        foreach ($this->keys as $i => $recorded) {
            if ($this->play_counts[$i] === 0 && $recorded === $key) {
                ++$this->play_counts[$i];

                return self::response($this->data[$i][1]);
            }
        }

        throw new CannotOverwriteExistingCassetteException(sprintf(
            "Can't overwrite existing cassette (%s) in your current record mode ('none').\nNo match for the request (<Request (%s) %s>) was found.\nNo similar requests, that have not been played, were found.",
            var_export($this->path, true),
            $request->method,
            $request->url,
        ));
    }

    /** The recorded bodies are already decoded (`decode_compressed_response`): `Content-Encoding` is dropped */
    private static function response(array $recorded): Response
    {
        $msg = [];
        foreach ($recorded['headers'] as $name => $values) {
            if (strtolower($name) === 'content-encoding') {
                continue;
            }
            // the headers of the older cassettes are strings, not lists
            foreach ((array) $values as $value) {
                $msg[] = [$name, $value];
            }
        }
        $body = $recorded['body'];
        $body = $body === null ? '' : (isset($body['base64']) ? base64_decode($body['base64']) : $body['string']);

        $raw = new Raw($msg, $body);
        $response = new Response();
        $response->status_code = $recorded['status']['code'];
        $response->reason = $recorded['status']['message'];
        $response->headers = $raw->headers();
        $response->raw = $raw;

        return $response;
    }
}
