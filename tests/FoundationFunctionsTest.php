<?php

namespace Tests;

use Foundation\Core;
use Foundation\Foundation;
use Foundation\Json;
use Foundation\Py;
use Foundation\StopIteration;
use Foundation\String_;
use Foundation\StringUtils;
use Foundation\VersionInfo;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rest of `foundation` (what the doctests and `test_json_rules` do not reach): `Foundation`, `Core`,
 * `Json`, `String_`, `StringUtils`. Expected values of the providers were produced by running Python.
 */
class FoundationFunctionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Foundation::$version_info = null;
        parent::tearDown();
    }

    private static function list_(iterable $it): array
    {
        return is_array($it) ? array_values($it) : iterator_to_array($it, false);
    }

    #[DataProvider('obfuscate_cases')]
    public function test_obfuscate(string $input, string $expected): void
    {
        $this->assertSame($expected, String_::obfuscate($input));
    }

    public function test_obfuscate_is_only_for_str(): void
    {
        $this->expectException(\BadMethodCallException::class);
        String_::obfuscate(12);
    }

    #[DataProvider('extract_context_cases')]
    public function test_extract_context(string $context, string $key, string $expected): void
    {
        $this->assertSame([$expected, 1], Json::extract_context($context, [$key, 1]));
        $this->assertSame([$expected, 1], Json::extract_context($context, \SplFixedArray::fromArray([$key, 1])));
    }

    #[DataProvider('apply_rules_cases')]
    public function test_apply_rules_like_pyicloud_ipd(string $name, mixed $doc, array $patterns, string $kind, string $expected): void
    {
        $fn = match ($kind) {
            'obf' => String_::obfuscate(...),
            'none' => Core::constant(null),
            'const' => Core::constant('REDACTED'),
        };
        $rules = self::list_(Core::map_(fn ($p) => [$p, $fn], Json::compile_patterns($patterns)));
        $this->assertSame($expected, Py::json_dumps(Json::apply_rules('', $rules, $doc)), $name);
    }

    public function test_apply_rules_accepts_a_stdClass_dict(): void
    {
        $rules = self::list_(Core::map_(fn ($p) => [$p, Core::constant('x')], Json::compile_patterns(['^b$'])));
        $this->assertSame(['a' => '1', 'b' => 'x'], Json::apply_rules('', $rules, (object) ['a' => '1', 'b' => '2']));
    }

    public function test_compile_patterns_are_case_insensitive_unicode_regexes(): void
    {
        [$p] = self::list_(Json::compile_patterns(['x-apple-.*é']));
        $this->assertSame(1, preg_match($p, 'X-APPLE-ID-É'));
        $this->assertSame(0, preg_match($p, 'y-apple-id-é'));
        $this->assertSame($p, Json::re_compile_ignorecase('x-apple-.*é'));
        $this->assertSame(1, preg_match(Json::re_compile_flag('', 'a~b'), 'a~b'), 'no delimiter to escape');
        $this->assertSame(0, preg_match(Json::re_compile_flag('', 'a'), 'A'));
    }

    public function test_first_helpers(): void
    {
        $this->assertSame(1, Json::first([1, 2]));
        $this->assertInstanceOf(StopIteration::class, Json::first([]));
        $this->assertSame('d', Json::first_or_default([], 'd'));
        $this->assertSame(0, Json::first_or_default([0, 1], 'd'));
        $this->assertNull(Json::first_or_none([]));
        $this->assertSame('a', Json::first_or_none((fn () => yield 'a')()));
        $this->assertTrue(Json::is_none(null));
        $this->assertFalse(Json::is_none(0));
        $this->assertTrue(Json::is_not_none(0));
        $this->assertSame([1], self::list_(Json::filter_not_none([null, 1, null])));
        $this->assertSame([0, '', false], self::list_(Json::filter_not_none([0, null, '', false])));
    }

    public function test_first_matching_rule(): void
    {
        $rules = self::list_(Core::map_(fn ($p) => [$p, Core::constant('r')], Json::compile_patterns(['^a', 'b$'])));
        $this->assertSame($rules[1], Json::first_matching_rule('xb', $rules));
        $this->assertSame($rules[0], Json::first_matching_rule('ab', $rules));
        $this->assertNull(Json::first_matching_rule('zz', $rules));
    }

    #[DataProvider('version_cases')]
    public function test_version_info_formatted(int $ts, string $expected): void
    {
        Foundation::$version_info = new VersionInfo(version: '1.2.3', commit_sha: 'abc1234', commit_timestamp: $ts);
        $this->assertSame($expected, Foundation::version_info_formatted());
    }

    public function test_version_info_default(): void
    {
        $vi = Foundation::version_info();
        $this->assertSame(['0.0.1', 'abcdefgh', 1234567890], [$vi->version, $vi->commit_sha, $vi->commit_timestamp]);
        $this->assertSame($vi, Foundation::version_info());
    }

    #[DataProvider('strip_cases')]
    public function test_strip(string $input, string $expected): void
    {
        $this->assertSame($expected, StringUtils::strip($input));
    }

    #[DataProvider('case_cases')]
    public function test_lower_upper(string $input, string $lower, string $upper): void
    {
        $this->assertSame($lower, StringUtils::lower($input));
        $this->assertSame($upper, StringUtils::upper($input));
    }

    public function test_bytes_decode(): void
    {
        $this->assertSame('é日', Foundation::bytes_decode('utf-8')("\xc3\xa9\xe6\x97\xa5"));
        $this->assertSame('é', Foundation::bytes_decode('latin-1')("\xe9"));
        $this->assertSame('abc', Foundation::bytes_decode('ascii')('abc'));
        foreach (['utf-8' => "\xff\xfe", 'ascii' => "\xc3\xa9"] as $enc => $bytes) {
            try {
                Foundation::bytes_decode($enc)($bytes);
                $this->fail("ValueError expected for $enc");
            } catch (\ValueError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_wrap_param_in_exception(): void
    {
        $ok = Foundation::wrap_param_in_exception('cap', fn ($x) => $x * 2);
        $this->assertSame(4, $ok(2));
        $boom = Foundation::wrap_param_in_exception('Parsing filenameEnc', function ($x) {
            throw new \OutOfBoundsException('inner');
        });
        try {
            $boom(['type' => "it's"]);
            $this->fail('ValueError expected');
        } catch (\ValueError $e) {
            $this->assertSame("Invalid Input (Parsing filenameEnc): {'type': \"it's\"}", $e->getMessage());
            $this->assertInstanceOf(\OutOfBoundsException::class, $e->getPrevious(), 'raise ... from err');
        }
        // an Error (Python's TypeError…) is wrapped too
        $this->expectException(\ValueError::class);
        Foundation::wrap_param_in_exception('c', fn ($x) => $x->missing())('s');
    }

    public function test_pairs_helpers(): void
    {
        $pairs = [['a', null], ['b', 1], ['c', null], ['d', 0]];
        $this->assertSame([['a', null], ['c', null]], self::list_(Foundation::empty_pairs($pairs)));
        $this->assertSame([['b', 1], ['d', 0]], self::list_(Foundation::non_empty_pairs($pairs)));
        $this->assertSame(['a', 'b', 'c', 'd'], self::list_(Foundation::keys_from_pairs($pairs)));
        $this->assertSame(['a', 'c'], self::list_(Foundation::keys_for_empty_values($pairs)));
        $this->assertTrue(Foundation::is_none(null));
        $this->assertTrue(Foundation::is_not_none(''));
    }

    public function test_flat_dict(): void
    {
        $this->assertSame(['a' => 3, 'b' => 2, 'c' => 4], Foundation::flat_dict([['a' => 1, 'b' => 2], ['a' => 3], ['c' => 4]]));
        $this->assertSame([], Foundation::flat_dict([]));
    }

    public function test_split_with_alternatives_on_command_line(): void
    {
        // icloudpd/cli.py: foundation.split_with_alternatives(["-u", "--username"], non_global_args)
        $this->assertSame(
            [['--only-print-filenames'], ['-u', 'a', '--recent', '5'], ['--username', 'b']],
            Foundation::split_with_alternatives(['-u', '--username'], ['--only-print-filenames', '-u', 'a', '--recent', '5', '--username', 'b']),
        );
        $this->assertSame([[]], Foundation::split_with_alternatives(['x'], []));
    }

    public function test_core_combinators(): void
    {
        $inc = fn ($x) => $x + 1;
        $dbl = fn ($x) => $x * 2;
        $this->assertSame(7, Core::compose($inc, $dbl)(3));
        $this->assertSame(8, Core::pipe($inc, $dbl)(3));
        $this->assertSame('x', Core::identity('x'));
        $this->assertSame('k', Core::constant('k')('whatever'));
        $this->assertSame(24, Core::curry3(fn ($a, $b, $c) => $a * $b * $c)(2)(3)(4));
        $this->assertSame(6, Core::compact2(fn ($a, $b) => $a * $b)([2, 3]));
        $this->assertSame(6, Core::expand2(fn ($t) => $t[0] * $t[1])(2, 3));
        $this->assertSame([4, 8], Core::arrow($inc, $dbl, [3, 4]));
        $this->assertSame(15, Core::partial_1_1(fn ($a, $b) => $a * $b, 3)(5));
        $this->assertSame(30, Core::partial_2_1(fn ($a, $b, $c) => $a * $b * $c, 2, 3)(5));
        $this->assertSame([2, 4], self::list_(Core::filter_(fn ($x) => $x % 2 == 0, [1, 2, 3, 4])));
        $this->assertSame([2, 4], self::list_(Core::filter_guarded(fn ($x) => $x % 2 == 0, [1, 2, 3, 4])));
        $this->assertSame([2, 3], self::list_(Core::map_($inc, [1, 2])));
        $this->assertSame([1, 2, 3, 4], self::list_(Core::chain_from_iterable([[1, 2], (fn () => yield from [3, 4])()])));
        $this->assertSame([2, 1], [Core::fst([2, 1]), Core::snd([2, 1])]);
        $this->assertSame([], self::list_(Core::take(0, [1, 2])));
        $this->assertSame([], self::list_(Core::skip(5, [1, 2])));
        // fst / snd on the tuples of Json
        $this->assertSame('a', Core::fst(\SplFixedArray::fromArray(['a', 'b'])));
    }

    public function test_map_and_filter_are_lazy_and_single_use(): void
    {
        $calls = 0;
        $m = Core::map_(function ($x) use (&$calls) {
            $calls++;
            return $x;
        }, [1, 2, 3]);
        $this->assertSame(0, $calls);
        $this->assertSame(1, $m->current());
        $this->assertSame(1, $calls);
        $this->assertSame([1, 2, 3], self::list_($m));
    }

    public function test_replace_extension_matches_splitext(): void
    {
        $f = StringUtils::replace_extension('.MOV');
        foreach (['a.HEIC' => 'a.MOV', '/x/y.z/f.heic' => '/x/y.z/f.MOV', '/x/y.z/f' => '/x/y.z/f', 'a.tar.gz' => 'a.tar.MOV', '.bashrc' => '.bashrc', 'é.JPG' => 'é.MOV', 'a.' => 'a.MOV'] as $in => $out) {
            $this->assertSame($out, $f($in), $in);
        }
    }

    public function test_curried_string_helpers(): void
    {
        $this->assertTrue(StringUtils::endswith(['.a', '.b'])('x.b'));
        $this->assertFalse(StringUtils::endswith([])('x'));
        $this->assertTrue(StringUtils::endswith('')('x'));
        $this->assertSame(['a', 'b'], StringUtils::split(',')('a,b'));
        $this->assertSame('a-b', StringUtils::join('-')(['a', 'b']));
        $this->assertTrue(StringUtils::is_empty(''));
        $this->assertSame('a', StringUtils::strip_and_lower(" \tA\n"));
        $this->assertSame('x', Core::compose(StringUtils::lower(...), StringUtils::strip(...))(' X '));
    }

    public static function obfuscate_cases(): array
    {
        return [
            ["", "OBFUSCATED-0"],
            ["abc", "OBFUSCATED-891568578"],
            ["\u{e9}\u{65e5}", "OBFUSCATED-1831326769"],
            ["xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", "OBFUSCATED-1577999759"],
            ["X-Apple-Session-Token", "OBFUSCATED-1783089661"],
            ["password with spaces", "OBFUSCATED-1981049500"],
            ["\u{1f600}", "OBFUSCATED-88978756"],
        ];
    }

    public static function extract_context_cases(): array
    {
        return [
            ["", "a", "a"],
            ["x", "a", "x.a"],
            ["x.", "a", "x.a"],
            ["x.y", "z", "x.y.z"],
            ["", "", ""],
            ["a", "", "a."],
            [".", "b", ".b"],
            ["a.", "", "a."],
        ];
    }

    public static function apply_rules_cases(): array
    {
        return [
            ["headers/obf_apple", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["X-APPLE-.*", "scnt"], "obf", "{\"X-Apple-ID-Session-Id\": \"OBFUSCATED-891568578\", \"Content-Type\": \"application/json\", \"scnt\": \"OBFUSCATED-3274128842\", \"x-apple-i-rscd\": \"OBFUSCATED-556920499\"}"],
            ["headers/obf_dsinfo", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["dsInfo\\.(fullName|appleId)"], "obf", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"application/json\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["headers/obf_leaf", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["filenameEnc.*value"], "obf", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"application/json\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["headers/drop_tokens", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["^tokens\\."], "none", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"application/json\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["headers/drop_records_fields", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["fields"], "none", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"application/json\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["headers/const_all", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["^records\\.recordName\$"], "const", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"application/json\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["headers/whole_list", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["^cookies\$"], "obf", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"application/json\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["headers/case", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["content-type"], "const", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"REDACTED\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["headers/tilde", ["X-Apple-ID-Session-Id" => "abc", "Content-Type" => "application/json", "scnt" => "zzz", "x-apple-i-rscd" => "200"], ["a~b|^zzz"], "const", "{\"X-Apple-ID-Session-Id\": \"abc\", \"Content-Type\": \"application/json\", \"scnt\": \"zzz\", \"x-apple-i-rscd\": \"200\"}"],
            ["body/obf_apple", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["X-APPLE-.*", "scnt"], "obf", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["body/obf_dsinfo", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["dsInfo\\.(fullName|appleId)"], "obf", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"OBFUSCATED-1782059462\", \"appleId\": \"OBFUSCATED-1391087471\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["body/obf_leaf", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["filenameEnc.*value"], "obf", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["body/drop_tokens", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["^tokens\\."], "none", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": []}"],
            ["body/drop_records_fields", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["fields"], "none", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["body/const_all", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["^records\\.recordName\$"], "const", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["body/whole_list", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["^cookies\$"], "obf", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["body/case", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["content-type"], "const", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["body/tilde", ["dsInfo" => ["dsid" => "123", "fullName" => "John Doe", "appleId" => "jdoe@gmail.com"], "webservices" => ["ckdatabasews" => ["url" => "https://p1-ckdatabasews.icloud.com:443", "status" => "active"]], "hsaTrustedBrowser" => true, "tokens" => ["a", "b"]], ["a~b|^zzz"], "const", "{\"dsInfo\": {\"dsid\": \"123\", \"fullName\": \"John Doe\", \"appleId\": \"jdoe@gmail.com\"}, \"webservices\": {\"ckdatabasews\": {\"url\": \"https://p1-ckdatabasews.icloud.com:443\", \"status\": \"active\"}}, \"hsaTrustedBrowser\": true, \"tokens\": [\"a\", \"b\"]}"],
            ["list_of_dicts/obf_apple", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["X-APPLE-.*", "scnt"], "obf", "{\"records\": [{\"recordName\": \"A1\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1H\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"A2\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1I\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/obf_dsinfo", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["dsInfo\\.(fullName|appleId)"], "obf", "{\"records\": [{\"recordName\": \"A1\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1H\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"A2\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1I\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/obf_leaf", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["filenameEnc.*value"], "obf", "{\"records\": [{\"recordName\": \"A1\", \"fields\": {\"filenameEnc\": {\"value\": \"OBFUSCATED-751918363\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"A2\", \"fields\": {\"filenameEnc\": {\"value\": \"OBFUSCATED-1540779405\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/drop_tokens", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["^tokens\\."], "none", "{\"records\": [{\"recordName\": \"A1\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1H\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"A2\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1I\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/drop_records_fields", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["fields"], "none", "{\"records\": [{\"recordName\": \"A1\"}, {\"recordName\": \"A2\"}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/const_all", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["^records\\.recordName\$"], "const", "{\"records\": [{\"recordName\": \"REDACTED\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1H\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"REDACTED\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1I\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/whole_list", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["^cookies\$"], "obf", "{\"records\": [{\"recordName\": \"A1\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1H\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"A2\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1I\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/case", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["content-type"], "const", "{\"records\": [{\"recordName\": \"A1\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1H\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"A2\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1I\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["list_of_dicts/tilde", ["records" => [["recordName" => "A1", "fields" => ["filenameEnc" => ["value" => "SU1H", "type" => "ENCRYPTED_BYTES"]]], ["recordName" => "A2", "fields" => ["filenameEnc" => ["value" => "SU1I", "type" => "ENCRYPTED_BYTES"]]]], "syncToken" => "tok"], ["a~b|^zzz"], "const", "{\"records\": [{\"recordName\": \"A1\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1H\", \"type\": \"ENCRYPTED_BYTES\"}}}, {\"recordName\": \"A2\", \"fields\": {\"filenameEnc\": {\"value\": \"SU1I\", \"type\": \"ENCRYPTED_BYTES\"}}}], \"syncToken\": \"tok\"}"],
            ["cookies/obf_apple", [["a", "b"], ["c", "d"]], ["X-APPLE-.*", "scnt"], "obf", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/obf_dsinfo", [["a", "b"], ["c", "d"]], ["dsInfo\\.(fullName|appleId)"], "obf", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/obf_leaf", [["a", "b"], ["c", "d"]], ["filenameEnc.*value"], "obf", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/drop_tokens", [["a", "b"], ["c", "d"]], ["^tokens\\."], "none", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/drop_records_fields", [["a", "b"], ["c", "d"]], ["fields"], "none", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/const_all", [["a", "b"], ["c", "d"]], ["^records\\.recordName\$"], "const", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/whole_list", [["a", "b"], ["c", "d"]], ["^cookies\$"], "obf", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/case", [["a", "b"], ["c", "d"]], ["content-type"], "const", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["cookies/tilde", [["a", "b"], ["c", "d"]], ["a~b|^zzz"], "const", "[[\"a\", \"b\"], [\"c\", \"d\"]]"],
            ["scalar/obf_apple", "hello", ["X-APPLE-.*", "scnt"], "obf", "\"hello\""],
            ["scalar/obf_dsinfo", "hello", ["dsInfo\\.(fullName|appleId)"], "obf", "\"hello\""],
            ["scalar/obf_leaf", "hello", ["filenameEnc.*value"], "obf", "\"hello\""],
            ["scalar/drop_tokens", "hello", ["^tokens\\."], "none", "\"hello\""],
            ["scalar/drop_records_fields", "hello", ["fields"], "none", "\"hello\""],
            ["scalar/const_all", "hello", ["^records\\.recordName\$"], "const", "\"hello\""],
            ["scalar/whole_list", "hello", ["^cookies\$"], "obf", "\"hello\""],
            ["scalar/case", "hello", ["content-type"], "const", "\"hello\""],
            ["scalar/tilde", "hello", ["a~b|^zzz"], "const", "\"hello\""],
            ["numbers/obf_apple", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["X-APPLE-.*", "scnt"], "obf", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/obf_dsinfo", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["dsInfo\\.(fullName|appleId)"], "obf", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/obf_leaf", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["filenameEnc.*value"], "obf", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/drop_tokens", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["^tokens\\."], "none", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/drop_records_fields", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["fields"], "none", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/const_all", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["^records\\.recordName\$"], "const", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/whole_list", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["^cookies\$"], "obf", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/case", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["content-type"], "const", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
            ["numbers/tilde", ["a" => 1, "b" => 2.5, "c" => null, "d" => false], ["a~b|^zzz"], "const", "{\"a\": 1, \"b\": 2.5, \"d\": false}"],
        ];
    }

    public static function version_cases(): array
    {
        return [
            [1514764800, "version:1.2.3, commit sha:abc1234, commit timestamp:Mon Jan 1 00:00:00 2018 UTC"],
            [1234567890, "version:1.2.3, commit sha:abc1234, commit timestamp:Fri Feb 13 23:31:30 2009 UTC"],
            [1700000000, "version:1.2.3, commit sha:abc1234, commit timestamp:Tue Nov 14 22:13:20 2023 UTC"],
            [1530403200, "version:1.2.3, commit sha:abc1234, commit timestamp:Sun Jul 1 00:00:00 2018 UTC"],
        ];
    }

    public static function strip_cases(): array
    {
        return [
            ["  hello  ", "hello"],
            ["\t\n x \r\x0b\x0c", "x"],
            ["\u{a0} x \u{2003}", "x"],
            ["\x1c\x1d\x1e\x1f x \u{85}", "x"],
            ["\u{200b}x\u{200b}", "\u{200b}x\u{200b}"],
            ["\u{3000}\u{65e5}\u{672c}\u{3000}", "\u{65e5}\u{672c}"],
            ["", ""],
            ["   ", ""],
            ["a b", "a b"],
            ["\u{e9}\u{2028}", "\u{e9}"],
            ["\u{feff}x", "\u{feff}x"],
        ];
    }

    public static function case_cases(): array
    {
        return [
            ["HELLO", "hello", "HELLO"],
            ["Stra\u{df}e", "stra\u{df}e", "STRASSE"],
            ["\u{130}stanbul", "i\u{307}stanbul", "\u{130}STANBUL"],
            ["\u{391}\u{3a3} \u{3a3}\u{391} \u{3a3}", "\u{3b1}\u{3c2} \u{3c3}\u{3b1} \u{3c3}", "\u{391}\u{3a3} \u{3a3}\u{391} \u{3a3}"],
            ["\u{1c5}", "\u{1c6}", "\u{1c4}"],
            ["\u{fb01}", "\u{fb01}", "FI"],
            ["\u{c9}COLE", "\u{e9}cole", "\u{c9}COLE"],
            ["IMG_0001.HEIC", "img_0001.heic", "IMG_0001.HEIC"],
            ["\u{1c6}", "\u{1c6}", "\u{1c4}"],
            ["\u{1e9e}", "\u{df}", "\u{1e9e}"],
            ["\u{149}", "\u{149}", "\u{2bc}N"],
            ["\u{390}", "\u{390}", "\u{399}\u{308}\u{301}"],
        ];
    }
}
