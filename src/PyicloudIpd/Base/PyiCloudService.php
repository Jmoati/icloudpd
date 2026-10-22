<?php

namespace PyicloudIpd\Base;

use Foundation\Core;
use Foundation\Http\LWPCookieJar;
use Foundation\Http\Request;
use Foundation\Http\Response;
use Foundation\Json;
use Foundation\OSError;
use Foundation\Os;
use Foundation\Patch;
use Foundation\Py;
use Foundation\String_;
use PyicloudIpd\Base;
use PyicloudIpd\Exceptions\PyiCloudAPIResponseException;
use PyicloudIpd\Exceptions\PyiCloudConnectionException;
use PyicloudIpd\Exceptions\PyiCloudFailedLoginException;
use PyicloudIpd\Exceptions\PyiCloudServiceNotActivatedException;
use PyicloudIpd\Services\Photos\PhotosService;
use PyicloudIpd\Session\PyiCloudPasswordFilter;
use PyicloudIpd\Session\PyiCloudSession;
use PyicloudIpd\Sms;
use PyicloudIpd\Sms\AuthenticatedSession;
use PyicloudIpd\Sms\TrustedDevice;
use PyicloudIpd\Srp;
use PyicloudIpd\Srp\User;

/**
 * A base authentication class for the iCloud service. Handles the
 * authentication required to access iCloud services.
 *
 * Usage:
 *     $pyicloud = new PyiCloudService('com', 'username@apple.com', fn() => 'password');
 *
 * The Python properties are methods (`requires_2fa()`, `requires_2sa()`, `is_trusted_session()`,
 * `trusted_devices()`, `session_path()`, `cookiejar_path()`, `photos()`); reading them as properties
 * (`$icloud->requires_2fa`) works too.
 */
class PyiCloudService
{
    public string $apple_id;
    /** @var callable(): ?string */
    public $password_provider;
    /** @var array<string, mixed> */
    public array $data = [];
    /** @var array<string, mixed> */
    public array $params = [];
    public string $client_id;
    public bool $with_family;
    public float $http_timeout;
    /** @var ?callable(array<string, mixed>): void */
    public $response_observer;
    /** @var list<array{0: string, 1: callable}> the rules of the observer: [pattern, function] */
    public array $observer_rules = [];

    /** set it when we get password */
    public ?PyiCloudPasswordFilter $password_filter = null;

    public string $AUTH_ROOT_ENDPOINT;
    public string $AUTH_ENDPOINT;
    public string $HOME_ENDPOINT;
    public string $SETUP_ENDPOINT;
    public string $domain;

    public string $_cookie_directory;

    /** @var array<string, mixed> */
    public array $session_data = [];
    public PyiCloudSession $session;

    /** @var list<array{0: string, 1: callable}> */
    public array $cookie_obfuscate_rules;
    public array $header_obfuscate_rules;
    public array $header_pass_rules;
    public array $header_drop_rules;
    public array $validate_response_body_obfuscate_rules;
    public array $validate_response_body_drop_rules;
    public array $auth_srp_init_body_obfuscate_rules;
    public array $auth_srp_init_body_drop_rules;
    public array $auth_srp_complete_body_obfuscate_rules;
    public array $auth_srp_complete_body_drop_rules;
    public array $auth_srp_repair_complete_body_obfuscate_rules;
    public array $auth_srp_repair_complete_body_drop_rules;
    public array $auth_raw_body_obfuscate_rules;
    public array $auth_raw_body_drop_rules;
    public array $auth_token_body_obfuscate_rules;
    public array $auth_token_body_drop_rules;

    /** @var array<string, mixed> */
    public array $_webservices = [];
    public ?PhotosService $_photos = null;

