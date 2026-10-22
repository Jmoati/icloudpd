<?php

namespace Icloudpd\XmpSidecar;

/**
 * `XMPMetadata` (a `NamedTuple`).
 *
 * `GPSTimeStamp` comes from a plist, so Python's datetime is naive: the wall-clock fields of the UTC
 * `DateTimeImmutable` are the value and there is no offset to print. `CreateDate` is aware.
 */
final class XMPMetadata
{
    /**
     * @param list<string>|null $Keywords
     */
    public function __construct(
        public string $XMPToolkit,
        public ?string $Title,
        public ?string $Description,
        public ?int $Orientation,
        public ?string $Make,
        public ?string $DigitalSourceType,
        public ?array $Keywords,
        public int|float|null $GPSAltitude,
        public int|float|null $GPSLatitude,
        public int|float|null $GPSLongitude,
        public int|float|null $GPSSpeed,
        public ?\DateTimeImmutable $GPSTimeStamp,
        public ?\DateTimeImmutable $CreateDate,
        public ?int $Rating,
    ) {
    }
}
