<?php

namespace Icloudpd\Config;

use Icloudpd\LogLevel\LogLevel;
use Icloudpd\MfaProvider\MFAProvider;
use Icloudpd\PasswordProvider\PasswordProvider;

/** `@dataclass(kw_only=True)`: build it with named arguments */
final class GlobalConfig
{
    /**
     * @param list<PasswordProvider> $password_providers
     */
    public function __construct(
        public bool $help,
        public bool $version,
        public bool $use_os_locale,
        public bool $only_print_filenames,
        public LogLevel $log_level,
        public bool $no_progress_bar,
        public int $threads_num,
        public string $domain,
        public ?int $watch_with_interval,
        public array $password_providers,
        public MFAProvider $mfa_provider,
    ) {
    }
}
