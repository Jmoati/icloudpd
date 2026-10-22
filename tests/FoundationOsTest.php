<?php

namespace Tests;

use Foundation\Clock;
use Foundation\FileExistsError;
use Foundation\FileNotFoundError;
use Foundation\Os;
use Foundation\OSError;
use Foundation\Patch;
use Foundation\Time;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `Foundation\Os`, `Time` and `Clock`. The `os.path` cases come from Python's `posixpath`
 * (Python 3.13): generated, do not edit the data by hand.
 */
class FoundationOsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/icloudpd-os-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    #[DataProvider('join_cases')]
    public function test_path_join(array $parts, string $expected): void
    {
        $this->assertSame($expected, Os::path_join(...$parts));
    }

    #[DataProvider('normpath_cases')]
    public function test_path_normpath(string $path, string $expected): void
    {
        $this->assertSame($expected, Os::path_normpath($path));
    }

    #[DataProvider('basename_cases')]
    public function test_path_basename(string $path, string $expected): void
    {
        $this->assertSame($expected, Os::path_basename($path));
    }

    #[DataProvider('dirname_cases')]
    public function test_path_dirname(string $path, string $expected): void
    {
        $this->assertSame($expected, Os::path_dirname($path));
    }

    #[DataProvider('splitext_cases')]
    public function test_path_splitext(string $path, array $expected): void
    {
        $this->assertSame($expected, Os::path_splitext($path));
    }

    #[DataProvider('expanduser_cases')]
    public function test_path_expanduser(string $home, string $path, string $expected): void
    {
        $old = getenv('HOME');
        putenv('HOME='.$home);
        try {
            $this->assertSame($expected, Os::path_expanduser($path));
        } finally {
            putenv($old === false ? 'HOME' : 'HOME='.$old);
        }
    }

    public function test_makedirs(): void
    {
        $d = $this->tmp.'/a/b/c';
        Os::makedirs($d);
        $this->assertDirectoryExists($d);
        $this->assertTrue(Os::path_exists($d));
        Os::makedirs($d, exist_ok: true);
        Os::makedirs($d, true);
        try {
            Os::makedirs($d);
            $this->fail('FileExistsError expected');
        } catch (FileExistsError $e) {
            $this->assertInstanceOf(OSError::class, $e);
        }
        file_put_contents($this->tmp.'/f', 'x');
        $this->expectException(FileExistsError::class);
        Os::makedirs($this->tmp.'/f', exist_ok: true);
    }

    public function test_makedirs_is_patchable(): void
    {
        $calls = [];
        Patch::set('Foundation\Os::makedirs', function (string $path, bool $exist_ok, int $mode) use (&$calls) {
            $calls[] = [$path, $exist_ok, $mode];
            throw new OSError('patched');
        });
        $this->expectException(OSError::class);
        try {
            Os::makedirs($this->tmp.'/never', true);
        } finally {
            $this->assertSame([[$this->tmp.'/never', true, 0777]], $calls);
            $this->assertDirectoryDoesNotExist($this->tmp.'/never');
        }
    }

    public function test_remove_and_rename(): void
    {
        file_put_contents($this->tmp.'/a', 'x');
        Os::rename($this->tmp.'/a', $this->tmp.'/b');
        $this->assertFileDoesNotExist($this->tmp.'/a');
        $this->assertFileExists($this->tmp.'/b');
        file_put_contents($this->tmp.'/c', 'y');
        Os::rename($this->tmp.'/c', $this->tmp.'/b'); // replaces
        $this->assertSame('y', file_get_contents($this->tmp.'/b'));
        Os::remove($this->tmp.'/b');
        $this->assertFileDoesNotExist($this->tmp.'/b');
    }

    public function test_remove_missing(): void
    {
        $this->expectException(FileNotFoundError::class);
        Os::remove($this->tmp.'/missing');
    }

    public function test_rename_missing(): void
    {
        $this->expectException(FileNotFoundError::class);
        Os::rename($this->tmp.'/missing', $this->tmp.'/other');
    }

    public function test_utime(): void
    {
        $f = $this->tmp.'/f';
        file_put_contents($f, 'x');
        Os::utime($f, [1531371164, 1531371165]);
        clearstatcache();
        $this->assertSame(1531371165, filemtime($f));
        $this->assertSame(1531371164, fileatime($f));
        $this->assertSame(1531371165, Os::stat($f)['st_mtime']);
        Os::utime($f, [1531371164.630, 1531371164.630]); // floats, as icloudpd passes
        $this->assertSame(1531371164, Os::stat($f)['st_mtime']);
        Os::utime($f);
        $this->assertGreaterThan(1700000000, Os::stat($f)['st_mtime']);
    }

    public function test_utime_missing_file_is_not_created(): void
    {
        try {
            Os::utime($this->tmp.'/missing', [1, 1]);
            $this->fail('FileNotFoundError expected');
        } catch (FileNotFoundError) {
            $this->assertFileDoesNotExist($this->tmp.'/missing');
        }
    }

    public function test_utime_is_patchable(): void
    {
        $calls = [];
        Patch::set('Foundation\Os::utime', function (string $path, ?array $times) use (&$calls) {
            $calls[] = [$path, $times];
        });
        Os::utime('/does/not/exist', [1, 2]);
        $this->assertSame([['/does/not/exist', [1, 2]]], $calls);
    }

    public function test_stat_isfile_getsize(): void
    {
        $f = $this->tmp.'/f';
        file_put_contents($f, 'abcde');
        $this->assertSame(5, Os::stat($f)['st_size']);
        $this->assertSame(5, Os::path_getsize($f));
        $this->assertTrue(Os::path_isfile($f));
        $this->assertFalse(Os::path_isfile($this->tmp));
        $this->assertFalse(Os::path_isfile($this->tmp.'/missing'));
        // no stale answers when the file changes behind PHP's stat cache
        file_put_contents($f, 'abcdefgh');
        $this->assertSame(8, Os::path_getsize($f));
        unlink($f);
        $this->assertFalse(Os::path_exists($f));
        $this->expectException(FileNotFoundError::class);
        Os::stat($f);
    }

    public function test_getcwd_environ_get(): void
    {
        $this->assertSame(getcwd(), Os::getcwd());
        putenv('ICLOUDPD_TEST_VAR=hello');
        $this->assertSame('hello', Os::environ_get('ICLOUDPD_TEST_VAR'));
        putenv('ICLOUDPD_TEST_VAR');
        $this->assertNull(Os::environ_get('ICLOUDPD_TEST_VAR'));
        $this->assertSame('d', Os::environ_get('ICLOUDPD_TEST_VAR', 'd'));
    }

    public function test_time_sleep(): void
    {
        $t = microtime(true);
        Time::sleep(0.05);
        $this->assertGreaterThanOrEqual(0.045, microtime(true) - $t);
        Time::sleep(0);
        Time::sleep(-1);
    }

    public function test_time_sleep_and_time_are_patchable(): void
    {
        $slept = [];
        Patch::set('Foundation\Time::sleep', function (float $s) use (&$slept) {
            $slept[] = $s;
        });
        Patch::set('Foundation\Time::time', fn () => 1514764800.5);
        $t = microtime(true);
        Time::sleep(30);
        $this->assertLessThan(1, microtime(true) - $t);
        $this->assertSame([30.0], $slept);
        $this->assertSame(1514764800.5, Time::time());
    }

    public function test_clock_now(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        $now = Clock::now($tz);
        $this->assertSame('Asia/Tokyo', $now->getTimezone()->getName());
        $this->assertLessThan(2, abs($now->getTimestamp() - time()));
        $this->assertSame(date_default_timezone_get(), Clock::now()->getTimezone()->getName());
        $this->assertSame(date_default_timezone_get(), Clock::get_localzone()->getName());
    }

    public function test_clock_now_is_patchable(): void
    {
        Patch::set('Foundation\Clock::now', fn (?\DateTimeZone $tz) => new \DateTimeImmutable('2018-01-01 00:00:00', $tz ?? new \DateTimeZone('UTC')));
        $this->assertSame('2018-01-01 00:00:00', Clock::now()->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Paris', Clock::now(new \DateTimeZone('Europe/Paris'))->getTimezone()->getName());
    }

    public static function join_cases(): array
    {
        return [
            [["a", "b"], "a/b"],
            [["a/", "b"], "a/b"],
            [["a", "/b"], "/b"],
            [["", "b"], "b"],
            [["a", ""], "a/"],
            [["a", "b", "c"], "a/b/c"],
            [["/a", "b", "/c", "d"], "/c/d"],
            [["a/b", "../c"], "a/b/../c"],
            [["a", "", "b"], "a/b"],
            [["", ""], ""],
            [["/", "a"], "/a"],
            [["a/", "/"], "/"],
            [["a", "b/"], "a/b/"],
            [["~", "x"], "~/x"],
        ];
    }

    public static function normpath_cases(): array
    {
        return [
            ["", "."],
            [".", "."],
            ["..", ".."],
            ["/", "/"],
            ["//", "//"],
            ["///", "/"],
            ["a", "a"],
            ["a/b", "a/b"],
            ["a/b/", "a/b"],
            ["/a/b", "/a/b"],
            ["//a/b", "//a/b"],
            ["///a/b", "/a/b"],
            ["a//b", "a/b"],
            ["a/./b", "a/b"],
            ["a/../b", "b"],
            ["../a", "../a"],
            ["../../a/b", "../../a/b"],
            ["a/b/../..", "."],
            ["a/b/../../..", ".."],
            ["/..", "/"],
            ["/../a", "/a"],
            ["/a/../..", "/"],
            ["./a", "a"],
            ["a/.", "a"],
            ["a/..", "."],
            ["/a/b/c.txt", "/a/b/c.txt"],
            ["IMG_0001.JPG", "IMG_0001.JPG"],
            [".hidden", ".hidden"],
            ["..hidden", "..hidden"],
            [".a.b", ".a.b"],
            ["a.b/c", "a.b/c"],
            ["a.b/c.d", "a.b/c.d"],
            ["a/.b", "a/.b"],
            ["a/..b.c", "a/..b.c"],
            ["x.", "x."],
            ["a..", "a.."],
            ["..", ".."],
            ["...", "..."],
            ["dir.d/", "dir.d"],
            ["/tmp/x.tar.gz", "/tmp/x.tar.gz"],
            ["\u{e9}/\u{65e5}\u{672c}.JPG", "\u{e9}/\u{65e5}\u{672c}.JPG"],
            ["a//", "a"],
            ["~", "~"],
            ["~/a", "~/a"],
            ["~/", "~"],
            ["~x", "~x"],
            ["a~", "a~"],
        ];
    }

    public static function basename_cases(): array
    {
        return [
            ["", ""],
            [".", "."],
            ["..", ".."],
            ["/", ""],
            ["//", ""],
            ["///", ""],
            ["a", "a"],
            ["a/b", "b"],
            ["a/b/", ""],
            ["/a/b", "b"],
            ["//a/b", "b"],
            ["///a/b", "b"],
            ["a//b", "b"],
            ["a/./b", "b"],
            ["a/../b", "b"],
            ["../a", "a"],
            ["../../a/b", "b"],
            ["a/b/../..", ".."],
            ["a/b/../../..", ".."],
            ["/..", ".."],
            ["/../a", "a"],
            ["/a/../..", ".."],
            ["./a", "a"],
            ["a/.", "."],
            ["a/..", ".."],
            ["/a/b/c.txt", "c.txt"],
            ["IMG_0001.JPG", "IMG_0001.JPG"],
            [".hidden", ".hidden"],
            ["..hidden", "..hidden"],
            [".a.b", ".a.b"],
            ["a.b/c", "c"],
            ["a.b/c.d", "c.d"],
            ["a/.b", ".b"],
            ["a/..b.c", "..b.c"],
            ["x.", "x."],
            ["a..", "a.."],
            ["..", ".."],
            ["...", "..."],
            ["dir.d/", ""],
            ["/tmp/x.tar.gz", "x.tar.gz"],
            ["\u{e9}/\u{65e5}\u{672c}.JPG", "\u{65e5}\u{672c}.JPG"],
            ["a//", ""],
            ["~", "~"],
            ["~/a", "a"],
            ["~/", ""],
            ["~x", "~x"],
            ["a~", "a~"],
        ];
    }

    public static function dirname_cases(): array
    {
        return [
            ["", ""],
            [".", ""],
            ["..", ""],
            ["/", "/"],
            ["//", "//"],
            ["///", "///"],
            ["a", ""],
            ["a/b", "a"],
            ["a/b/", "a/b"],
            ["/a/b", "/a"],
            ["//a/b", "//a"],
            ["///a/b", "///a"],
            ["a//b", "a"],
            ["a/./b", "a/."],
            ["a/../b", "a/.."],
            ["../a", ".."],
            ["../../a/b", "../../a"],
            ["a/b/../..", "a/b/.."],
            ["a/b/../../..", "a/b/../.."],
            ["/..", "/"],
            ["/../a", "/.."],
            ["/a/../..", "/a/.."],
            ["./a", "."],
            ["a/.", "a"],
            ["a/..", "a"],
            ["/a/b/c.txt", "/a/b"],
            ["IMG_0001.JPG", ""],
            [".hidden", ""],
            ["..hidden", ""],
            [".a.b", ""],
            ["a.b/c", "a.b"],
            ["a.b/c.d", "a.b"],
            ["a/.b", "a"],
            ["a/..b.c", "a"],
            ["x.", ""],
            ["a..", ""],
            ["..", ""],
            ["...", ""],
            ["dir.d/", "dir.d"],
            ["/tmp/x.tar.gz", "/tmp"],
            ["\u{e9}/\u{65e5}\u{672c}.JPG", "\u{e9}"],
            ["a//", "a"],
            ["~", ""],
            ["~/a", "~"],
            ["~/", "~"],
            ["~x", ""],
            ["a~", ""],
        ];
    }

    public static function splitext_cases(): array
    {
        return [
            ["", ["", ""]],
            [".", [".", ""]],
            ["..", ["..", ""]],
            ["/", ["/", ""]],
            ["//", ["//", ""]],
            ["///", ["///", ""]],
            ["a", ["a", ""]],
            ["a/b", ["a/b", ""]],
            ["a/b/", ["a/b/", ""]],
            ["/a/b", ["/a/b", ""]],
            ["//a/b", ["//a/b", ""]],
            ["///a/b", ["///a/b", ""]],
            ["a//b", ["a//b", ""]],
            ["a/./b", ["a/./b", ""]],
            ["a/../b", ["a/../b", ""]],
            ["../a", ["../a", ""]],
            ["../../a/b", ["../../a/b", ""]],
            ["a/b/../..", ["a/b/../..", ""]],
            ["a/b/../../..", ["a/b/../../..", ""]],
            ["/..", ["/..", ""]],
            ["/../a", ["/../a", ""]],
            ["/a/../..", ["/a/../..", ""]],
            ["./a", ["./a", ""]],
            ["a/.", ["a/.", ""]],
            ["a/..", ["a/..", ""]],
            ["/a/b/c.txt", ["/a/b/c", ".txt"]],
            ["IMG_0001.JPG", ["IMG_0001", ".JPG"]],
            [".hidden", [".hidden", ""]],
            ["..hidden", ["..hidden", ""]],
            [".a.b", [".a", ".b"]],
            ["a.b/c", ["a.b/c", ""]],
            ["a.b/c.d", ["a.b/c", ".d"]],
            ["a/.b", ["a/.b", ""]],
            ["a/..b.c", ["a/..b", ".c"]],
            ["x.", ["x", "."]],
            ["a..", ["a.", "."]],
            ["..", ["..", ""]],
            ["...", ["...", ""]],
            ["dir.d/", ["dir.d/", ""]],
            ["/tmp/x.tar.gz", ["/tmp/x.tar", ".gz"]],
            ["\u{e9}/\u{65e5}\u{672c}.JPG", ["\u{e9}/\u{65e5}\u{672c}", ".JPG"]],
            ["a//", ["a//", ""]],
            ["~", ["~", ""]],
            ["~/a", ["~/a", ""]],
            ["~/", ["~/", ""]],
            ["~x", ["~x", ""]],
            ["a~", ["a~", ""]],
        ];
    }

    public static function expanduser_cases(): array
    {
        return [
            ["/home/jmoati", "~", "/home/jmoati"],
            ["/home/jmoati", "~/a", "/home/jmoati/a"],
            ["/home/jmoati", "~/", "/home/jmoati/"],
            ["/home/jmoati", "~/a/b", "/home/jmoati/a/b"],
            ["/home/jmoati", "a~", "a~"],
            ["/home/jmoati", "/a", "/a"],
            ["/home/jmoati", "", ""],
            ["/home/jmoati", "~nosuchuserzz", "~nosuchuserzz"],
            ["/home/jmoati", "~nosuchuserzz/x", "~nosuchuserzz/x"],
            ["/home/jmoati/", "~", "/home/jmoati"],
            ["/home/jmoati/", "~/a", "/home/jmoati/a"],
            ["/home/jmoati/", "~/", "/home/jmoati/"],
            ["/home/jmoati/", "~/a/b", "/home/jmoati/a/b"],
            ["/home/jmoati/", "a~", "a~"],
            ["/home/jmoati/", "/a", "/a"],
            ["/home/jmoati/", "", ""],
            ["/home/jmoati/", "~nosuchuserzz", "~nosuchuserzz"],
            ["/home/jmoati/", "~nosuchuserzz/x", "~nosuchuserzz/x"],
            ["/", "~", "/"],
            ["/", "~/a", "/a"],
            ["/", "~/", "/"],
            ["/", "~/a/b", "/a/b"],
            ["/", "a~", "a~"],
            ["/", "/a", "/a"],
            ["/", "", ""],
            ["/", "~nosuchuserzz", "~nosuchuserzz"],
            ["/", "~nosuchuserzz/x", "~nosuchuserzz/x"],
            ["//", "~", "/"],
            ["//", "~/a", "/a"],
            ["//", "~/", "/"],
            ["//", "~/a/b", "/a/b"],
            ["//", "a~", "a~"],
            ["//", "/a", "/a"],
            ["//", "", ""],
            ["//", "~nosuchuserzz", "~nosuchuserzz"],
            ["//", "~nosuchuserzz/x", "~nosuchuserzz/x"],
            ["/root", "~", "/root"],
            ["/root", "~/a", "/root/a"],
            ["/root", "~/", "/root/"],
            ["/root", "~/a/b", "/root/a/b"],
            ["/root", "a~", "a~"],
            ["/root", "/a", "/a"],
            ["/root", "", ""],
            ["/root", "~nosuchuserzz", "~nosuchuserzz"],
            ["/root", "~nosuchuserzz/x", "~nosuchuserzz/x"],
        ];
    }
}
