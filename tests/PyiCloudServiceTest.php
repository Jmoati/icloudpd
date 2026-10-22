<?php

namespace Tests;

use Foundation\Http\Request;
use Foundation\Http\Session;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudFailedLoginException;
use PyicloudIpd\Exceptions\PyiCloudServiceUnavailableException;
use PyicloudIpd\Sms\TrustedDevice;

/**
 * `PyiCloudService` replayed against the cassettes of the authentication tests, without the CLI: the requests
 * are the ones of the Python `PyiCloudService` (listed below, taken from a run of the upstream code over the same
 * cassettes), in the same order, and the cassette must be played to the end when Python does.
 * `CLIENT_ID` is set by phpunit.xml (the `DEFAULT_ENV` of the upstream helpers).
 */
class PyiCloudServiceTest extends TestCase
{
    private string $cookie_dir;
    /** @var list<string> the requests sent, in order */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookie_dir = sys_get_temp_dir().'/pyicloud_service_'.uniqid();
        mkdir($this->cookie_dir);
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cookie_dir.'/*'));
        rmdir($this->cookie_dir);
        parent::tearDown();
    }

    private function service(?string $password = 'password1'): PyiCloudService
    {
        return new PyiCloudService('com', 'jdoe@gmail.com', fn () => $password, null, cookie_directory: $this->cookie_dir, client_id: getenv('CLIENT_ID'));
    }

    /** runs `$flow` with the cassette, noting the requests; returns what `$flow` returns */
    private function with_cassette(string $name, callable $flow, ?bool $all_played, array $expected_requests): mixed
    {
        $result = Vcr::use_cassette($name, function ($cassette) use ($flow, &$all_played) {
            $inner = Session::$transport;
            Session::$transport = function (Request $request) use ($inner) {
                $this->requests[] = $request->method.' '.$request->url;

                return $inner($request);
            };
            try {
                return $flow();
            } finally {
                Session::$transport = $inner;
                $all_played = $cassette->all_played === $all_played;
            }
        });
        $this->assertTrue($all_played, "$name: all_played is not what Python gets");
        $this->assertSame($expected_requests, $this->requests, "$name: requests");

        return $result;
    }

    public function test_auth_requires_2fa(): void
    {
        $service = $this->with_cassette('auth_requires_2fa', fn () => $this->service(), true, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            'POST https://idmsa.apple.com/appleauth/auth/signin/complete?isRememberMeEnabled=true',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
        ]);
        $this->assertTrue($service->requires_2fa());
        $this->assertTrue($service->requires_2sa());
        // `requires_2fa` also reads as a property
        $this->assertTrue($service->requires_2fa);
        $this->assertSame('12345678901', $service->params['dsid']);
        $this->assertSame('iCloud API: jdoe@gmail.com', (string) $service);

        // the session of the account is saved like Python does (the same bytes), and its cookies
        $this->assertSame(
            '{"client_id": "'.getenv('CLIENT_ID').'", "scnt": "scnt-1234567890", "account_country": "USA", "session_id": "sess-1234567890", "session_token": "token-1234567890", "trust_eligible": "true"}',
            file_get_contents($this->cookie_dir.'/jdoegmailcom.session'),
        );
        $this->assertStringStartsWith("#LWP-Cookies-2.0\nSet-Cookie3: X-APPLE-UNIQUE-CLIENT-ID=", file_get_contents($this->cookie_dir.'/jdoegmailcom'));
        $this->assertSame(0600, fileperms($this->cookie_dir.'/jdoegmailcom') & 0777);
    }

    public function test_2fa_flow_valid_code(): void
    {
        $result = $this->with_cassette('2fa_flow_valid_code', function () {
            $service = $this->service();
            $requires_2fa = $service->requires_2fa();
            $devices = $service->get_trusted_phone_numbers();
            $pushed = $service->trigger_push_notification();

            return [$requires_2fa, $devices, $pushed, $service->validate_2fa_code('654321')];
        }, true, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            'POST https://idmsa.apple.com/appleauth/auth/signin/complete?isRememberMeEnabled=true',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
            'GET https://idmsa.apple.com/appleauth/auth',
            'PUT https://idmsa.apple.com/appleauth/auth/verify/trusteddevice/securitycode',
            'POST https://idmsa.apple.com/appleauth/auth/verify/trusteddevice/securitycode',
            'GET https://idmsa.apple.com/appleauth/auth/2sv/trust',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
        ]);
        $this->assertEquals([true, [new TrustedDevice(1, '(***) ***-**81')], true, true], $result);
    }

    public function test_2fa_flow_invalid_code(): void
    {
        $result = $this->with_cassette('2fa_flow_invalid_code', function () {
            $service = $this->service();

            return [$service->trigger_push_notification(), $service->validate_2fa_code('901431')];
        }, false, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            'POST https://idmsa.apple.com/appleauth/auth/signin/complete?isRememberMeEnabled=true',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
            'PUT https://idmsa.apple.com/appleauth/auth/verify/trusteddevice/securitycode',
            'POST https://idmsa.apple.com/appleauth/auth/verify/trusteddevice/securitycode',
        ]);
        $this->assertSame([true, false], $result);
    }

    public function test_2sa_flow_valid_code(): void
    {
        $result = $this->with_cassette('2sa_flow_valid_code', function () {
            $service = $this->service();
            $requires_2sa = $service->requires_2sa();
            $devices = $service->trusted_devices();

            return [$requires_2sa, count($devices), $service->send_verification_code($devices[0]), $service->validate_verification_code($devices[0], '654321')];
        }, true, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            'POST https://idmsa.apple.com/appleauth/auth/signin/complete?isRememberMeEnabled=true',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
            'GET https://setup.icloud.com/setup/ws/1/listDevices?clientBuildNumber=2522Project44&clientMasteringNumber=2522B2&clientId=DE309E26-942E-11E8-92F5-14109FE0B321&dsid=12345678901',
            'POST https://setup.icloud.com/setup/ws/1/sendVerificationCode?clientBuildNumber=2522Project44&clientMasteringNumber=2522B2&clientId=DE309E26-942E-11E8-92F5-14109FE0B321&dsid=12345678901',
            'POST https://setup.icloud.com/setup/ws/1/validateVerificationCode?clientBuildNumber=2522Project44&clientMasteringNumber=2522B2&clientId=DE309E26-942E-11E8-92F5-14109FE0B321&dsid=12345678901',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
        ]);
        $this->assertSame([true, 1, true, true], $result);
    }

    public function test_auth_non_2fa(): void
    {
        $service = $this->with_cassette('auth_non_2fa', fn () => $this->service(), true, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            'POST https://idmsa.apple.com/appleauth/auth/signin/complete?isRememberMeEnabled=true',
            'POST https://idmsa.apple.com/appleauth/auth/repair/complete',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
        ]);
        $this->assertFalse($service->requires_2fa());
        $this->assertFalse($service->requires_2sa());
    }

    public function test_successful_auth(): void
    {
        // the session and cookies of a previous run: only the token is validated
        foreach (glob(__DIR__.'/cookie/*') as $file) {
            copy($file, $this->cookie_dir.'/'.basename($file));
        }
        $service = $this->with_cassette('successful_auth', fn () => $this->service(), true, [
            'POST https://setup.icloud.com/setup/ws/1/validate',
        ]);
        $this->assertFalse($service->requires_2fa());
        $this->assertFalse($service->requires_2sa());
        $this->assertSame('12345678901', $service->params['dsid']);
    }

    public function test_failed_auth(): void
    {
        try {
            $this->with_cassette('failed_auth', fn () => $this->service(), false, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            'POST https://idmsa.apple.com/appleauth/auth/signin/complete?isRememberMeEnabled=true',
            ]);
            $this->fail('PyiCloudFailedLoginException expected');
        } catch (PyiCloudFailedLoginException $e) {
            // `str()` of the exception is what Python prints
            $this->assertSame('(\'Invalid email/password combination.\', PyiCloudAPIResponseException(\'{"serviceErrors": [{"code":"-20101","message":"Enter the email or phone number and password for your Apple Account.","suppressDismissal":false}]} (401)\'))', $e->getMessage());
            $this->assertStringContainsString('Invalid email/password combination.', $e->getMessage());
        }
    }

    public function test_failed_auth_503(): void
    {
        try {
            $this->with_cassette('failed_auth_503', fn () => $this->service(), false, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            ]);
            $this->fail('PyiCloudServiceUnavailableException expected');
        } catch (PyiCloudServiceUnavailableException $e) {
            $this->assertSame('Apple iCloud is temporary refusing to serve icloudpd', $e->getMessage());
        }
    }

    /** The two answers of the cassette are served one after the other (the `--watch-with-interval` run) */
    public function test_failed_auth_503_twice(): void
    {
        Vcr::use_cassette('failed_auth_503', function ($cassette) {
            for ($i = 0; $i < 2; ++$i) {
                try {
                    $this->service();
                    $this->fail('PyiCloudServiceUnavailableException expected');
                } catch (PyiCloudServiceUnavailableException) {
                }
            }
            $this->assertTrue($cassette->all_played);
            // and a third try is not in the cassette
            $this->expectException(\Tests\Vcr\CannotOverwriteExistingCassetteException::class);
            $this->service();
        });
    }

    /** the authentication that is commented out in `authenticate()` (srp, then raw password, then the token) */
    public function test_fallback_raw_password(): void
    {
        $result = $this->with_cassette('fallback_raw_password', function () {
            $service = $this->service(null);
            try {
                $service->_authenticate_srp('password1');
                $this->fail('PyiCloudFailedLoginException expected');
            } catch (PyiCloudFailedLoginException $e) {
                $first = $e->getMessage();
            }
            $service->_authenticate_raw_password('password1');
            $service->_authenticate_with_token();

            return [$first, $service->data['dsInfo']['dsid']];
        }, true, [
            'POST https://idmsa.apple.com/appleauth/auth/signin/init',
            'POST https://idmsa.apple.com/appleauth/auth/signin/complete?isRememberMeEnabled=true',
            'POST https://idmsa.apple.com/appleauth/auth/signin?isRememberMeEnabled=true',
            'POST https://setup.icloud.com/setup/ws/1/accountLogin',
        ]);
        $this->assertSame(['(\'Invalid email/password combination.\', PyiCloudAPIResponseException(\'{"serviceErrors": [{"code":"-20101","message":"Enter the email or phone number and password for your Apple Account.","suppressDismissal":false}]} (401)\'))', '12345678901'], $result);
    }

    public function test_a_request_missing_from_the_cassette_fails(): void
    {
        $this->expectException(\Tests\Vcr\CannotOverwriteExistingCassetteException::class);
        Vcr::use_cassette('successful_auth', fn () => $this->service());
    }
}
