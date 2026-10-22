<?php

namespace Foundation;

use Foundation\Smtp\SMTPException;
use Foundation\Smtp\SMTPResponseException;

/**
 * The part of Python's `smtplib.SMTP` that icloudpd uses (ESMTP client on `stream_socket_client`):
 * `connect`, `ehlo`, `helo`, `starttls`, `login`, `sendmail`, `quit`, with the same commands on the wire.
 * A reply is `[code, message]`, as the tuple of Python.
 *
 * The class is not final so that a test can mock it. `Smtp::SMTP()` is `smtplib.SMTP(host, port)` (the
 * constructor): a patchable factory, as the upstream tests patch `smtplib.SMTP`.
 *
 * ponytail: no `SMTP_SSL`, `source_address`, `send_message`, `verify`/`expn`/`help`, SMTPUTF8, no debug output
 * (`set_debuglevel` is only stored), the timeout is PHP's `default_socket_timeout` (Python waits forever), the
 * local host name is `gethostname()` (or a `[127.0.0.1]` literal) instead of `socket.getfqdn()`, and the address
 * parser behind `quoteaddr` handles `name <addr>`, `addr` and `addr (comment)` but not the rest of RFC 5322.
 * The TLS context is as unverified as smtplib's default (`ssl._create_stdlib_context()`: no certificate or host name
 * check): pass a verifying context to `starttls()` once someone needs it.
 */
class Smtp
{
    public const SMTP_PORT = 25;
    private const CRLF = "\r\n";
    // Python's `_MAXLINE`: limit for reading a response line, `_MAXCHALLENGE`: authentication challenges
    private const _MAXLINE = 8192;
    private const _MAXCHALLENGE = 5;

    public int $debuglevel = 0;
    public ?string $helo_resp = null;
    public ?string $ehlo_resp = null;
    public bool $does_esmtp = false;
    /** @var array<string, string> */
    public array $esmtp_features = [];
    public string $local_hostname;

    /** @var resource|null */
    private $sock = null;
    private string $_host;
    private string $user = '';
    private string $password = '';
    private int $_auth_challenge_count = 0;

    /** `smtplib.SMTP(host, port)` */
    public static function SMTP(string $host = '', int $port = 0): static
    {
        if ($p = Patch::get(__METHOD__)) return $p($host, $port);

        return new static($host, $port);
    }

