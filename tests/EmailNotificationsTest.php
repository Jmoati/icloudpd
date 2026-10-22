<?php

namespace Tests;

use Foundation\Clock;
use Foundation\Logging;
use Foundation\Logging\Logger;
use Foundation\Logging\StreamHandler;
use Foundation\Patch;
use Foundation\Smtp;
use Icloudpd\EmailNotifications;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The upstream tests run the whole CLI against a cassette and patch `smtplib.SMTP`: here the notification is
 * sent straight away (`EmailNotifications::send_2sa_notification` is what the CLI calls with these options) and
 * `Smtp::SMTP` stands for `smtplib.SMTP`. `freeze_time("2018-01-01")` is a patch of `Clock::now`.
 */
class EmailNotificationsTest extends TestCase
{
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
        Patch::set('Foundation\Smtp::SMTP', function (string $host = '', int $port = 0) use ($smtp_instance) {
            $this->assertSame('smtp.gmail.com', $host);
            $this->assertSame(587, $port);

            return $smtp_instance;
        });

        return $smtp_instance;
    }

    public function test_2sa_required_email_notification(): void
    {
        $this->freeze_time();
        [$logger, $stream] = $this->logger();
        $smtp_instance = $this->patch_smtp();
        $smtp_instance->expects($this->once())->method('connect')->with('smtp.gmail.com', 587);
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
        $smtp_instance->expects($this->once())->method('quit');

        EmailNotifications::send_2sa_notification(
            $logger,
            'jdoe@gmail.com',
            'jdoe+smtp@gmail.com',
            'password1',
            'smtp.gmail.com',
            587,
            false,
            'jdoe+notifications@gmail.com',
        );

        rewind($stream);
        $this->assertSame("Sending 'two-step expired' notification via email...\n", stream_get_contents($stream));
    }

    public function test_2sa_notification_without_smtp_login_and_tls(): void
    {
        $this->freeze_time();
        [$logger] = $this->logger();
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

        EmailNotifications::send_2sa_notification(
            $logger,
            'jdoe@gmail.com',
            null,
            null,
            'smtp.gmail.com',
            587,
            true,
            'jdoe+notifications@gmail.com',
        );
    }

    public function test_2sa_required_email_notification_from(): void
    {
        $this->freeze_time();
        [$logger] = $this->logger();
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

        EmailNotifications::send_2sa_notification(
            $logger,
            'jdoe@gmail.com',
            'jdoe+smtp@gmail.com',
            'password1',
            'smtp.gmail.com',
            587,
            false,
            'JD <jdoe+notifications@gmail.com>',
            'JD <jdoe+notifications+from@gmail.com>',
        );
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
