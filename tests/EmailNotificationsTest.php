<?php

namespace Tests;

use Foundation\Clock;
use Foundation\Logging;
use Foundation\Os;
use Foundation\Logging\Logger;
use Foundation\Logging\StreamHandler;
use Foundation\Patch;
use Foundation\Smtp;
use Icloudpd\EmailNotifications;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * `Smtp::SMTP` stands for `smtplib.SMTP` and `freeze_time("2018-01-01")` is a patch of `Clock::now`.
 * `test_2sa_required_notification_script` (`subprocess.call`) is about the notification script, not about email:
 * it is the job of the tests of `Icloudpd\Base`.
 */
class EmailNotificationsTest extends TestCase
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

    /** @return array{string, string, string} [base_dir, cookie_dir, data_dir], recreated empty */
    private function recreate_dirs(string $test_name): array
    {
        $base_dir = Os::path_join($this->fixtures_path, $test_name);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');

        foreach ([$base_dir, $cookie_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        return [$base_dir, $cookie_dir, $data_dir];
    }

    /** `Date: 01/01/2018 00:00` */
    private function freeze_time(): void
    {
        Patch::set('Foundation\Clock::now', fn (?\DateTimeZone $tz = null) => new \DateTimeImmutable('2018-01-01', $tz ?? Clock::get_localzone()));
    }

    /** @return array{Logger, resource} */
    private function logger(): array
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new Logger('email-notifications-test', Logging::DEBUG);
        $logger->addHandler(new StreamHandler($stream));

        return [$logger, $stream];
    }

    /** `with patch("smtplib.SMTP") as smtp`: `smtp()` is the one instance every call gets */
    private function patch_smtp(): MockObject
    {
        $smtp_instance = $this->createMock(Smtp::class);
        Patch::set('Foundation\Smtp::SMTP', fn (string $host = '', int $port = 0) => $smtp_instance);

        return $smtp_instance;
    }

    public function test_2sa_required_email_notification(): void
    {
        $this->freeze_time();
        [$base_dir, $cookie_dir, $data_dir] = $this->recreate_dirs(__FUNCTION__);
        $smtp_instance = $this->patch_smtp();
        $smtp_instance->expects($this->once())->method('connect');
        $smtp_instance->expects($this->once())->method('starttls');
        $smtp_instance->expects($this->once())->method('login')->with('jdoe+smtp@gmail.com', 'password1');
        $smtp_instance->expects($this->once())->method('sendmail')->with(
            'iCloud Photos Downloader <jdoe+smtp@gmail.com>',
            'jdoe+notifications@gmail.com',
            "From: iCloud Photos Downloader <jdoe+smtp@gmail.com>\n"
            ."To: jdoe+notifications@gmail.com\n"
            ."Subject: icloud_photos_downloader: Two step authentication has expired\n"
            ."Date: 01/01/2018 00:00\n\nHello,\n\n"
            ."jdoe@gmail.com's two-step authentication has expired for the icloud_photos_downloader script.\n"
            .'Please log in to your server and run the script manually to update two-step '
            .'authentication.',
        );

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'auth_requires_2fa.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--smtp-username',
                'jdoe+smtp@gmail.com',
                '--smtp-password',
                'password1',
                '--notification-email',
                'jdoe+notifications@gmail.com',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_2sa_notification_without_smtp_login_and_tls(): void
    {
        $this->freeze_time();
        [$base_dir, $cookie_dir, $data_dir] = $this->recreate_dirs(__FUNCTION__);
        $smtp_instance = $this->patch_smtp();
        $smtp_instance->expects($this->once())->method('connect');
        $smtp_instance->expects($this->never())->method('starttls');
        $smtp_instance->expects($this->never())->method('login');
        $smtp_instance->expects($this->once())->method('sendmail')->with(
            'jdoe+notifications@gmail.com',
            'jdoe+notifications@gmail.com',
            "From: jdoe+notifications@gmail.com\n"
            ."To: jdoe+notifications@gmail.com\n"
            ."Subject: icloud_photos_downloader: Two step authentication has expired\n"
            ."Date: 01/01/2018 00:00\n\nHello,\n\n"
            ."jdoe@gmail.com's two-step authentication has expired for the icloud_photos_downloader script.\n"
            .'Please log in to your server and run the script manually to update two-step '
            .'authentication.',
        );

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'auth_requires_2fa.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--smtp-no-tls',
                '--notification-email',
                'jdoe+notifications@gmail.com',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    public function test_2sa_required_email_notification_from(): void
    {
        $this->freeze_time();
        [$base_dir, $cookie_dir, $data_dir] = $this->recreate_dirs(__FUNCTION__);
        $smtp_instance = $this->patch_smtp();
        $smtp_instance->expects($this->once())->method('connect');
        $smtp_instance->expects($this->once())->method('starttls');
        $smtp_instance->expects($this->once())->method('login')->with('jdoe+smtp@gmail.com', 'password1');
        $smtp_instance->expects($this->once())->method('sendmail')->with(
            'JD <jdoe+notifications+from@gmail.com>',
            'JD <jdoe+notifications@gmail.com>',
            "From: JD <jdoe+notifications+from@gmail.com>\n"
            ."To: JD <jdoe+notifications@gmail.com>\n"
            ."Subject: icloud_photos_downloader: Two step authentication has expired\n"
            ."Date: 01/01/2018 00:00\n\nHello,\n\n"
            ."jdoe@gmail.com's two-step authentication has expired for the icloud_photos_downloader script.\n"
            .'Please log in to your server and run the script manually to update two-step '
            .'authentication.',
        );

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'auth_requires_2fa.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--smtp-username',
                'jdoe+smtp@gmail.com',
                '--smtp-password',
                'password1',
                '--notification-email',
                'JD <jdoe+notifications@gmail.com>',
                '--notification-email-from',
                'JD <jdoe+notifications+from@gmail.com>',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
    }

    /** the message of upstream's tests, written out once more for the tests that call `send_2sa_notification` straight away */
    private static function message(string $from, string $to): string
    {
        return "From: $from\n"
            ."To: $to\n"
            ."Subject: icloud_photos_downloader: Two step authentication has expired\n"
            ."Date: 01/01/2018 00:00\n\nHello,\n\n"
            ."jdoe@gmail.com's two-step authentication has expired for the icloud_photos_downloader script.\n"
            .'Please log in to your server and run the script manually to update two-step '
            .'authentication.';
    }

    /** the three scenarios of upstream's tests: [smtp_email, smtp_password, smtp_no_tls, to_addr, from_addr, sendmail from, sendmail to] */
    public static function scenarios(): array
    {
        return [
            'test_2sa_required_email_notification' => [
                'jdoe+smtp@gmail.com', 'password1', false, 'jdoe+notifications@gmail.com', null,
                'iCloud Photos Downloader <jdoe+smtp@gmail.com>', 'jdoe+notifications@gmail.com',
            ],
            'test_2sa_notification_without_smtp_login_and_tls' => [
                null, null, true, 'jdoe+notifications@gmail.com', null,
                'jdoe+notifications@gmail.com', 'jdoe+notifications@gmail.com',
            ],
            'test_2sa_required_email_notification_from' => [
                'jdoe+smtp@gmail.com', 'password1', false, 'JD <jdoe+notifications@gmail.com>', 'JD <jdoe+notifications+from@gmail.com>',
                'JD <jdoe+notifications+from@gmail.com>', 'JD <jdoe+notifications@gmail.com>',
            ],
            // not in upstream: no SMTP user and no recipient is not reachable from the CLI, the user is the recipient
            'smtp_username_only' => [
                'jdoe+smtp@gmail.com', 'password1', false, null, null,
                'iCloud Photos Downloader <jdoe+smtp@gmail.com>', 'jdoe+smtp@gmail.com',
            ],
        ];
    }

    /**
     * `send_2sa_notification` called straight away (what the CLI does with these options): the calls to
     * `smtplib.SMTP` (host and port, `connect`, `starttls`, `login`, `sendmail` with the exact message, `quit`)
     * and the log line.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_send_2sa_notification(
        ?string $smtp_email,
        ?string $smtp_password,
        bool $smtp_no_tls,
        ?string $to_addr,
        ?string $from_addr,
        string $sendmail_from,
        string $sendmail_to,
    ): void {
        $this->freeze_time();
        [$logger, $stream] = $this->logger();
        $smtp_instance = $this->createMock(Smtp::class);
        Patch::set('Foundation\Smtp::SMTP', function (string $host = '', int $port = 0) use ($smtp_instance) {
            $this->assertSame('smtp.gmail.com', $host);
            $this->assertSame(587, $port);

            return $smtp_instance;
        });
        $smtp_instance->expects($this->once())->method('set_debuglevel')->with(0);
        $smtp_instance->expects($this->once())->method('connect')->with('smtp.gmail.com', 587);
        $smtp_instance->expects($smtp_no_tls ? $this->never() : $this->once())->method('starttls');
        if ($smtp_email !== null && $smtp_password !== null) {
            $smtp_instance->expects($this->once())->method('login')->with($smtp_email, $smtp_password);
        } else {
            $smtp_instance->expects($this->never())->method('login');
        }
        $smtp_instance->expects($this->once())->method('sendmail')->with($sendmail_from, $sendmail_to, self::message($sendmail_from, $sendmail_to));
        $smtp_instance->expects($this->once())->method('quit');

        EmailNotifications::send_2sa_notification($logger, 'jdoe@gmail.com', $smtp_email, $smtp_password, 'smtp.gmail.com', 587, $smtp_no_tls, $to_addr, $from_addr);

        rewind($stream);
        $this->assertSame("Sending 'two-step expired' notification via email...\n", stream_get_contents($stream));
    }

    /**
     * Not in upstream: the real `Smtp` client against a local server (tests/data/fake_smtp_server.php: STARTTLS with a
     * self-signed certificate, AUTH CRAM-MD5 PLAIN LOGIN). The expected lines are what upstream's
     * `send_2sa_notification` (smtplib) sends to the same server, `ehlo` host name aside.
     */
    private function transcript_with_fake_server(array $options): string
    {
        $this->freeze_time();
        [$logger] = $this->logger();
        $transcript = tempnam(sys_get_temp_dir(), 'smtp-transcript-');
        $process = proc_open([PHP_BINARY, __DIR__.'/data/fake_smtp_server.php', $transcript], [1 => ['pipe', 'w']], $pipes);
        try {
            stream_set_timeout($pipes[1], 20);
            $port = (int) fgets($pipes[1]);
            $this->assertGreaterThan(0, $port, 'the fake SMTP server did not start');
            [$smtp_email, $smtp_password, $smtp_no_tls] = $options;

            EmailNotifications::send_2sa_notification(
                $logger,
                'jdoe@gmail.com',
                $smtp_email,
                $smtp_password,
                '127.0.0.1',
                $port,
                $smtp_no_tls,
                'jdoe+notifications@gmail.com',
            );
        } finally {
            fclose($pipes[1]);
            proc_close($process);
        }
        $lines = file_get_contents($transcript);
        unlink($transcript);

        return preg_replace('/ehlo [^\\\\]*/', 'ehlo HOST', $lines);
    }

    public function test_2sa_notification_wire_with_starttls_and_login(): void
    {
        $this->assertSame(
            <<<'TRANSCRIPT'
            [conn2] "ehlo HOST\r\n"
            [conn2] "STARTTLS\r\n"
            [conn2] "ehlo HOST\r\n"
            [conn2] "AUTH CRAM-MD5\r\n"
            [conn2] "amRvZStzbXRwQGdtYWlsLmNvbSA4ZGYwMTBmNWI1YzI0MzgxNmRiMGEzMDg1NGM3OGU0Yw==\r\n"
            [conn2] "mail from:<jdoe+smtp@gmail.com> size=382\r\n"
            [conn2] "rcpt to:<jdoe+notifications@gmail.com>\r\n"
            [conn2] "data\r\n"
            [conn2] "From: iCloud Photos Downloader <jdoe+smtp@gmail.com>\r\n"
            [conn2] "To: jdoe+notifications@gmail.com\r\n"
            [conn2] "Subject: icloud_photos_downloader: Two step authentication has expired\r\n"
            [conn2] "Date: 01\/01\/2018 00:00\r\n"
            [conn2] "\r\n"
            [conn2] "Hello,\r\n"
            [conn2] "\r\n"
            [conn2] "jdoe@gmail.com's two-step authentication has expired for the icloud_photos_downloader script.\r\n"
            [conn2] "Please log in to your server and run the script manually to update two-step authentication.\r\n"
            [conn2] ".\r\n"
            [conn2] "quit\r\n"

            TRANSCRIPT,
            $this->transcript_with_fake_server(['jdoe+smtp@gmail.com', 'password1', false])
        );
    }

    public function test_2sa_notification_wire_without_smtp_login_and_tls(): void
    {
        $this->assertSame(
            <<<'TRANSCRIPT'
            [conn2] "ehlo HOST\r\n"
            [conn2] "mail from:<jdoe+notifications@gmail.com> size=364\r\n"
            [conn2] "rcpt to:<jdoe+notifications@gmail.com>\r\n"
            [conn2] "data\r\n"
            [conn2] "From: jdoe+notifications@gmail.com\r\n"
            [conn2] "To: jdoe+notifications@gmail.com\r\n"
            [conn2] "Subject: icloud_photos_downloader: Two step authentication has expired\r\n"
            [conn2] "Date: 01\/01\/2018 00:00\r\n"
            [conn2] "\r\n"
            [conn2] "Hello,\r\n"
            [conn2] "\r\n"
            [conn2] "jdoe@gmail.com's two-step authentication has expired for the icloud_photos_downloader script.\r\n"
            [conn2] "Please log in to your server and run the script manually to update two-step authentication.\r\n"
            [conn2] ".\r\n"
            [conn2] "quit\r\n"

            TRANSCRIPT,
            $this->transcript_with_fake_server([null, null, true])
        );
    }
}
