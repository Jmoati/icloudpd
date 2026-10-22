<?php

namespace Foundation\Smtp;

/**
 * `smtplib.SMTPResponseException`: the server answered with an unexpected code (`smtp_code`, `smtp_error`).
 *
 * ponytail: also stands for SMTPConnectError, SMTPHeloError, SMTPAuthenticationError, SMTPSenderRefused,
 * SMTPRecipientsRefused and SMTPDataError, which upstream never catches; the message tells them apart.
 */
class SMTPResponseException extends SMTPException
{
    public function __construct(public int $smtp_code, public string $smtp_error, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "($smtp_code, ".var_export($smtp_error, true).')');
    }
}