    /**
     * @param callable(): ?string $password_provider
     * @param ?callable(array<string, mixed>): void $response_observer
     */
    public function __construct(
        string $domain,
        string $apple_id,
        callable $password_provider,
        ?callable $response_observer = null,
        ?string $cookie_directory = null,
        bool|string $verify = true,
        ?string $client_id = null,
        bool $with_family = true,
        float $http_timeout = 30.0,
    ) {
        $this->apple_id = $apple_id;
        $this->password_provider = $password_provider;
        $this->data = [];
        $this->params = [];
        $this->client_id = Py::truthy($client_id) ? $client_id : ('auth-'.strtolower(Base::uuid1()));
        $this->with_family = $with_family;
        $this->http_timeout = $http_timeout;
        $this->response_observer = $response_observer;
        $this->observer_rules = [];

        // set it when we get password
        $this->password_filter = null;

        if ($domain === 'com') {
            $this->AUTH_ROOT_ENDPOINT = 'https://idmsa.apple.com';
            $this->AUTH_ENDPOINT = 'https://idmsa.apple.com/appleauth/auth';
            $this->HOME_ENDPOINT = 'https://www.icloud.com';
            $this->SETUP_ENDPOINT = 'https://setup.icloud.com/setup/ws/1';
        } elseif ($domain === 'cn') {
            $this->AUTH_ROOT_ENDPOINT = 'https://idmsa.apple.com.cn';
            $this->AUTH_ENDPOINT = 'https://idmsa.apple.com.cn/appleauth/auth';
            $this->HOME_ENDPOINT = 'https://www.icloud.com.cn';
            $this->SETUP_ENDPOINT = 'https://setup.icloud.com.cn/setup/ws/1';
        } else {
            throw new \BadMethodCallException("Domain '$domain' is not supported yet");
        }

        $this->domain = $domain;

        if ($cookie_directory) {
            $this->_cookie_directory = Os::path_expanduser(Os::path_normpath($cookie_directory));
            if (!Os::path_exists($this->_cookie_directory)) {
                mkdir($this->_cookie_directory, 0700);
            }
        } else {
            $topdir = Os::path_join(sys_get_temp_dir(), 'pyicloud');
            $this->_cookie_directory = Os::path_join($topdir, self::getuser());
            if (!Os::path_exists($topdir)) {
                mkdir($topdir, 0777);
            }
            if (!Os::path_exists($this->_cookie_directory)) {
                mkdir($this->_cookie_directory, 0700);
            }
        }

        Base::LOGGER()->debug('Using session file %s', $this->session_path());

        $this->session_data = [];
        $session_f = @file_get_contents($this->session_path());
        if ($session_f === false) {
            // FileNotFoundError
            Base::LOGGER()->info('Session file does not exist');
        } else {
            try {
                $this->session_data = json_decode($session_f, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // json.JSONDecodeError
                Base::LOGGER()->info('Session file does not exist');
            }
        }
        $session_client_id = $this->session_data['client_id'] ?? null;
        if ($session_client_id) {
            $this->client_id = $session_client_id;
        } else {
            $this->session_data['client_id'] = $this->client_id;
        }

        $apply_rules_and_observe = function (array $response): void {
            if ($this->response_observer) {
                ($this->response_observer)(Json::apply_rules('', $this->observer_rules, $response));
            }
        };

        $this->session = new PyiCloudSession(
            $this,
            $this->response_observer ? $apply_rules_and_observe : null,
        );
        $this->session->verify = $verify;
        $this->session->headers->update([
            'Origin' => $this->HOME_ENDPOINT,
            'Referer' => "$this->HOME_ENDPOINT/",
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        ]);

        $cookiejar_path = $this->cookiejar_path();
        $this->session->cookies = new LWPCookieJar(filename: $cookiejar_path);
        if (Os::path_exists($cookiejar_path)) {
            try {
                $this->session->cookies->load(ignore_discard: true, ignore_expires: true);
                Base::LOGGER()->debug('Read cookies from %s', $cookiejar_path);
            } catch (OSError) {
                // Most likely a pickled cookiejar from earlier versions.
                // The cookiejar will get replaced with a valid one after
                // successful authentication.
                Base::LOGGER()->warning('Failed to read cookiejar %s', $cookiejar_path);
            }
        }

        // Unsure if this is still needed
        $this->params = [
            'clientBuildNumber' => '2522Project44',
            'clientMasteringNumber' => '2522B2',
            'clientId' => $this->client_id,
        ];

        // set observer rules
        $obfuscate_rules_from_pattern = static fn (array $patterns): array => array_map(
            static fn (string $p) => [Json::re_compile_ignorecase($p), String_::obfuscate(...)],
            $patterns,
        );
        $this->cookie_obfuscate_rules = $obfuscate_rules_from_pattern(
            ['X_APPLE_.*', 'DES.*', 'acn01', 'aasp'],
        );

        $this->header_obfuscate_rules = $obfuscate_rules_from_pattern(['X-APPLE-.*', 'scnt']);

        $pass_rules_from_pattern = static fn (array $patterns): array => array_map(
            static fn (string $p) => [Json::re_compile_ignorecase($p), Core::identity(...)],
            $patterns,
        );
        $this->header_pass_rules = $pass_rules_from_pattern(
            ['^(request|response)\.headers\.(Origin|Referer|Content-Type|Location)$'],
        );

        $drop_rules_from_pattern = static fn (array $patterns): array => array_map(
            static fn (string $p) => [Json::re_compile_ignorecase($p), Core::constant(null)],
            $patterns,
        );
        $this->header_drop_rules = $drop_rules_from_pattern(
            ['^(request|response)\.headers\..+'],
        );

        $this->validate_response_body_obfuscate_rules = $obfuscate_rules_from_pattern(
            [
                '^response\.content\.dsInfo\.appleId$',
                '^response\.content\.dsInfo\.appleIdAlias$',
                '^response\.content\.dsInfo\.iCloudAppleIdAlias$',
                '^response\.content\.dsInfo\.appleIdEntries\.value$',
                '^response\.content\.dsInfo\.fullName',
                '^response\.content\.dsInfo\.firstName',
                '^response\.content\.dsInfo\.lastName',
                '^response\.content\.dsInfo\.dsid',
                '^response\.content\.dsInfo\.notificationId',
                '^response\.content\.dsInfo\.aDsID',
                '^response\.content\.dsInfo\.primaryEmail',
            ],
        );
        $this->validate_response_body_drop_rules = $drop_rules_from_pattern(
            [
                '^response\.content\.webservices\.',
                '^response\.content\.configBag\.urls\.',
                '^response\.content\.apps.*',
                '^response\.content\.dsInfo\.mailFlags',
            ],
        );
        $this->auth_srp_init_body_obfuscate_rules = $obfuscate_rules_from_pattern(
            [
                '^request\.content\.accountName',
                '^request\.content\.a$',
                '^response\.content\.salt',
                '^response\.content\.b',
                '^response\.content\.c',
            ],
        );
        $this->auth_srp_init_body_drop_rules = $drop_rules_from_pattern([]);
        $this->auth_srp_complete_body_obfuscate_rules = $obfuscate_rules_from_pattern(
            [
                '^request\.content\.accountName',
                '^request\.content\.c',
                '^request\.content\.m1',
                '^request\.content\.m2',
                '^request\.content\.trustTokens\.',
            ],
        );
        $this->auth_srp_complete_body_drop_rules = $drop_rules_from_pattern([]);
        $this->auth_srp_repair_complete_body_obfuscate_rules = $obfuscate_rules_from_pattern([]);
        $this->auth_srp_repair_complete_body_drop_rules = $drop_rules_from_pattern([]);
        $this->auth_raw_body_obfuscate_rules = $obfuscate_rules_from_pattern(
            [
                '^request\.content\.accountName',
                '^request\.content\.password',
                '^request\.content\.trustTokens\.',
            ],
        );
        $this->auth_raw_body_drop_rules = $drop_rules_from_pattern([]);
        $this->auth_token_body_obfuscate_rules = array_merge(
            $obfuscate_rules_from_pattern(
                [
                    '^request\.content\.dsWebAuthToken',
                    '^request\.content\.trustToken',
                ],
            ),
            $this->validate_response_body_obfuscate_rules,
        );
        $this->auth_token_body_drop_rules = $this->validate_response_body_drop_rules;

        $this->authenticate();

        $this->_photos = null;
    }

    /** `getpass.getuser()` */
    private static function getuser(): string
    {
        foreach (['LOGNAME', 'USER', 'LNAME', 'USERNAME'] as $name) {
            $user = getenv($name);
            if ($user) {
                return $user;
            }
        }

        return posix_getpwuid(posix_geteuid())['name'];
    }

    /**
     * `with self.use_rules(rules): ...`: runs `$f` with the observer rules set, and restores them.
     *
     * @param list<array{0: string, 1: callable}> $rules
     */
    public function use_rules(array $rules, \Closure $f): mixed
    {
        $temp_rules = $this->observer_rules;
        try {
            $this->observer_rules = $rules;

            return $f($temp_rules);
        } finally {
            $this->observer_rules = $temp_rules;
        }
    }

    /**
     * The rules that obfuscate cookies and headers, plus `$extra` (`list(chain(...))` of `base.py`), when
     * there is an observer.
     *
     * @param list<array{0: string, 1: callable}> ...$extra
     * @return list<array{0: string, 1: callable}>
     */
    private function rules(array ...$extra): array
    {
        if ($this->response_observer) {
            return array_merge(
                $this->cookie_obfuscate_rules,
                $this->header_obfuscate_rules,
                $this->header_pass_rules,
                $this->header_drop_rules,
                ...$extra,
            );
        }

        return [];
    }

    /**
     * Handles authentication, and persists cookies so that
     * subsequent logins will not cause additional e-mails from Apple.
     */
    public function authenticate(bool $force_refresh = false): void
    {
        if ($p = Patch::get(__METHOD__)) {
            $p($this, $force_refresh);

            return;
        }
        $login_successful = false;
        if (Py::truthy($this->session_data['session_token'] ?? null) && !$force_refresh) {
            Base::LOGGER()->debug('Checking session token validity');
            try {
                $this->data = $this->_validate_token();
                $login_successful = true;
            } catch (PyiCloudAPIResponseException) {
                Base::LOGGER()->debug('Invalid authentication token, will log in from scratch.');
            }
        }

        if (!$login_successful) {
            $password = ($this->password_provider)();
            if (!Py::truthy($password)) {
                Base::LOGGER()->debug('Password Provider did not give any data');

                return;
            }
            // set logging filter
            $this->password_filter = new PyiCloudPasswordFilter($password);
            Base::LOGGER()->addFilter($this->password_filter);

            Base::LOGGER()->debug("Authenticating as $this->apple_id");
            $this->_authenticate_srp($password);
            // try:
            //     self._authenticate_srp(password)
            // except PyiCloudFailedLoginException as error:
            //     LOGGER.error("Failed to login with srp, falling back to old raw password authentication. Error: %s", error)
            //     try:
            //         self._authenticate_raw_password(password)
            //     except PyiCloudFailedLoginException as error:
            //         LOGGER.error("Failed to login with raw password. Error: %s", error)
            //         raise error

            $this->_authenticate_with_token();
        }

        // Is this needed?
        $this->params['dsid'] = $this->data['dsInfo']['dsid'];

        $this->_webservices = $this->data['webservices'];

        Base::LOGGER()->info('Authentication completed successfully');
        Base::LOGGER()->debug($this->params);
    }

    /** Authenticate using session token. */
    public function _authenticate_with_token(): void
    {
        $data = [
            'accountCountryCode' => $this->session_data['account_country'] ?? null,
            'dsWebAuthToken' => $this->session_data['session_token'] ?? null,
            'extended_login' => true,
            'trustToken' => $this->session_data['trust_token'] ?? '',
        ];

        try {
            // set observer with obfuscator
            $rules = $this->rules($this->auth_token_body_obfuscate_rules, $this->auth_token_body_drop_rules);

            $req = $this->use_rules($rules, fn () => $this->session->post(
                "$this->SETUP_ENDPOINT/accountLogin",
                data: Py::json_dumps($data),
            ));
            $this->data = $req->json();
        } catch (PyiCloudAPIResponseException $error) {
            $msg = 'Invalid authentication token.';
            throw new PyiCloudFailedLoginException($msg, $error);
        }

        // {'domainToUse': 'iCloud.com'}
        $domain_to_use = $this->data['domainToUse'] ?? null;
        if ($domain_to_use !== null) {
            $msg = "Apple insists on using $domain_to_use for your request. Please use --domain parameter";
            throw new PyiCloudConnectionException($msg);
        }
    }

    public function _authenticate_srp(string $password): void
    {
        $srp_password = new class($password) {
            // srp uses the encoded password at process_challenge(), thus set_encrypt_info() should be called before that
            public string $protocol;
            public string $salt;
            public int $iterations;

            public function __construct(public string $pwd)
            {
            }

            public function set_encrypt_info(string $protocol, string $salt, int $iterations): void
            {
                $this->protocol = $protocol;
                $this->salt = $salt;
                $this->iterations = $iterations;
            }

            public function encode(): string
            {
                $password_digest = hash('sha256', $this->pwd, $this->protocol !== 's2k_fo');
                $key_length = 32;

                return hash_pbkdf2('sha256', $password_digest, $this->salt, $this->iterations, $key_length, true);
            }
        };

        // Step 1: client generates private key a (stored in srp.User) and public key A, sends to server
        Srp::rfc5054_enable();
        Srp::no_username_in_x();
        $usr = new User($this->apple_id, $srp_password, hash_alg: Srp::SHA256, ng_type: Srp::NG_2048);
        [$uname, $A] = $usr->start_authentication();
        $data = [
            'a' => base64_encode($A),
            'accountName' => $uname,
            'protocols' => ['s2k', 's2k_fo'],
        ];

        $headers = $this->_get_auth_headers(Base::origin_referer_headers($this->AUTH_ROOT_ENDPOINT));
        try {
            $rules = $this->rules($this->auth_srp_init_body_obfuscate_rules, $this->auth_srp_init_body_drop_rules);

            $response = $this->use_rules($rules, fn () => $this->session->post(
                "$this->AUTH_ENDPOINT/signin/init",
                data: Py::json_dumps($data),
                headers: $headers,
            ));
            if ($response->status_code === 401) {
                throw new PyiCloudAPIResponseException($response->text(), (string) $response->status_code);
            }
        } catch (PyiCloudAPIResponseException $error) {
            $msg = 'Failed to initiate srp authentication.';
            throw new PyiCloudFailedLoginException($msg, $error);
        }

        // Step 2: server sends public key B, salt, and c to client
        $body = $response->json();
        $salt = base64_decode($body['salt'] ?? throw new \OutOfBoundsException('salt'));
        $b = base64_decode($body['b'] ?? throw new \OutOfBoundsException('b'));
        $c = $body['c'] ?? throw new \OutOfBoundsException('c');
        $iterations = $body['iteration'] ?? throw new \OutOfBoundsException('iteration');
        $protocol = $body['protocol'] ?? throw new \OutOfBoundsException('protocol');

        // Step 3: client generates session key M1 and M2 with salt and b, sends to server
        $srp_password->set_encrypt_info($protocol, $salt, $iterations);
        $m1 = $usr->process_challenge($salt, $b);
        $m2 = $usr->H_AMK;
        if ($m1 === null) {
            throw new \TypeError("a bytes-like object is required, not 'NoneType'");
        }

        $data = [
            'accountName' => $uname,
            'c' => $c,
            'm1' => base64_encode($m1),
            'm2' => base64_encode($m2),
            'rememberMe' => true,
            'trustTokens' => [],
        ];

        if (Py::truthy($this->session_data['trust_token'] ?? null)) {
            $data['trustTokens'] = [$this->session_data['trust_token']];
        }

        try {
            // set observer with obfuscator
            $rules = $this->rules($this->auth_srp_complete_body_obfuscate_rules, $this->auth_srp_complete_body_drop_rules);

            $response = $this->use_rules($rules, fn () => $this->session->post(
                "$this->AUTH_ENDPOINT/signin/complete",
                params: ['isRememberMeEnabled' => 'true'],
                data: Py::json_dumps($data),
                headers: $headers,
            ));
            if ($response->status_code === 409) {
                // requires 2FA
            } elseif ($response->status_code === 412) {
                // non 2FA account returns 412 "precondition no met"
                $headers = $this->_get_auth_headers();
                // set observer with obfuscator
                $rules = $this->rules($this->auth_srp_repair_complete_body_obfuscate_rules, $this->auth_srp_repair_complete_body_drop_rules);

                $response = $this->use_rules($rules, fn () => $this->session->post(
                    "$this->AUTH_ENDPOINT/repair/complete",
                    data: Py::json_dumps(new \stdClass()),
                    headers: $headers,
                ));
            } elseif ($response->status_code >= 400 && $response->status_code < 600) {
                throw new PyiCloudAPIResponseException($response->text(), (string) $response->status_code);
            }
        } catch (PyiCloudAPIResponseException $error) {
            $msg = 'Invalid email/password combination.';
            throw new PyiCloudFailedLoginException($msg, $error);
        }
    }

    public function _authenticate_raw_password(string $password): void
    {
        $data = [
            'accountName' => $this->apple_id,
            'password' => $password,
            'rememberMe' => true,
            'trustTokens' => [],
        ];
        if (Py::truthy($this->session_data['trust_token'] ?? null)) {
            $data['trustTokens'] = [$this->session_data['trust_token']];
        }

        $headers = $this->_get_auth_headers(Base::origin_referer_headers($this->AUTH_ROOT_ENDPOINT));
        try {
            // set observer with obfuscator
            $rules = $this->rules($this->auth_raw_body_obfuscate_rules, $this->auth_raw_body_drop_rules);

            $this->use_rules($rules, fn () => $this->session->post(
                "$this->AUTH_ENDPOINT/signin",
                params: ['isRememberMeEnabled' => 'true'],
                data: Py::json_dumps($data),
                headers: $headers,
            ));
        } catch (PyiCloudAPIResponseException $error) {
            $msg = 'Invalid email/password combination.';
            throw new PyiCloudFailedLoginException($msg, $error);
        }
    }

    /**
     * Checks if the current access token is still valid.
     *
     * @return array<string, mixed>
     */
    public function _validate_token(): array
    {
        Base::LOGGER()->debug('Checking session token validity');
        $headers = Base::origin_referer_headers($this->HOME_ENDPOINT);
        try {
            // set observer with obfuscator
            $rules = $this->rules($this->validate_response_body_obfuscate_rules, $this->validate_response_body_drop_rules);

            $response = $this->use_rules($rules, fn () => $this->session->post(
                "$this->SETUP_ENDPOINT/validate",
                data: 'null',
                headers: $headers,
            ));
            Base::LOGGER()->debug('Session token is still valid');

            return $response->json();
        } catch (PyiCloudAPIResponseException $err) {
            Base::LOGGER()->debug('Invalid authentication token');
            throw $err;
        }
    }

    /**
     * @param ?array<string, string> $overrides
     * @return array<string, string>
     */
    public function _get_auth_headers(?array $overrides = null): array
    {
        $headers = [
            'Accept' => 'application/json, text/javascript',
            'Content-Type' => 'application/json',
            'X-Apple-OAuth-Client-Id' => 'd39ba9916b7251055b22c7f910e2ea796ee65e98b2ddecea8f5dde8d9d1a815d',
            'X-Apple-OAuth-Client-Type' => 'firstPartyAuth',
            'X-Apple-OAuth-Redirect-URI' => $this->domain === 'cn'
                ? 'https://www.icloud.com.cn'
                : 'https://www.icloud.com',
            'X-Apple-OAuth-Require-Grant-Code' => 'true',
            'X-Apple-OAuth-Response-Mode' => 'web_message',
            'X-Apple-OAuth-Response-Type' => 'code',
            'X-Apple-OAuth-State' => $this->client_id,
            'X-Apple-Widget-Key' => 'd39ba9916b7251055b22c7f910e2ea796ee65e98b2ddecea8f5dde8d9d1a815d',
        ];
        $scnt = $this->session_data['scnt'] ?? null;
        if ($scnt) {
            $headers['scnt'] = $scnt;
        }

        $session_id = $this->session_data['session_id'] ?? null;
        if ($session_id) {
            $headers['X-Apple-ID-Session-Id'] = $session_id;
        }

        if ($overrides) {
            $headers = array_merge($headers, $overrides);
        }

        return $headers;
    }

    /** Get path for cookiejar file. */
    public function cookiejar_path(): string
    {
        return Os::path_join(
            $this->_cookie_directory,
            preg_replace('/[^\w]/', '', $this->apple_id),
        );
    }

    /** Get path for session data file. */
    public function session_path(): string
    {
        return Os::path_join(
            $this->_cookie_directory,
            preg_replace('/[^\w]/', '', $this->apple_id).'.session',
        );
    }

    /** Returns true if two-step authentication is required. */
    public function requires_2sa(): bool
    {
        return ($this->data['dsInfo']['hsaVersion'] ?? 0) >= 1 && (
            Py::truthy($this->data['hsaChallengeRequired'] ?? false) || !$this->is_trusted_session()
        );
    }

    /** Returns true if two-factor authentication is required. */
    public function requires_2fa(): bool
    {
        return ($this->data['dsInfo']['hsaVersion'] ?? 0) == 2
            && (Py::truthy($this->data['hsaChallengeRequired'] ?? false) || !$this->is_trusted_session())
            && Py::truthy($this->data['dsInfo']['hasICloudQualifyingDevice'] ?? false);
    }

    /** Returns true if the session is trusted. */
    public function is_trusted_session(): bool
    {
        return (bool) ($this->data['hsaTrustedBrowser'] ?? false);
    }

    /**
     * Returns devices trusted for two-step authentication.
     *
     * @return list<array<string, mixed>>
     */
    public function trusted_devices(): array
    {
        $request = $this->session->get("$this->SETUP_ENDPOINT/listDevices", params: $this->params);
        $devices = $request->json()['devices'] ?? null;
        if ($devices) {
            return $devices;
        }

        return [];
    }

    public function send_request(Request $request): Response
    {
        return $this->session->send($request);
    }

    public function get_oauth_session(): AuthenticatedSession
    {
        return new AuthenticatedSession(
            client_id: $this->client_id,
            scnt: $this->session_data['scnt'] ?? throw new \OutOfBoundsException('scnt'),
            session_id: $this->session_data['session_id'] ?? throw new \OutOfBoundsException('session_id'),
        );
    }

    /**
     * Returns list of trusted phone number for sms 2fa
     *
     * @return list<TrustedDevice>
     */
    public function get_trusted_phone_numbers(): array
    {
        $oauth_session = $this->get_oauth_session();
        $context = new TrustedPhoneContextProvider(domain: $this->domain, oauth_session: $oauth_session);

        $req = Sms::build_trusted_phone_numbers_request($context);
        $request = (new Request())->prepare(method: $req->method, url: $req->url, headers: $req->headers);

        $rules = $this->rules();

        $response = $this->use_rules($rules, fn () => $this->send_request($request));

        return Sms::parse_trusted_phone_numbers_response($response);
    }

    /**
     * Triggers a push notification to trusted devices for 2FA code entry.
     *
     * Sends PUT to /verify/trusteddevice/securitycode (no body). Apple's new
     * auth flow (2026+) uses this endpoint to push a code to trusted devices.
     */
    public function trigger_push_notification(): bool
    {
        $headers = $this->_get_auth_headers(['Accept' => 'application/json']);
        try {
            $rules = $this->rules();

            $this->use_rules($rules, fn () => $this->session->put(
                "$this->AUTH_ENDPOINT/verify/trusteddevice/securitycode",
                headers: $headers,
            ));

            return true;
        } catch (PyiCloudAPIResponseException) {
            return false;
        }
    }

    /** Requests that a verification code is sent to the given device */
    public function send_2fa_code_sms(int $device_id): bool
    {
        $oauth_session = $this->get_oauth_session();
        $context = new TrustedPhoneContextProvider(domain: $this->domain, oauth_session: $oauth_session);

        $req = Sms::build_send_sms_code_request($context, $device_id);
        $request = (new Request())->prepare(
            method: $req->method,
            url: $req->url,
            headers: $req->headers,
            data: $req->data,
            json: $req->json,
        );

        $rules = $this->rules();

        $response = $this->use_rules($rules, fn () => $this->send_request($request));

        return $response->ok();
    }

    /**
     * Requests that a verification code is sent to the given device
     *
     * @param array<string, mixed> $device
     */
    public function send_verification_code(array $device): bool
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this, $device);
        }
        $data = Py::json_dumps($device);
        $request = $this->session->post(
            "$this->SETUP_ENDPOINT/sendVerificationCode",
            params: $this->params,
            data: $data,
        );

        return (bool) ($request->json()['success'] ?? false);
    }

    /**
     * Verifies a verification code received on a trusted device.
     *
     * @param array<string, mixed> $device
     */
    public function validate_verification_code(array $device, string $code): bool
    {
        $device = array_merge($device, ['verificationCode' => $code, 'trustBrowser' => true]);
        $data = Py::json_dumps($device);

        try {
            $this->session->post(
                "$this->SETUP_ENDPOINT/validateVerificationCode",
                params: $this->params,
                data: $data,
            );
        } catch (PyiCloudAPIResponseException $error) {
            if ((string) $error->code === '-21669') {
                // Wrong verification code
                return false;
            }
            throw $error;
        }

        // When validating a code through the 2SA endpoint
        // the /2sv/trust URL returns 404 and won't return a trust token
        // self.trust_session()
        $this->_authenticate_with_token();

        return !$this->requires_2sa();
    }

    /** Verifies a verification code received via Apple's 2FA system through SMS. */
    public function validate_2fa_code_sms(int $device_id, string $code): bool
    {
        $oauth_session = $this->get_oauth_session();
        $context = new TrustedPhoneContextProvider(domain: $this->domain, oauth_session: $oauth_session);

        $req = Sms::build_verify_sms_code_request($context, $device_id, $code);
        $request = (new Request())->prepare(
            method: $req->method,
            url: $req->url,
            headers: $req->headers,
            data: $req->data,
            json: $req->json,
        );
        $rules = $this->rules();

        $response = $this->use_rules($rules, fn () => $this->send_request($request));

        if ($response->ok()) {
            return $this->trust_session();
        }

        return false;
    }

    /** Verifies a verification code received via Apple's 2FA system (HSA2). */
    public function validate_2fa_code(string $code): bool
    {
        $data = ['securityCode' => ['code' => $code]];

        $headers = $this->_get_auth_headers(['Accept' => 'application/json']);

        try {
            $rules = $this->rules();

            $this->use_rules($rules, fn () => $this->session->post(
                "$this->AUTH_ENDPOINT/verify/trusteddevice/securitycode",
                data: Py::json_dumps($data),
                headers: $headers,
            ));
        } catch (PyiCloudAPIResponseException $error) {
            if ((string) $error->code === '-21669') {
                // Wrong verification code
                Base::LOGGER()->error('Code verification failed.');

                return false;
            }
            throw $error;
        }

        Base::LOGGER()->debug('Code verification successful.');

        $this->trust_session();

        return !$this->requires_2sa();
    }

    /** Request session trust to avoid user log in going forward. */
    public function trust_session(): bool
    {
        $headers = $this->_get_auth_headers();

        try {
            $rules = $this->rules();

            $this->use_rules($rules, fn () => $this->session->get(
                "$this->AUTH_ENDPOINT/2sv/trust",
                headers: $headers,
            ));
            $this->_authenticate_with_token();

            return true;
        } catch (PyiCloudAPIResponseException) {
            Base::LOGGER()->error('Session trust failed.');

            return false;
        }
    }

    /** Get webservice URL, throw an exception if not exists. */
    public function _get_webservice_url(string $ws_key): string
    {
        if (($this->_webservices[$ws_key] ?? null) === null) {
            throw new PyiCloudServiceNotActivatedException('Webservice not available', $ws_key);
        }

        return $this->_webservices[$ws_key]['url'];
    }

    /** Gets the 'Photo' service. */
    public function photos(): PhotosService
    {
        if (!$this->_photos) {
            $service_root = $this->_get_webservice_url('ckdatabasews');
            $this->_photos = new PhotosService($service_root, $this->session, $this->params);
        }

        return $this->_photos;
    }

    public function __toString(): string
    {
        return "iCloud API: $this->apple_id";
    }

    /** `repr()` */
    public function __repr__(): string
    {
        return '<'.$this.'>';
    }

    /** The Python properties (`requires_2fa`, `photos`...) read as PHP properties. */
    public function __get(string $name): mixed
    {
        if (in_array($name, ['requires_2fa', 'requires_2sa', 'is_trusted_session', 'trusted_devices', 'session_path', 'cookiejar_path', 'photos'], true)) {
            return $this->$name();
        }
        trigger_error("Undefined property: ".static::class."::\$$name", E_USER_WARNING);

        return null;
    }
}
