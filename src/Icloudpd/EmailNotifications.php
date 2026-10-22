<?php

namespace Icloudpd;

use Foundation\Clock;
use Foundation\Logging\Logger;
use Foundation\Py;
use Foundation\Smtp;

/**
 * Send an email notification when 2SA is expired
 */
final class EmailNotifications
{
    /** Send an email notification when 2SA is expired */
    public static function send_2sa_notification(
        Logger $logger,
        string $username,
        ?string $smtp_email,
        ?string $smtp_password,
        string $smtp_host,
        int $smtp_port,
        bool $smtp_no_tls,
        ?string $to_addr,
        ?string $from_addr = null,
    ): void {
        $to_addr = $to_addr !== null ? $to_addr : $smtp_email;
        $from_addr = $from_addr !== null
            ? $from_addr
            : (Py::truthy($smtp_email) ? "iCloud Photos Downloader <{$smtp_email}>" : $to_addr);
        $logger->info("Sending 'two-step expired' notification via email...");
        $smtp = Smtp::SMTP($smtp_host, $smtp_port);
        $smtp->set_debuglevel(0);
        // leaving explicit call of connect to not break unit tests, even though it is
        // called implicitly via constructor parameters
        $smtp->connect($smtp_host, $smtp_port);
        if (!$smtp_no_tls) {
            $smtp->starttls();
        }

        if ($smtp_email !== null && $smtp_password !== null) {
            $smtp->login($smtp_email, $smtp_password);
        }

        $subj = 'icloud_photos_downloader: Two step authentication has expired';
        $date = Py::strftime(Clock::now(), '%d/%m/%Y %H:%M');

        $message_text = "Hello,

{$username}'s two-step authentication has expired for the icloud_photos_downloader script.
Please log in to your server and run the script manually to update two-step authentication.";

        $msg = "From: {$from_addr}\n"."To: {$to_addr}\nSubject: {$subj}\nDate: {$date}\n\n{$message_text}";

        $smtp->sendmail($from_addr, $to_addr, $msg);
        $smtp->quit();
    }
}