    /**
     * If specified, `host` is the name of the remote host to which to connect. If specified, `port` specifies
     * the port to which to connect. By default, SMTP_PORT is used. If a host is specified the connect method is
     * called, and if it returns anything other than a success code an exception is raised.
     */
    public function __construct(string $host = '', int $port = 0, ?string $local_hostname = null)
    {
        $this->_host = $host;
        if ($host !== '') {
            [$code, $msg] = $this->connect($host, $port);
            if ($code != 220) {
                $this->close();
                throw new SMTPResponseException($code, $msg, "SMTPConnectError ($code, ".var_export($msg, true).')');
            }
        }
        if ($local_hostname !== null) {
            $this->local_hostname = $local_hostname;
        } else {
            // RFC 2821 says we should use the fqdn in the EHLO/HELO verb, and
            // if that can't be calculated, that we should use a domain literal
            // instead (essentially an encoded IP address like [A.B.C.D]).
            $fqdn = (string) gethostname();
            $this->local_hostname = str_contains($fqdn, '.') ? $fqdn : '[127.0.0.1]';
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Set the debug output level.
     *
     * A non-false value results in debug messages for connection and for all messages sent to and received
     * from the server (not printed by this port).
     */
    public function set_debuglevel(int $debuglevel): void
    {
        $this->debuglevel = $debuglevel;
    }

    /**
     * Connect to a host on a given port.
     *
     * If the hostname ends with a colon (`:') followed by a number, and there is no port specified, that
     * suffix will be stripped off and the number interpreted as the port number to use.
     *
     * Note: This method is automatically invoked by the constructor, if a host is specified during instantiation.
     *
     * @return array{int, string}
     */
    public function connect(string $host = 'localhost', int $port = 0): array
    {
        if (!$port && strpos($host, ':') === strrpos($host, ':')) {
            $i = strrpos($host, ':');
            if ($i !== false) {
                $port = substr($host, $i + 1);
                $host = substr($host, 0, $i);
                if (!ctype_digit($port)) {
                    throw new OSError('nonnumeric port');
                }
                $port = (int) $port;
            }
        }
        if (!$port) {
            $port = self::SMTP_PORT;
        }
        // a second connect() replaces the connection (smtplib leaves the first socket to the garbage collector)
        $this->close();
        $this->_host = $host;
        $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, (float) ini_get('default_socket_timeout'));
        if ($sock === false) {
            throw new OSError("[Errno $errno] $errstr");
        }
        $this->sock = $sock;

        return $this->getreply();
    }

    /** Send `s` to the server. */
    public function send(string $s): void
    {
        if ($this->sock) {
            for ($sent = 0, $n = strlen($s); $sent < $n;) {
                $written = @fwrite($this->sock, substr($s, $sent));
                if ($written === false || $written === 0) {
                    $this->close();
                    throw new SMTPException('Server not connected');
                }
                $sent += $written;
            }
        } else {
            throw new SMTPException('please run connect() first');
        }
    }

    /** Send a command to the server. */
    public function putcmd(string $cmd, string $args = ''): void
    {
        $s = $args === '' ? $cmd : "$cmd $args";
        if (str_contains($s, "\r") || str_contains($s, "\n")) {
            $s = str_replace(["\n", "\r"], ['\\n', '\\r'], $s);
            throw new \ValueError("command and arguments contain prohibited newline characters: $s");
        }
        $this->send($s.self::CRLF);
    }

    /**
     * Get a reply from the server.
     *
     * Returns a tuple consisting of:
     *
     *   - server response code (e.g. '250', or such, if all goes well)
     *     Note: returns -1 if it can't read response code.
     *
     *   - server response string corresponding to response code (multiline
     *     responses are converted to a single, multiline string).
     *
     * Raises SMTPException if end-of-file is reached.
     *
     * @return array{int, string}
     */
    public function getreply(): array
    {
        $resp = [];
        if (!$this->sock) {
            throw new SMTPException('Connection unexpectedly closed');
        }
        while (true) {
            $line = fgets($this->sock, self::_MAXLINE + 2);
            if ($line === false || $line === '') {
                $this->close();
                throw new SMTPException('Connection unexpectedly closed');
            }
            if (strlen($line) > self::_MAXLINE) {
                $this->close();
                throw new SMTPResponseException(500, 'Line too long.');
            }
            $resp[] = trim(substr($line, 4), " \t\r\n");
            $code = substr($line, 0, 3);
            // Check that the error code is syntactically correct.
            // Don't attempt to read a continuation line if it is broken.
            if (!ctype_digit($code)) {
                $errcode = -1;
                break;
            }
            $errcode = (int) $code;
            // Check if multiline response.
            if (substr($line, 3, 1) !== '-') {
                break;
            }
        }

        return [$errcode, implode("\n", $resp)];
    }

    /**
     * Send a command, and return its response code.
     *
     * @return array{int, string}
     */
    public function docmd(string $cmd, string $args = ''): array
    {
        $this->putcmd($cmd, $args);

        return $this->getreply();
    }

    // std smtp commands

    /**
     * SMTP 'helo' command. Hostname to send for this command defaults to the FQDN of the local host.
     *
     * @return array{int, string}
     */
    public function helo(string $name = ''): array
    {
        $this->putcmd('helo', $name ?: $this->local_hostname);
        [$code, $msg] = $this->getreply();
        $this->helo_resp = $msg;

        return [$code, $msg];
    }

    /**
     * SMTP 'ehlo' command. Hostname to send for this command defaults to the FQDN of the local host.
     *
     * @return array{int, string}
     */
    public function ehlo(string $name = ''): array
    {
        $this->esmtp_features = [];
        $this->putcmd('ehlo', $name ?: $this->local_hostname);
        [$code, $msg] = $this->getreply();
        // According to RFC1869 some (badly written)
        // MTA's will disconnect on an ehlo. Toss an exception if
        // that happens -ddm
        if ($code == -1 && strlen($msg) == 0) {
            $this->close();
            throw new SMTPException('Server not connected');
        }
        $this->ehlo_resp = $msg;
        if ($code != 250) {
            return [$code, $msg];
        }
        $this->does_esmtp = true;
        // parse the ehlo response -ddm
        $resp = explode("\n", $this->ehlo_resp);
        array_shift($resp);
        foreach ($resp as $each) {
            // To be able to communicate with as many SMTP servers as possible,
            // we have to take the old-style auth advertisement into account,
            // because:
            // 1) Else our SMTP feature parser gets confused.
            // 2) There are some servers that only advertise the auth methods we
            //    support using the old style.
            if (preg_match('/auth=(.*)/Ai', $each, $auth_match)) {
                // This doesn't remove duplicates, but that's no problem
                $this->esmtp_features['auth'] = ($this->esmtp_features['auth'] ?? '').' '.$auth_match[1];
                continue;
            }

            // RFC 1869 requires a space between ehlo keyword and parameters.
            // It's actually stricter, in that only spaces are allowed between
            // parameters, but were not going to check for that here.  Note
            // that the space isn't present if there are no parameters.
            if (preg_match('/([A-Za-z0-9][A-Za-z0-9\-]*) ?/A', $each, $m)) {
                $feature = strtolower($m[1]);
                $params = trim(substr($each, strlen($m[1])));
                if ($feature == 'auth') {
                    $this->esmtp_features[$feature] = ($this->esmtp_features[$feature] ?? '').' '.$params;
                } else {
                    $this->esmtp_features[$feature] = $params;
                }
            }
        }

        return [$code, $msg];
    }

    /** Does the server support a given SMTP service extension? */
    public function has_extn(string $opt): bool
    {
        return array_key_exists(strtolower($opt), $this->esmtp_features);
    }

    /** SMTP 'rset' command -- resets session. */
    public function rset(): array
    {
        return $this->docmd('rset');
    }

    private function _rset(): void
    {
        // Used internally in the library, since the server disconnected error
        // should appear to the application when the *next* command is issued, if
        // we are doing an internal "safety" reset.
        try {
            $this->rset();
        } catch (SMTPException) {
        }
    }

    /**
     * SMTP 'mail' command -- begins mail xfer session.
     *
     * @param list<string> $options
     * @return array{int, string}
     */
    public function mail(string $sender, array $options = []): array
    {
        $optionlist = '';
        if ($options && $this->does_esmtp) {
            $optionlist = ' '.implode(' ', $options);
        }
        $this->putcmd('mail', 'from:'.self::quoteaddr($sender).$optionlist);

        return $this->getreply();
    }

    /**
     * SMTP 'rcpt' command -- indicates 1 recipient for this mail.
     *
     * @param list<string> $options
     * @return array{int, string}
     */
    public function rcpt(string $recip, array $options = []): array
    {
        $optionlist = '';
        if ($options && $this->does_esmtp) {
            $optionlist = ' '.implode(' ', $options);
        }
        $this->putcmd('rcpt', 'to:'.self::quoteaddr($recip).$optionlist);

        return $this->getreply();
    }

    /**
     * SMTP 'DATA' command -- sends message data to server.
     *
     * Automatically quotes lines beginning with a period per rfc821. Raises SMTPResponseException if there is an
     * unexpected reply to the DATA command; the return value from this method is the final response code received
     * when the all data is sent. Lone '\r' and '\n' characters are converted to '\r\n' characters.
     *
     * @return array{int, string}
     */
    public function data(string $msg): array
    {
        $this->putcmd('data');
        [$code, $repl] = $this->getreply();
        if ($code != 354) {
            throw new SMTPResponseException($code, $repl, "SMTPDataError ($code, ".var_export($repl, true).')');
        }
        $msg = self::_fix_eols($msg);
        if (preg_match('/[^\x00-\x7F]/', $msg)) {
            throw new \ValueError("'ascii' codec can't encode characters in the message");
        }
        $q = self::_quote_periods($msg);
        if (substr($q, -2) !== self::CRLF) {
            $q .= self::CRLF;
        }
        $q .= '.'.self::CRLF;
        $this->send($q);

        return $this->getreply();
    }

    /** `ehlo`, then `helo` if the server does not do ESMTP, when neither has been done in this session */
    public function ehlo_or_helo_if_needed(): void
    {
        // Call self.ehlo() and/or self.helo() if needed.
        //
        // If there has been no previous EHLO or HELO command this session, this
        // method tries ESMTP EHLO first.
        //
        // This method may raise the following exceptions:
        //
        //  SMTPHeloError            The server didn't reply properly to
        //                           the helo greeting.
        if ($this->helo_resp === null && $this->ehlo_resp === null) {
            [$code] = $this->ehlo();
            if (!(200 <= $code && $code <= 299)) {
                [$code, $resp] = $this->helo();
                if (!(200 <= $code && $code <= 299)) {
                    throw new SMTPResponseException($code, $resp, "SMTPHeloError ($code, ".var_export($resp, true).')');
                }
            }
        }
    }

    /**
     * Authentication command - requires response processing.
     *
     * 'mechanism' specifies which authentication mechanism is to be used - the valid values are those listed in
     * the 'auth' element of 'esmtp_features'.
     *
     * 'authobject' must be a callable object taking a single argument: data = authobject(challenge). It will be
     * called to process the server's challenge response; the challenge argument it is passed will be a string.
     * It should return an ASCII string that will be base64 encoded and sent to the server.
     *
     * @return array{int, string}
     */
    public function auth(string $mechanism, callable $authobject, bool $initial_response_ok = true): array
    {
        // RFC 4954 allows auth methods to provide an initial response.  Not all
        // methods support it.  By definition, if they return something other
        // than None when challenge is None, then they do.  See issue #15014.
        $mechanism = strtoupper($mechanism);
        $initial_response = $initial_response_ok ? $authobject(null) : null;
        if ($initial_response !== null) {
            $response = base64_encode($initial_response);
            [$code, $resp] = $this->docmd('AUTH', $mechanism.' '.$response);
            $this->_auth_challenge_count = 1;
        } else {
            [$code, $resp] = $this->docmd('AUTH', $mechanism);
            $this->_auth_challenge_count = 0;
        }
        // If server responds with a challenge, send the response.
        while ($code == 334) {
            $this->_auth_challenge_count++;
            $challenge = base64_decode($resp);
            $response = base64_encode($authobject($challenge));
            [$code, $resp] = $this->docmd($response);
            // If server keeps sending challenges, something is wrong.
            if ($this->_auth_challenge_count > self::_MAXCHALLENGE) {
                throw new SMTPException('Server AUTH mechanism infinite loop. Last response: ('.$code.', '.var_export($resp, true).')');
            }
        }
        if ($code == 235 || $code == 503) {
            return [$code, $resp];
        }
        throw new SMTPResponseException($code, $resp, "SMTPAuthenticationError ($code, ".var_export($resp, true).')');
    }

    /** Authobject to use with CRAM-MD5 authentication. Requires self.user and self.password to be set. */
    public function auth_cram_md5(?string $challenge = null): ?string
    {
        // CRAM-MD5 does not support initial-response.
        if ($challenge === null) {
            return null;
        }

        return $this->user.' '.hash_hmac('md5', $challenge, $this->password);
    }

    /** Authobject to use with PLAIN authentication. Requires self.user and self.password to be set. */
    public function auth_plain(?string $challenge = null): string
    {
        return "\0".$this->user."\0".$this->password;
    }

    /** Authobject to use with LOGIN authentication. Requires self.user and self.password to be set. */
    public function auth_login(?string $challenge = null): string
    {
        if ($challenge === null || $this->_auth_challenge_count < 2) {
            return $this->user;
        }

        return $this->password;
    }

    /**
     * Log in on an SMTP server that requires authentication.
     *
     * The arguments are: user (the user name to authenticate with), password (the password for the authentication).
     *
     * If there has been no previous EHLO or HELO command this session, this method tries ESMTP EHLO first.
     *
     * This method will return normally if the authentication was successful.
     *
     * @return array{int, string}
     */
    public function login(string $user, string $password, bool $initial_response_ok = true): array
    {
        $this->ehlo_or_helo_if_needed();
        if (!$this->has_extn('auth')) {
            throw new SMTPException('SMTP AUTH extension not supported by server.');
        }

        // Authentication methods the server claims to support
        $advertised_authlist = preg_split('/\s+/', trim($this->esmtp_features['auth']), -1, PREG_SPLIT_NO_EMPTY);

        // Authentication methods we can handle in our preferred order:
        $preferred_auths = ['CRAM-MD5', 'PLAIN', 'LOGIN'];

        // We try the supported authentications in our preferred order, if
        // the server supports them.
        $authlist = array_filter($preferred_auths, fn ($auth) => in_array($auth, $advertised_authlist, true));
        if (!$authlist) {
            throw new SMTPException('No suitable authentication method found.');
        }

        // Some servers advertise authentication methods they don't really
        // support, so if authentication fails, we continue until we've tried
        // all methods.
        $this->user = $user;
        $this->password = $password;
        $last_exception = null;
        foreach ($authlist as $authmethod) {
            $method_name = 'auth_'.str_replace('-', '_', strtolower($authmethod));
            try {
                [$code, $resp] = $this->auth($authmethod, $this->$method_name(...), $initial_response_ok);
                // 235 == 'Authentication successful'
                // 503 == 'Error: already authenticated'
                if ($code == 235 || $code == 503) {
                    return [$code, $resp];
                }
            } catch (SMTPResponseException $e) {
                $last_exception = $e;
            }
        }

        // We could not login successfully.  Return result of last attempt.
        throw $last_exception;
    }

    /**
     * Puts the connection to the SMTP server into TLS mode.
     *
     * If there has been no previous EHLO or HELO command this session, this method tries ESMTP EHLO first.
     *
     * If the server supports TLS, this will encrypt the rest of the SMTP session.
     *
     * @return array{int, string}
     */
    public function starttls(): array
    {
        $this->ehlo_or_helo_if_needed();
        if (!$this->has_extn('starttls')) {
            throw new SMTPException('STARTTLS extension not supported by server.');
        }
        [$resp, $reply] = $this->docmd('STARTTLS');
        if ($resp == 220) {
            // ssl._create_stdlib_context() is an unverified context
            stream_context_set_option($this->sock, 'ssl', 'verify_peer', false);
            stream_context_set_option($this->sock, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($this->sock, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($this->sock, 'ssl', 'peer_name', $this->_host);
            if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->close();
                throw new SMTPException('TLS handshake with the server failed');
            }
            // RFC 3207:
            // The client MUST discard any knowledge obtained from
            // the server, such as the list of SMTP service extensions,
            // which was not obtained from the TLS negotiation itself.
            $this->helo_resp = null;
            $this->ehlo_resp = null;
            $this->esmtp_features = [];
            $this->does_esmtp = false;
        } else {
            // RFC 3207:
            // 501 Syntax error (no parameters allowed)
            // 454 TLS not available due to temporary reason
            throw new SMTPResponseException($resp, $reply);
        }

        return [$resp, $reply];
    }

    /**
     * This command performs an entire mail transaction.
     *
     * The arguments are: from_addr (the address sending this mail), to_addrs (a list of addresses to send this
     * mail to, a bare string is treated as a list with 1 address), msg (the message to send), mail_options
     * (list of ESMTP options (such as 8bitmime) for the mail command), rcpt_options (list of ESMTP options for
     * the rcpt commands).
     *
     * msg may be a string containing characters in the ASCII range. Lone \r and \n characters are converted to
     * \r\n characters.
     *
     * If there has been no previous EHLO or HELO command this session, this method tries ESMTP EHLO first. If the
     * server does ESMTP, message size and each of the specified options will be passed to it. If EHLO fails, HELO
     * will be tried and ESMTP options suppressed.
     *
     * This method will return normally if the mail is accepted for at least one recipient. It returns an array,
     * with one entry for each recipient that was refused. Each entry contains a tuple of the SMTP error code and
     * the accompanying error message sent by the server.
     *
     * Note: the connection will be open even after an exception is raised.
     *
     * @param string|list<string> $to_addrs
     * @param list<string> $mail_options
     * @param list<string> $rcpt_options
     * @return array<string, array{int, string}>
     */
    public function sendmail(string $from_addr, string|array $to_addrs, string $msg, array $mail_options = [], array $rcpt_options = []): array
    {
        $this->ehlo_or_helo_if_needed();
        $esmtp_opts = [];
        $msg = self::_fix_eols($msg);
        if (preg_match('/[^\x00-\x7F]/', $msg)) {
            throw new \ValueError("'ascii' codec can't encode characters in the message");
        }
        if ($this->does_esmtp) {
            if ($this->has_extn('size')) {
                $esmtp_opts[] = sprintf('size=%d', strlen($msg));
            }
            foreach ($mail_options as $option) {
                $esmtp_opts[] = $option;
            }
        }
        [$code, $resp] = $this->mail($from_addr, $esmtp_opts);
        if ($code != 250) {
            if ($code == 421) {
                $this->close();
            } else {
                $this->_rset();
            }
            throw new SMTPResponseException($code, $resp, "SMTPSenderRefused ($code, ".var_export($resp, true).", '$from_addr')");
        }
        $senderrs = [];
        if (is_string($to_addrs)) {
            $to_addrs = [$to_addrs];
        }
        foreach ($to_addrs as $each) {
            [$code, $resp] = $this->rcpt($each, $rcpt_options);
            if ($code != 250 && $code != 251) {
                $senderrs[$each] = [$code, $resp];
            }
            if ($code == 421) {
                $this->close();
                throw new SMTPResponseException($code, $resp, 'SMTPRecipientsRefused '.json_encode($senderrs));
            }
        }
        if (count($senderrs) == count($to_addrs)) {
            // the server refused all our recipients
            $this->_rset();
            throw new SMTPResponseException($code, $resp, 'SMTPRecipientsRefused '.json_encode($senderrs));
        }
        [$code, $resp] = $this->data($msg);
        if ($code != 250) {
            if ($code == 421) {
                $this->close();
            } else {
                $this->_rset();
            }
            throw new SMTPResponseException($code, $resp, "SMTPDataError ($code, ".var_export($resp, true).')');
        }
        // if we got here then somebody got our mail
        return $senderrs;
    }

    /** Close the connection to the SMTP server. */
    public function close(): void
    {
        if ($this->sock) {
            $sock = $this->sock;
            $this->sock = null;
            @fclose($sock);
        }
    }

    /**
     * Terminate the SMTP session.
     *
     * @return array{int, string}
     */
    public function quit(): array
    {
        $res = $this->docmd('quit');
        // A new EHLO is required after reconnecting with connect()
        $this->ehlo_resp = $this->helo_resp = null;
        $this->esmtp_features = [];
        $this->does_esmtp = false;
        $this->close();

        return $res;
    }

    /** Quote a subset of the email addresses defined by RFC 821. */
    private static function quoteaddr(string $addrstring): string
    {
        [$displayname, $addr] = self::parseaddr($addrstring);
        if ([$displayname, $addr] === ['', '']) {
            // parseaddr couldn't parse it, use it as is and hope for the best.
            if (str_starts_with(trim($addrstring), '<')) {
                return $addrstring;
            }

            return "<$addrstring>";
        }

        return "<$addr>";
    }

    /**
     * `email.utils.parseaddr`: `[display name, address]`, `['', '']` when there is no address.
     *
     * ponytail: `Name <addr>`, `"Name" <addr>`, `addr` and `addr (comment)` only (no group syntax, quoted local
     * parts with spaces, or routes); add the rest of RFC 5322 when an address needs it.
     */
    private static function parseaddr(string $addr): array
    {
        $addr = trim($addr);
        if (preg_match('/^(.*?)<([^<>]*)>\s*$/s', $addr, $m)) {
            $name = trim(preg_replace('/\([^)]*\)/', '', trim($m[1])));
            if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
                $name = stripslashes(substr($name, 1, -1));
            }

            return [$name, trim($m[2])];
        }
        $bare = trim(preg_replace('/\([^)]*\)/', '', $addr));
        if ($bare === '' || preg_match('/\s/', $bare)) {
            return ['', ''];
        }

        return ['', $bare];
    }

    private static function _quote_periods(string $bindata): string
    {
        return preg_replace('/^\./m', '..', $bindata);
    }

    private static function _fix_eols(string $data): string
    {
        return preg_replace('/(?:\r\n|\n|\r(?!\n))/', self::CRLF, $data);
    }
}
