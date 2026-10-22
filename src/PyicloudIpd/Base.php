<?php

namespace PyicloudIpd;

use Foundation\Logging;
use Foundation\Logging\Logger;

/** Module `pyicloud_ipd/base.py` (the classes are in `PyicloudIpd\Base\`). */
final class Base
{
    public const HEADER_DATA = [
        'X-Apple-ID-Account-Country' => 'account_country',
        'X-Apple-ID-Session-Id' => 'session_id',
        'X-Apple-Session-Token' => 'session_token',
        'X-Apple-TwoSV-Trust-Token' => 'trust_token',
        'X-Apple-TwoSV-Trust-Eligible' => 'trust_eligible',
        'X-Apple-I-Rscd' => 'apple_rscd',
        'X-Apple-I-Ercd' => 'apple_ercd',
        'scnt' => 'scnt',
    ];

    /** `LOGGER = logging.getLogger(__name__)` */
    public static function LOGGER(): Logger
    {
        PyicloudIpd::init();

        return Logging::moduleLogger('pyicloud_ipd.base');
    }

    /** @return array{Origin: string, Referer: string} */
    public static function origin_referer_headers(string $input): array
    {
        return ['Origin' => $input, 'Referer' => "$input/"];
    }

    /** `str(uuid.uuid4())`: a random UUID. */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** `str(uuid.uuid1()).lower()`: a time-based UUID (random node, as Python does without a MAC address). */
    public static function uuid1(): string
    {
        // 100-nanosecond intervals since 1582-10-15
        $timestamp = (int) (microtime(true) * 10_000_000) + 0x01B21DD213814000;
        $clock_seq = random_int(0, 0x3FFF);
        $node = random_int(0, 0xFFFFFFFFFFFF) | (1 << 40);

        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            $timestamp & 0xFFFFFFFF,
            ($timestamp >> 32) & 0xFFFF,
            (($timestamp >> 48) & 0x0FFF) | 0x1000,
            ($clock_seq & 0x3FFF) | 0x8000,
            $node,
        );
    }
}
