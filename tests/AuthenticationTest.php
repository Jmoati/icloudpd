<?php

namespace Tests;

use Foundation\Core;
use Foundation\Http\ConnectionError;
use Foundation\Http\Timeout;
use Foundation\Http\TimeoutError;
use Foundation\Os;
use Foundation\Patch;
use Icloudpd\Authentication;
use Icloudpd\Base;
use Icloudpd\Logger;
use Icloudpd\MfaProvider\MFAProvider;
use Icloudpd\Status\StatusExchange;
use PyicloudIpd\Exceptions\PyiCloudFailedLoginException;
use PyicloudIpd\Sms;

class AuthenticationTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;
    private string $vcr_path;
    private string $data_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
        $this->vcr_path = Os::path_join($this->root_path, 'vcr_cassettes');
        $this->data_path = Os::path_join($this->root_path, 'data');
    }

    public function test_failed_auth(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $context = null;
        Vcr::use_cassette(Helpers::cassette_path(Os::path_join($this->vcr_path, 'failed_auth.yml')), function () use ($cookie_dir, &$context) {
            try {
                Authentication::authenticator(
                    Logger::setup_logger(),
                    'com',
                    ['test' => [Core::constant('dummy'), Base::dummy_password_writter(...)]],
                    MFAProvider::CONSOLE,
                    new StatusExchange(),
                    'bad_username',
                    fn() => null,
                    null,
                    $cookie_dir,
                    'EC5646DE-9423-11E8-BF21-14109FE0B321',
                );
            } catch (PyiCloudFailedLoginException $e) {
                $context = $e;
            }
        });

        $this->assertInstanceOf(PyiCloudFailedLoginException::class, $context);

        // self.assertIn(
        //     "Failed to login with srp, falling back to old raw password authentication.",
        //     result.output,
        // )
        $this->assertTrue(str_contains($context->getMessage(), 'Invalid email/password combination.'));
    }

    public function test_fallback_raw_password(): void
    {
        $this->markTestSkipped('No longer support fallback to raw');

        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'fallback_raw_password.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--cookie-directory',
                $cookie_dir,
                '--auth-only',
            ],
        );
        $this->assertStringContainsString(
            'Failed to login with srp, falling back to old raw password authentication.',
            $result->output,
        );
        $this->assertStringContainsString('Authentication completed successfully', $result->output);
        $this->assertSame(0, $result->exit_code, 'exit code');
    }

    public function test_successful_token_validation(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        Helpers::recreate_path($base_dir);

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'successful_auth.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--cookie-directory',
                $cookie_dir,
                '--auth-only',
            ],
        );
        $this->assertStringContainsString('Authentication completed successfully', $result->output);
        $this->assertSame(0, $result->exit_code, 'exit code');
    }

    public function test_password_prompt_2sa(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, '2sa_flow_valid_code.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--cookie-directory',
                $cookie_dir,
                '--auth-only',
            ],
            "0\n654321\n",
        );
        $this->assertStringContainsString('Authenticating...', $result->output);
        $this->assertStringContainsString(
            'Two-step authentication is required',
            $result->output,
        );
        $this->assertStringContainsString('  0: SMS to *******03', $result->output);
        $this->assertStringContainsString('Please choose an option: [0]: 0', $result->output);
        $this->assertStringContainsString('Please enter two-step authentication code: 654321', $result->output);
        $this->assertStringContainsString(
            "Great, you're all set up. The script can now be run without "
            .'user interaction until 2SA expires.',
            $result->output,
        );
        $this->assertSame(0, $result->exit_code, 'exit code');
    }

    public function test_password_prompt_2fa(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, '2fa_flow_valid_code.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--cookie-directory',
                $cookie_dir,
                '--auth-only',
            ],
            "654321\n",
        );
        $this->assertStringContainsString('Authenticating...', $result->output);
        $this->assertStringContainsString(
            'Two-factor authentication is required',
            $result->output,
        );
        $this->assertStringContainsString('  a: (***) ***-**81', $result->output);
        $this->assertStringContainsString(
            'Please enter two-factor authentication code or device index (a) to send SMS with a code: 654321',
            $result->output,
        );
        $this->assertStringContainsString(
            "Great, you're all set up. The script can now be run without "
            .'user interaction until 2FA expires.',
            $result->output,
        );
        $this->assertStringNotContainsString('Failed to parse response with JSON mimetype', $result->output);
        $this->assertSame(0, $result->exit_code, 'exit code');
    }

    public function test_parse_trusted_phone_numbers_payload_valid(): void
    {
        $html_path = Os::path_join($this->data_path, 'parse_trusted_phone_numbers_payload_valid.html');
        $html = file_get_contents($html_path);
        $expected = new _TrustedDevice(id: 1, obfuscated_number: '(***) ***-**81');
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(1, count($result), 'number of numbers parsed');
        $this->assertEquals($expected, $result[0], 'parsed number');
    }

    public function test_parse_trusted_phone_numbers_payload_minimal(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"twoSV":{"phoneNumberVerification":{"trustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","obfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","id":1}]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $expected = new _TrustedDevice(id: 1, obfuscated_number: '(***) ***-**81');
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(1, count($result), 'number of numbers parsed');
        $this->assertEquals($expected, $result[0], 'parsed number');
    }

    public function test_parse_trusted_phone_numbers_payload_bridge_initiate_fallback(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"twoSV":{"bridgeInitiateData":{"phoneNumberVerification":{"trustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","obfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","id":1}]}},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $expected = new _TrustedDevice(id: 1, obfuscated_number: '(***) ***-**81');
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(1, count($result), 'number of numbers parsed');
        $this->assertEquals($expected, $result[0], 'parsed number');
    }

    public function test_parse_trusted_phone_numbers_payload_missing_node0(): void
    {
        $html = '<script type="application/json" class="boot_args">{"MISSINGdirect":{"twoSV":{"phoneNumberVerification":{"trustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","obfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","id":1}]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(0, count($result), 'number of numbers parsed');
    }

    public function test_parse_trusted_phone_numbers_payload_missing_node1(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"MISSINGtwoSV":{"phoneNumberVerification":{"trustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","obfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","id":1}]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(0, count($result), 'number of numbers parsed');
    }

    public function test_parse_trusted_phone_numbers_payload_missing_node2(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"twoSV":{"MISSINGphoneNumberVerification":{"trustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","obfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","id":1}]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(0, count($result), 'number of numbers parsed');
    }

    public function test_parse_trusted_phone_numbers_payload_missing_node3(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"twoSV":{"phoneNumberVerification":{"MISSINGtrustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","obfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","id":1}]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(0, count($result), 'number of numbers parsed');
    }

    public function test_parse_trusted_phone_numbers_payload_empty_list(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"twoSV":{"phoneNumberVerification":{"trustedPhoneNumbers":[]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(0, count($result), 'number of numbers parsed');
    }

    public function test_parse_trusted_phone_numbers_payload_invalid_missing_id(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"twoSV":{"phoneNumberVerification":{"trustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","obfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","MISSINGid":1}]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(0, count($result), 'number of numbers parsed');
    }

    public function test_parse_trusted_phone_numbers_payload_invalid_missing_number(): void
    {
        $html = '<script type="application/json" class="boot_args">{"direct":{"twoSV":{"phoneNumberVerification":{"trustedPhoneNumbers":[{"numberWithDialCode":"+1 (•••) •••-••81","pushMode":"sms","MISSINGobfuscatedNumber":"(•••) •••-••81","lastTwoDigits":"81","id":1}]},"authInitialRoute":"auth/verify/phone"}}}</script>';
        $result = Sms::parse_trusted_phone_numbers_payload($html);
        $this->assertSame(0, count($result), 'number of numbers parsed');
    }

    public function test_non_2fa(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Vcr::use_cassette(Helpers::cassette_path(Os::path_join($this->vcr_path, 'auth_non_2fa.yml')), function ($cass) use ($cookie_dir) {
            // To re-record this HTTP request,
            // delete ./tests/vcr_cassettes/auth_requires_2fa.yml,
            // put your actual credentials in here, run the test,
            // and then replace with dummy credentials.
            Authentication::authenticator(
                Logger::setup_logger(),
                'com',
                ['test' => [Core::constant('dummy'), Base::dummy_password_writter(...)]],
                MFAProvider::CONSOLE,
                new StatusExchange(),
                'jdoe@gmail.com',
                fn() => null,
                null,
                $cookie_dir,
                'EC5646DE-9423-11E8-BF21-14109FE0B321',
            );

            $this->assertTrue($cass->all_played);
        });
    }

    public function test_failed_auth_503(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'failed_auth_503.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--cookie-directory',
                $cookie_dir,
                '--auth-only',
            ],
        );
        $this->assertStringNotContainsString(
            'Failed to login with srp, falling back to old raw password authentication.',
            $result->output,
        );
        $this->assertStringContainsString('Apple iCloud is temporary refusing to serve icloudpd', $result->output);
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_failed_auth_503_watch(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'failed_auth_503.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--directory',
                $base_dir,
                '--cookie-directory',
                $cookie_dir,
                '--watch-with-interval',
                '1',
            ],
        );
        $this->assertStringNotContainsString(
            'Failed to login with srp, falling back to old raw password authentication.',
            $result->output,
        );
        $this->assertSame(
            2,
            substr_count($result->output, 'Apple iCloud is temporary refusing to serve icloudpd'),
        );
        $this->assertSame(2, substr_count($result->output, 'Waiting for 1 sec...'));
        // self.assertTrue("Can't overwrite existing cassette" in str(context.exception))
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_connection_error(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $pa_request_call_count = 0;
        $mock_raise_response_error = function (...$args) use (&$pa_request_call_count) {
            $pa_request_call_count++;
            throw new ConnectionError('Simulated Connection Error');
        };

        Patch::set('Foundation\Http\Session::request', $mock_raise_response_error);
        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'failed_auth_503.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--directory',
                $base_dir,
                '--cookie-directory',
                $cookie_dir,
                // "--watch-with-interval",
                // "1",
            ],
        );
        $this->assertSame(1, $pa_request_call_count);
        $this->assertStringContainsString(
            'Authenticating...',
            $result->output,
        );
        $this->assertStringContainsString(
            'Cannot connect to Apple iCloud service',
            $result->output,
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_timeout_error(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $pa_request_call_count = 0;
        $mock_raise_response_error = function (...$args) use (&$pa_request_call_count) {
            $pa_request_call_count++;
            throw new TimeoutError('Simulated TimeoutError');
        };

        Patch::set('Foundation\Http\Session::request', $mock_raise_response_error);
        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'failed_auth_503.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--directory',
                $base_dir,
                '--cookie-directory',
                $cookie_dir,
                // "--watch-with-interval",
                // "1",
            ],
        );
        $this->assertSame(1, $pa_request_call_count);
        $this->assertStringContainsString(
            'Authenticating...',
            $result->output,
        );
        $this->assertStringContainsString(
            'Cannot connect to Apple iCloud service',
            $result->output,
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_timeout(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $pa_request_call_count = 0;
        $mock_raise_response_error = function (...$args) use (&$pa_request_call_count) {
            $pa_request_call_count++;
            throw new Timeout('Simulated Timeout');
        };

        Patch::set('Foundation\Http\Session::request', $mock_raise_response_error);
        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'failed_auth_503.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--no-progress-bar',
                '--directory',
                $base_dir,
                '--cookie-directory',
                $cookie_dir,
                // "--watch-with-interval",
                // "1",
            ],
        );
        $this->assertSame(1, $pa_request_call_count);
        $this->assertStringContainsString(
            'Authenticating...',
            $result->output,
        );
        $this->assertStringContainsString(
            'Cannot connect to Apple iCloud service',
            $result->output,
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
    }
}

final class _TrustedDevice
{
    public function __construct(
        public int $id,
        public string $obfuscated_number,
    ) {
    }
}
