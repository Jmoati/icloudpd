<?php

namespace Tests;

use Foundation\Http\CookieJar;
use Foundation\Http\Cookies;
use Foundation\Http\LWPCookieJar;
use Foundation\Http\MockRequest;
use Foundation\Http\Raw;
use Foundation\Http\Request;

/**
 * The cookie jar against Python's `http.cookiejar.LWPCookieJar` / `requests.cookies`: the cookies the
 * Set-Cookie headers of a response give, the Cookie header sent back, and the `#LWP-Cookies-2.0` files read
 * and written both ways. The expected values come from Python (upstream venv) for the same inputs.
 */
class LWPCookieJarTest extends TestCase
{
    private const SET_COOKIE = [
        'X-APPLE-UNIQUE-CLIENT-ID="Cw==";Path=/;Domain=.icloud.com;Secure',
        'X-APPLE-WEBAUTH-LOGIN="v=1:t=Cw==BST_IAAAAAAABLw1234~~";Path=/;Domain=.icloud.com;Secure;HttpOnly',
        'X-APPLE-WEBAUTH-USER="v=1:s=1:d=12345678901";Expires=Fri, 12-Jan-2124 05:06:31 GMT;Path=/;Domain=.icloud.com;Secure;HttpOnly',
        'X-APPLE-WEBAUTH-PCS-Documents="pcsdocs-1234567890=";Expires=Tue,16-Jan-2124 02:07:19 GMT;Path=/;Domain=.icloud.com;Secure;HttpOnly',
        'dslang=US-EN; Domain=apple.com; Path=/; Secure; HttpOnly',
        'site=USA; Max-Age=3600; Path=/x; SameSite=Lax',
        'plain=value',
        'withspace=a b; path=/a/b',
        'old=gone; Expires=Thu, 01 Jan 1970 00:00:01 GMT',
        'bad=1; Domain=evil.com',
        'emptyval=',
        'noval',
    ];

    private const EXPECTED_NAMES = ['X-APPLE-UNIQUE-CLIENT-ID', 'X-APPLE-WEBAUTH-LOGIN', 'X-APPLE-WEBAUTH-USER', 'X-APPLE-WEBAUTH-PCS-Documents', 'site', 'plain', 'withspace', 'emptyval', 'noval'];

    /** `jar.as_lwp_str()` of Python after `extract_cookies` (the Max-Age cookie has a relative `expires`) */
    private const EXPECTED_LWP = <<<'LWP'
Set-Cookie3: X-APPLE-UNIQUE-CLIENT-ID="\"Cw==\""; path="/"; domain=.icloud.com; path_spec; domain_dot; secure; discard; version=0
Set-Cookie3: X-APPLE-WEBAUTH-LOGIN="\"v=1:t=Cw==BST_IAAAAAAABLw1234~~\""; path="/"; domain=.icloud.com; path_spec; domain_dot; secure; discard; HttpOnly=None; version=0
Set-Cookie3: X-APPLE-WEBAUTH-USER="\"v=1:s=1:d=12345678901\""; path="/"; domain=.icloud.com; path_spec; domain_dot; secure; expires="2124-01-12 05:06:31Z"; HttpOnly=None; version=0
Set-Cookie3: X-APPLE-WEBAUTH-PCS-Documents="\"pcsdocs-1234567890=\""; path="/"; domain=.icloud.com; path_spec; domain_dot; secure; expires="2124-01-16 02:07:19Z"; HttpOnly=None; version=0
Set-Cookie3: site=USA; path="/x"; domain=www.icloud.com; path_spec; expires="@SITE_EXPIRES@"; SameSite=Lax; version=0
Set-Cookie3: plain=value; path="/a/b"; domain=www.icloud.com; discard; version=0
Set-Cookie3: withspace="a b"; path="/a/b"; domain=www.icloud.com; path_spec; discard; version=0
Set-Cookie3: emptyval=""; path="/a/b"; domain=www.icloud.com; discard; version=0
Set-Cookie3: noval; path="/a/b"; domain=www.icloud.com; discard; version=0
LWP;

