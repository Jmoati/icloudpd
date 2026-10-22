<?php

namespace Tests;

use Foundation\Os;
use Foundation\Patch;

class TwoStepAuthTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;
    private string $vcr_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
        $this->vcr_path = Helpers::calc_vcr_dir($this->root_path);
    }

    public function test_2sa_flow_invalid_code(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Helpers::calc_cookie_dir($base_dir);

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, '2sa_flow_invalid_code.yml'),
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
            "0\n901431\n",
        );
        $this->assertStringContainsString(
            'Failed to verify two-step authentication code',
            $result->output,
        );

        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_2sa_flow_valid_code(): void
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

    public function test_2sa_flow_failed_send_code(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Patch::set('PyicloudIpd\Base\PyiCloudService::send_verification_code', fn(...$args) => false);
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
            "0\n",
        );
        $this->assertStringContainsString('Authenticating...', $result->output);
        $this->assertStringContainsString(
            'Two-step authentication is required',
            $result->output,
        );
        $this->assertStringContainsString('  0: SMS to *******03', $result->output);
        $this->assertStringContainsString('Please choose an option: [0]: 0', $result->output);
        $this->assertStringContainsString(
            'Failed to send two-step authentication code',
            $result->output,
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_2fa_flow_invalid_code(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, '2fa_flow_invalid_code.yml'),
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
            "901431\n",
        );
        $this->assertStringContainsString(
            'Failed to verify two-factor authentication code',
            $result->output,
        );

        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_2fa_flow_valid_code(): void
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
        $this->assertStringContainsString(
            'Please enter two-factor authentication code or device index (a) to send SMS with a code: 654321',
            $result->output,
        );
        $this->assertStringContainsString(
            "Great, you're all set up. The script can now be run without "
            .'user interaction until 2FA expires.',
            $result->output,
        );
        $this->assertSame(0, $result->exit_code, 'exit code');
    }

    public function test_2fa_flow_valid_code_zero_lead(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');

        foreach ([$base_dir, $cookie_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, '2fa_flow_valid_code_zero_lead.yml'),
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
            "054321\n",
        );
        $this->assertStringContainsString('Authenticating...', $result->output);
        $this->assertStringContainsString(
            'Two-factor authentication is required',
            $result->output,
        );
        $this->assertStringContainsString(
            'Please enter two-factor authentication code or device index (a) to send SMS with a code: 054321',
            $result->output,
        );
        $this->assertStringContainsString(
            "Great, you're all set up. The script can now be run without "
            .'user interaction until 2FA expires.',
            $result->output,
        );
        $this->assertSame(0, $result->exit_code, 'exit code');
    }
}
