<?php

namespace PyicloudIpd\Base;

use PyicloudIpd\Sms\AuthenticatedSession;

final class TrustedPhoneContextProvider
{
    public function __construct(
        public string $domain,
        public AuthenticatedSession $oauth_session,
    ) {
    }
}