    private const EXPECTED_COOKIE_HEADERS = [
            'https://www.icloud.com/a/b/c' => 'plain=value; withspace=a b; emptyval=; noval; X-APPLE-UNIQUE-CLIENT-ID="Cw=="; X-APPLE-WEBAUTH-LOGIN="v=1:t=Cw==BST_IAAAAAAABLw1234~~"; X-APPLE-WEBAUTH-USER="v=1:s=1:d=12345678901"; X-APPLE-WEBAUTH-PCS-Documents="pcsdocs-1234567890="',
            'https://www.icloud.com/x/y' => 'site=USA; X-APPLE-UNIQUE-CLIENT-ID="Cw=="; X-APPLE-WEBAUTH-LOGIN="v=1:t=Cw==BST_IAAAAAAABLw1234~~"; X-APPLE-WEBAUTH-USER="v=1:s=1:d=12345678901"; X-APPLE-WEBAUTH-PCS-Documents="pcsdocs-1234567890="',
            'http://www.icloud.com/' => null,
            'https://setup.icloud.com/' => 'X-APPLE-UNIQUE-CLIENT-ID="Cw=="; X-APPLE-WEBAUTH-LOGIN="v=1:t=Cw==BST_IAAAAAAABLw1234~~"; X-APPLE-WEBAUTH-USER="v=1:s=1:d=12345678901"; X-APPLE-WEBAUTH-PCS-Documents="pcsdocs-1234567890="',
            'https://apple.com/' => null,
    ];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/lwpjar_'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function prep(string $url): Request
    {
        return (new Request())->prepare(method: 'GET', url: $url);
    }

    private function jar_from_headers(): LWPCookieJar
    {
        $jar = new LWPCookieJar();
        $response = new Raw(array_map(fn ($v) => ['Set-Cookie', $v], self::SET_COOKIE));
        $jar->extract_cookies($response, new MockRequest($this->prep('https://www.icloud.com/a/b/c?x=1')));

        return $jar;
    }

    /** the Max-Age cookie expires one hour from now: put its date in the expected text */
    private function expected_lwp(LWPCookieJar $jar): string
    {
        foreach ($jar as $cookie) {
            if ($cookie->name === 'site') {
                $this->assertEqualsWithDelta(time() + 3600, $cookie->expires, 2);

                return str_replace('@SITE_EXPIRES@', gmdate('Y-m-d H:i:s', $cookie->expires).'Z', self::EXPECTED_LWP)."\n";
            }
        }
        $this->fail('no site cookie');
    }

    public function test_extract_cookies_and_lwp_text_match_python(): void
    {
        $jar = $this->jar_from_headers();

        $this->assertSame(self::EXPECTED_NAMES, array_map(fn ($c) => $c->name, iterator_to_array($jar, false)));
        $this->assertSame($this->expected_lwp($jar), $jar->as_lwp_str(ignore_discard: true, ignore_expires: true));
    }

    public function test_cookie_header_matches_python(): void
    {
        $jar = $this->jar_from_headers();
        foreach (self::EXPECTED_COOKIE_HEADERS as $url => $expected) {
            $this->assertSame($expected, Cookies::get_cookie_header($jar, $this->prep($url)), $url);
        }
    }

    public function test_save_does_not_write_discard_cookies_by_default_and_uses_mode_0600(): void
    {
        $jar = $this->jar_from_headers();
        $jar->filename = $this->dir.'/jar';
        $jar->save();
        $this->assertSame(0600, fileperms($jar->filename) & 0777);
        $lines = explode("\n", rtrim(file_get_contents($jar->filename)));
        $this->assertSame('#LWP-Cookies-2.0', $lines[0]);
        // discard cookies are not saved: only the ones with an expiry date
        $this->assertCount(1 + 3, $lines);
    }

    public function test_load_fixture_written_by_the_python_tool(): void
    {
        $jar = new LWPCookieJar(__DIR__.'/cookie/jdoegmailcom');
        $jar->load(ignore_discard: true, ignore_expires: true);
        $cookies = iterator_to_array($jar, false);
        $this->assertSame(
            ['X-APPLE-UNIQUE-CLIENT-ID', 'X-APPLE-WEBAUTH-LOGIN', 'X-APPLE-WEBAUTH-VALIDATE', 'X-APPLE-WEBAUTH-HSA-LOGIN'],
            array_map(fn ($c) => $c->name, $cookies),
        );
        $this->assertSame('"Cw=="', $cookies[0]->value);
        $this->assertSame('.icloud.com', $cookies[0]->domain);
        $this->assertTrue($cookies[0]->secure);
        $this->assertTrue($cookies[0]->discard);
        $this->assertTrue($cookies[0]->domain_initial_dot);
        $this->assertTrue($cookies[0]->path_specified);
        $this->assertSame(0, $cookies[0]->version);
        // `HttpOnly=None`: the Python file holds the text of the value None
        $this->assertSame('None', $cookies[1]->get_nonstandard_attr('HttpOnly'));
        $this->assertStringContainsString(
            'X-APPLE-WEBAUTH-LOGIN="v=1:t=Cw==BST_IAAAAAAABLw1234~~"',
            Cookies::get_cookie_header($jar, $this->prep('https://www.icloud.com/')),
        );
    }

