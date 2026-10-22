<?php

namespace Foundation;

/** `foundation.VersionInfo` (a `NamedTuple`) */
final class VersionInfo
{
    public function __construct(
        public string $version,
        public string $commit_sha,
        public int $commit_timestamp,
    ) {
    }
}
