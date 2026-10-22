<?php

namespace Foundation\Http;

use Foundation\FileNotFoundError;
use Foundation\OSError;

/**
 * The LWPCookieJar saves a sequence of "Set-Cookie3" lines (http.cookiejar.LWPCookieJar, with FileCookieJar folded in).
 *
 * "Set-Cookie3" is the format used by the libwww-perl library, not known
 * to be compatible with any browser, but which is easy to read and
 * doesn't lose information about RFC 2965 cookies.
 *
 * The files are the ones of the Python tool (``#LWP-Cookies-2.0``).
 */
class LWPCookieJar extends CookieJar
{
    public ?string $filename;
    public bool $delayload;

    /**
     * Cookies are NOT loaded from the named file until either the .load() or
     * .revert() method is called.
     */
    public function __construct(?string $filename = null, bool $delayload = false, ?DefaultCookiePolicy $policy = null)
    {
        parent::__construct($policy);
        $this->filename = $filename;
        $this->delayload = $delayload;
    }

    /**
     * Return cookies as a string of "\n"-separated "Set-Cookie3" headers.
     *
     * ignore_discard and ignore_expires: see docstring for save()
     */
    public function as_lwp_str(bool $ignore_discard = true, bool $ignore_expires = true): string
    {
        $now = time();
        $r = [];
        foreach ($this as $cookie) {
            if (!$ignore_discard && $cookie->discard) {
                continue;
            }
            if (!$ignore_expires && $cookie->is_expired($now)) {
                continue;
            }
            $r[] = 'Set-Cookie3: '.HttpCookiejar::lwp_cookie_str($cookie);
        }
        $r[] = '';

        return implode("\n", $r);
    }

    /** Save cookies to a file. */
    public function save(?string $filename = null, bool $ignore_discard = false, bool $ignore_expires = false): void
    {
        if ($filename === null) {
            if ($this->filename !== null) {
                $filename = $this->filename;
            } else {
                throw new \ValueError(HttpCookiejar::MISSING_FILENAME_TEXT);
            }
        }

        // like os.open(filename, O_CREAT | O_WRONLY | O_TRUNC, 0o600): the mode only counts for a new file
        $umask = umask(0177);
        try {
            $f = fopen($filename, 'w');
        } finally {
            umask($umask);
        }
        if ($f === false) {
            throw new \RuntimeException("Cannot open $filename for writing");
        }
        try {
            // There really isn't an LWP Cookies 2.0 format, but this indicates
            // that there is extra information in here (domain_dot and
            // port_spec) while still being compatible with libwww-perl, I hope.
            fwrite($f, "#LWP-Cookies-2.0\n");
            fwrite($f, $this->as_lwp_str($ignore_discard, $ignore_expires));
        } finally {
            fclose($f);
        }
    }

    /** Load cookies from a file. */
    public function load(?string $filename = null, bool $ignore_discard = false, bool $ignore_expires = false): void
    {
        if ($filename === null) {
            if ($this->filename !== null) {
                $filename = $this->filename;
            } else {
                throw new \ValueError(HttpCookiejar::MISSING_FILENAME_TEXT);
            }
        }

        $f = @fopen($filename, 'r');
        if ($f === false) {
            throw new FileNotFoundError("[Errno 2] No such file or directory: '$filename'");
        }
        try {
            $this->_really_load($f, $filename, $ignore_discard, $ignore_expires);
        } finally {
            fclose($f);
        }
    }

    /**
     * Clear all cookies and reload cookies from a saved file.
     *
     * Throws LoadError if reversion is not successful; the
     * object's state will not be altered if this happens.
     */
    public function revert(?string $filename = null, bool $ignore_discard = false, bool $ignore_expires = false): void
    {
        if ($filename === null) {
            if ($this->filename !== null) {
                $filename = $this->filename;
            } else {
                throw new \ValueError(HttpCookiejar::MISSING_FILENAME_TEXT);
            }
        }

        $old_state = $this->_cookies;
        $this->_cookies = [];
        try {
            $this->load($filename, $ignore_discard, $ignore_expires);
        } catch (OSError $e) {
            $this->_cookies = $old_state;
            throw $e;
        }
    }

    /** @param resource $f */
    private function _really_load($f, string $filename, bool $ignore_discard, bool $ignore_expires): void
    {
        $magic = fgets($f);
        if ($magic === false || !preg_match('~^\#LWP-Cookies-(\d+\.\d+)~', $magic)) {
            $msg = sprintf("'%s' does not look like a Set-Cookie3 (LWP) format file", $filename);
            throw new LoadError($msg);
        }

        $now = time();

        $header = 'Set-Cookie3:';
        $boolean_attrs = ['port_spec', 'path_spec', 'domain_dot', 'secure', 'discard'];
        $value_attrs = ['version',
            'port', 'path', 'domain',
            'expires',
            'comment', 'commenturl'];

        $line = '';
        try {
            while (($line = fgets($f)) !== false) {
                if (!str_starts_with($line, $header)) {
                    continue;
                }
                $line = trim(substr($line, strlen($header)));

                foreach (HttpCookiejar::split_header_words([$line]) as $data) {
                    [$name, $value] = $data[0];
                    $standard = [];
                    $rest = [];
                    foreach ($boolean_attrs as $k) {
                        $standard[$k] = false;
                    }
                    foreach (array_slice($data, 1) as [$k, $v]) {
                        $lc = $k !== null ? strtolower($k) : null;
                        // don't lose case distinction for unknown fields
                        if (in_array($lc, $value_attrs, true) || in_array($lc, $boolean_attrs, true)) {
                            $k = $lc;
                        }
                        if (in_array($k, $boolean_attrs, true)) {
                            if ($v === null) {
                                $v = true;
                            }
                            $standard[$k] = $v;
                        } elseif (in_array($k, $value_attrs, true)) {
                            $standard[$k] = $v;
                        } else {
                            $rest[$k] = $v;
                        }
                    }

                    $expires = $standard['expires'] ?? null;
                    $discard = $standard['discard'];
                    if ($expires !== null) {
                        $expires = HttpCookiejar::iso2time($expires);
                    }
                    if ($expires === null) {
                        $discard = true;
                    }
                    $domain = $standard['domain'] ?? null;
                    if ($domain === null) {
                        throw new \TypeError('domain is missing');
                    }
                    $domain_specified = str_starts_with($domain, '.');
                    $c = new Cookie(
                        $standard['version'] ?? null, $name, $value,
                        $standard['port'] ?? null, (bool) $standard['port_spec'],
                        $domain, $domain_specified, (bool) $standard['domain_dot'],
                        $standard['path'], (bool) $standard['path_spec'],
                        (bool) $standard['secure'],
                        $expires,
                        (bool) $discard,
                        $standard['comment'] ?? null,
                        $standard['commenturl'] ?? null,
                        $rest,
                    );
                    if (!$ignore_discard && $c->discard) {
                        continue;
                    }
                    if (!$ignore_expires && $c->is_expired($now)) {
                        continue;
                    }
                    $this->set_cookie($c);
                }
            }
        } catch (\Throwable $e) {
            throw new LoadError(sprintf('invalid Set-Cookie3 format file %s: %s', var_export($filename, true), var_export($line, true)), 0, $e);
        }
    }
}
