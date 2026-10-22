<?php

namespace PyicloudIpd\Sms;

final class AuthenticatedSession
{
    public function __construct(
        public string $client_id,
        public string $scnt,
        public string $session_id,
    ) {
    }
}
