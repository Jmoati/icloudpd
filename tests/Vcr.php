<?php

namespace Tests;

use Foundation\Http\Session;
use Tests\Vcr\Cassette;

/**
 * The `vcr` of the upstream tests: replays HTTP cassettes (`VCR(decode_compressed_response=True, record_mode="none")`).
 *
 * `use_cassette($path, $fn)` puts a replayer in `Session::$transport` while `$fn` runs. The cassette is
 * `tests/vcr_cassettes/<name>.json.gz` (converted from the upstream YAML by `tools/convert-cassettes.py`); the
 * path may be that file, the upstream `.yml` path, or a bare name. `$fn` receives the `Cassette` (`all_played`).
 */
final class Vcr
{
    public static function use_cassette(string $path, callable $fn): mixed
    {
        $cassette = new Cassette(self::resolve($path));
        $previous = Session::$transport;
        Session::$transport = $cassette->play(...);
        try {
            return $fn($cassette);
        } finally {
            Session::$transport = $previous;
        }
    }

    /** `<dir>/<name>.yml`, `<dir>/<name>.json.gz` or `<name>` to the cassette file */
    public static function resolve(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }
        $name = preg_replace('/(\.json\.gz|\.yml)$/', '', basename($path));
        foreach ([dirname($path), __DIR__.'/vcr_cassettes'] as $dir) {
            if (is_file("$dir/$name.json.gz")) {
                return "$dir/$name.json.gz";
            }
        }
        throw new \RuntimeException("Cassette not found: $path");
    }
}