    public function test_load_rejects_a_file_that_is_not_lwp(): void
    {
        file_put_contents($this->dir.'/bad', "# Netscape HTTP Cookie File\n");
        $this->expectException(\Foundation\Http\LoadError::class);
        (new LWPCookieJar($this->dir.'/bad'))->load();
    }

    public function test_load_missing_file_is_a_file_not_found_error(): void
    {
        $this->expectException(\Foundation\FileNotFoundError::class);
        (new LWPCookieJar($this->dir.'/missing'))->load();
    }

    private function python(): ?string
    {
        $python = getenv('HOME').'/icloud_photos_downloader/.venv/bin/python';

        return is_executable($python) ? $python : null;
    }

    /** a file written by this jar is read by Python's LWPCookieJar, with the same cookies */
    public function test_python_reads_the_file_written_here(): void
    {
        $python = $this->python() ?? $this->markTestSkipped('the upstream venv is not there');
        $jar = $this->jar_from_headers();
        $jar->save($this->dir.'/php.jar', ignore_discard: true, ignore_expires: true);

        $script = <<<'PY'
import http.cookiejar as cj, json, sys
jar = cj.LWPCookieJar(sys.argv[1]); jar.load(ignore_discard=True, ignore_expires=True)
print(json.dumps([[c.name, c.value, c.domain, c.path, c.secure, c.expires, c.discard, c.version, sorted((k, str(v)) for k, v in c._rest.items()), c.domain_specified, c.domain_initial_dot, c.path_specified] for c in jar]))
PY;
        $out = shell_exec(escapeshellarg($python).' -I -c '.escapeshellarg($script).' '.escapeshellarg($this->dir.'/php.jar'));
        $python_cookies = json_decode($out, true);
        $mine = array_map(fn ($c) => [$c->name, $c->value, $c->domain, $c->path, $c->secure, $c->expires, $c->discard, $c->version,
            array_map(fn ($k) => [$k, \Foundation\Py::str($c->_rest[$k])], array_keys($c->_rest)), $c->domain_specified, $c->domain_initial_dot, $c->path_specified], iterator_to_array($jar, false));
        $this->assertSame(json_encode($mine), json_encode($python_cookies));
    }

    /** a file written by Python's LWPCookieJar (this version's text format) is read here */
    public function test_this_reads_the_file_written_by_python(): void
    {
        $python = $this->python() ?? $this->markTestSkipped('the upstream venv is not there');
        $script = <<<'PY'
import http.cookiejar as cj, sys
jar = cj.LWPCookieJar(sys.argv[1]); jar.load(sys.argv[2], ignore_discard=True, ignore_expires=True); jar.save(ignore_discard=True, ignore_expires=True)
PY;
        // the fixture, and the jar of the test through Python: re-saved by Python, loaded here, saved here, same text
        $jar = $this->jar_from_headers();
        $jar->save($this->dir.'/a.jar', ignore_discard: true, ignore_expires: true);
        shell_exec(escapeshellarg($python).' -I -c '.escapeshellarg($script).' '.escapeshellarg($this->dir.'/b.jar').' '.escapeshellarg($this->dir.'/a.jar'));
        $this->assertFileExists($this->dir.'/b.jar');
        $this->assertSame(file_get_contents($this->dir.'/a.jar'), file_get_contents($this->dir.'/b.jar'), 'Python re-saves the file unchanged');

        $reloaded = new LWPCookieJar($this->dir.'/b.jar');
        $reloaded->load(ignore_discard: true, ignore_expires: true);
        $this->assertSame($jar->as_lwp_str(), $reloaded->as_lwp_str());
    }
}
