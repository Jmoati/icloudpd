<?php

namespace Foundation\Smtp;

use Foundation\OSError;

/**
 * `smtplib.SMTPException` (an OSError in Python).
 *
 * ponytail: also stands for SMTPServerDisconnected and SMTPNotSupportedError, which upstream never catches
 * apart from the base class; the message tells them apart.
 */
class SMTPException extends OSError
{
}
