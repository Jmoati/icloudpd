<?php

namespace PyicloudIpd\Sms;

/** A request to build (``_InternalRequest``, the ``Request`` protocol) */
final class InternalRequest
{
    /**
     * @param array<string, string> $headers
     * @param ?array<string, mixed> $json
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $data = null,
        public ?array $json = null,
    ) {
    }
}
