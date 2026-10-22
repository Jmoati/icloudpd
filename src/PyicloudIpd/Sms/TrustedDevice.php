<?php

namespace PyicloudIpd\Sms;

/** A trusted phone number (``_InternalTrustedDevice``, the ``TrustedDevice`` protocol): value equality like a NamedTuple. */
final class TrustedDevice
{
    public function __construct(
        public mixed $id,
        public string $obfuscated_number,
    ) {
    }
}
