<?php

namespace Icloudpd;

use Foundation\Argparse\ArgumentError;
use Foundation\Argparse\ArgumentParser;
use Foundation\Argparse\ArgumentTypeError;
use Foundation\Argparse\Namespace_;
use Foundation\Clock;
use Foundation\Core;
use Foundation\Foundation;
use Foundation\Py;
use Foundation\StringUtils;
use Icloudpd\Config\GlobalConfig;
use Icloudpd\Config\UserConfig;
use Icloudpd\LogLevel\LogLevel;
use Icloudpd\MfaProvider\MFAProvider;
use Icloudpd\PasswordProvider\PasswordProvider;
use PyicloudIpd\FileMatch\FileMatchPolicy;
use PyicloudIpd\LivePhotoMovFilenamePolicy\LivePhotoMovFilenamePolicy;
use PyicloudIpd\RawPolicy\RawTreatmentPolicy;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;

final class Cli
{
    /** Map user-friendly CLI strings to RawTreatmentPolicy enum values. */
    public static function map_align_raw_to_enum(string $align_raw_str): RawTreatmentPolicy
    {
        $mapping = [
            'as-is' => RawTreatmentPolicy::AS_IS,
            'original' => RawTreatmentPolicy::AS_ORIGINAL,
            'alternative' => RawTreatmentPolicy::AS_ALTERNATIVE,
        ];
        return $mapping[$align_raw_str];
    }

    public static function add_options_for_user(ArgumentParser $parser): ArgumentParser
    {
        $cloned = clone $parser;
        $cloned->add_argument(
            '-d',
            '--directory',
            metavar: 'DIRECTORY',
            help: 'Local directory to use for downloads',
        );
        $cloned->add_argument(
            '--auth-only',
            action: 'store_true',
            help: 'Create/update cookie and session tokens only.',
        );
        $cloned->add_argument(
            '--adp-web-access',
            action: 'store_true',
            help: 'Request iCloud web access consent for accounts with Advanced Data Protection (ADP). '
            . 'Requires approving a notification on an Apple device the first time.',
        );
        $cloned->add_argument(
            '--cookie-directory',
            help: 'Directory to store cookies for authentication. Default: %(default)s',
            default: '~/.pyicloud',
        );
        $cloned->add_argument(
            '--size',
            help: 'Image size to download. `medium` and `thumb` will always be added as suffixes to filenames, `adjusted` and `alternative` only if conflicting, `original` never. If `adjusted` or `alternative` is specified and missing, then `original` is used. Default: %(default)s',
            choices: ['original', 'medium', 'thumb', 'adjusted', 'alternative'],
            default: null,
            action: 'append',
            dest: 'sizes',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '--live-photo-size',
            help: 'Live Photo video size to download. Default: %(default)s',
            choices: ['original', 'medium', 'thumb'],
            default: 'original',
            action: 'store',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '--recent',
            help: 'Number of recent photos to download (default: download all photos)',
            type: 'int',
            default: null,
        );
        $cloned->add_argument(
            '--until-found',
            help: 'Download the most recently added photos until we find X number of '
                . 'previously downloaded consecutive photos (default: download all photos)',
            type: 'int',
            default: null,
        );
        $cloned->add_argument(
            '-a',
            '--album',
            help: 'Album(s) to download, or the whole collection if not specified',
            action: 'append',
            default: [],
            dest: 'albums',
        );
        $cloned->add_argument(
            '-l',
            '--list-albums',
            help: 'List the available albums',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--library',
            help: 'Library to download. Default: %(default)s',
            default: 'PrimarySync',
        );
        $cloned->add_argument(
            '--list-libraries',
            help: 'List the available libraries',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--skip-videos',
            help: "Don't download any videos (default: download all photos and videos)",
            action: 'store_true',
        );
        $cloned->add_argument(
            '--skip-live-photos',
            help: "Don't download any live photos (default: download live photos)",
            action: 'store_true',
        );
        $cloned->add_argument(
            '--xmp-sidecar',
            help: "Export additional data as XMP sidecar files (default: don't export)",
            action: 'store_true',
        );
        $cloned->add_argument(
            '--force-size',
            help: 'Only download the requested size (`adjusted` and `alternative` will not be forced). Default: download original if size is not available',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--auto-delete',
            help: 'Scan the "Recently Deleted" folder and delete any files found there. '
                . '(If you restore the photo in iCloud, it will be downloaded again.)',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--folder-structure',
            help: 'Folder structure. If set to `none`, all photos will be placed into the download directory. Default: %(default)s',
            default: '{:%Y/%m/%d}',
            type: self::validate_folder_structure(...),
        );
        $cloned->add_argument(
            '--set-exif-datetime',
            help: "Write the DateTimeOriginal EXIF tag from file creation date, if it doesn't exist.",
            action: 'store_true',
        );

        $cloned->add_argument(
            '--smtp-username',
            help: 'SMTP username for sending email notifications when two-step authentication expires.',
            default: null,
        );
        $cloned->add_argument(
            '--smtp-password',
            help: 'SMTP password for sending email notifications when two-step authentication expires.',
            default: null,
        );
        $cloned->add_argument(
            '--smtp-host',
            help: 'SMTP server host for notifications',
            default: 'smtp.gmail.com',
        );
        $cloned->add_argument(
            '--smtp-port',
            help: 'SMTP server port. Default: %(default)i',
            type: 'int',
            default: 587,
        );
        $cloned->add_argument(
            '--smtp-no-tls',
            help: 'Disable TLS for SMTP (TLS is required for Gmail)',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--notification-email',
            help: 'Email address where you would like to receive email notifications. '
                . 'Default: SMTP username',
            default: null,
            type: 'str',
        );
        $cloned->add_argument(
            '--notification-email-from',
            help: 'Email address from which you would like to receive email notifications. '
                . 'Default: SMTP username or notification-email',
            default: null,
            type: 'str',
        );
        $cloned->add_argument(
            '--notification-script',
            type: self::path(...),
            help: 'Path to external script to run when two-factor authentication expires.',
            default: null,
        );
        $deprecated_kwargs = [];
        // sys.version_info >= (3, 13): the reference icloudpd runs on Python 3.13, which warns
        // "option '--delete-after-download' is deprecated" on use
        $deprecated_kwargs['deprecated'] = true;
        $cloned->add_argument(
            '--delete-after-download',
            ...$deprecated_kwargs,
            help: 'Delete the photo/video after downloading it.'
                . ' The deleted items will appear in "Recently Deleted".'
                . ' Therefore, should not be combined with --auto-delete option.',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--keep-icloud-recent-days',
            help: 'Keep photos newer than this many days in iCloud. Delete the rest. '
                . 'If set to 0, all photos will be deleted from iCloud.',
            type: 'int',
            default: null,
        );
        $cloned->add_argument(
            '--dry-run',
            help: 'Do not modify the local system or iCloud',
            action: 'store_true',
            default: false,
        );
        $cloned->add_argument(
            '--keep-unicode-in-filenames',
            help: 'Keep Unicode characters in filenames, or remove all non-ASCII characters',
            action: 'store_true',
            default: false,
        );
        $cloned->add_argument(
            '--live-photo-mov-filename-policy',
            help: 'How to produce filenames for the video portion of live photos: `suffix` will add _HEVC suffix and `original` will keep the filename as is. Default: %(default)s',
            choices: ['suffix', 'original'],
            default: 'suffix',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '--align-raw',
            help: 'For photo assets with RAW and JPEG, always treat RAW in the specified size: `original` (RAW+JPEG), `alternative` (JPEG+RAW), or unchanged (as-is). This matters when choosing sizes to download. Default: %(default)s',
            choices: ['as-is', 'original', 'alternative'],
            default: 'as-is',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '--file-match-policy',
            help: 'Policy to identify existing files and de-duplicate. `name-size-dedup-with-suffix` appends file size to de-duplicate. `name-id7` adds asset ID from iCloud to all filenames and does not de-duplicate. Default: %(default)s',
            choices: ['name-size-dedup-with-suffix', 'name-id7'],
            default: 'name-size-dedup-with-suffix',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '--skip-created-before',
            help: 'Do not process assets created before the specified timestamp in ISO format (2025-01-02) or interval backwards from now (20d = 20 days ago)',
            default: null,
            type: self::parse_timestamp_or_timedelta_tz_error(...),
        );
        $cloned->add_argument(
            '--skip-created-after',
            help: 'Do not process assets created after the specified timestamp in ISO format (2025-01-02) or interval backwards from now (20d = 20 days ago)',
            default: null,
            type: self::parse_timestamp_or_timedelta_tz_error(...),
        );
        $cloned->add_argument(
            '--skip-photos',
            help: "Don't download any photos (default: download all photos and videos)",
            action: 'store_true',
        );
        return $cloned;
    }

    public static function add_user_option(ArgumentParser $parser): ArgumentParser
    {
        $cloned = clone $parser;
        $cloned->add_argument(
            '-u',
            '--username',
            help: 'Apple ID email address. Starts a new configuration group.',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '-p',
            '--password',
            help: 'iCloud password for the account if `--password-provider` specifies `parameter`',
            default: null,
            type: 'str',
        );
        return $cloned;
    }

    public static function parse_mfa_provider(string $provider): MFAProvider
    {
        $provider_map = [
            'console' => MFAProvider::CONSOLE,
            'webui' => MFAProvider::WEBUI,
        ];

        $normalized_provider = StringUtils::lower($provider);
        if (isset($provider_map[$normalized_provider])) {
            return $provider_map[$normalized_provider];
        } else {
            throw new \ValueError("Only `console` and `webui` are supported, but `{$provider}` was provided");
        }
    }

    public static function add_global_options(ArgumentParser $parser): ArgumentParser
    {
        $cloned = clone $parser;
        $group = $cloned->add_mutually_exclusive_group();
        $group->add_argument('--help', '-h', action: 'store_true', help: 'Show this information');
        $group->add_argument(
            '--version',
            help: 'Show the version, commit hash, and timestamp',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--use-os-locale',
            help: 'Use the locale of the host OS to format dates',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--only-print-filenames',
            help: 'Only print the filenames of all files that will be downloaded '
                . '(not including files that are already downloaded). '
                . '(Does not download or delete any files.)',
            action: 'store_true',
        );
        $cloned->add_argument(
            '--log-level',
            help: 'Log level. Default: %(default)s',
            choices: ['debug', 'info', 'error'],
            default: 'debug',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '--no-progress-bar',
            help: 'Disable the one-line progress bar and print log messages on separate lines '
                . '(progress bar is disabled by default if there is no TTY attached)',
            action: 'store_true',
        );
        $deprecated_kwargs = [];
        // sys.version_info >= (3, 13): the reference icloudpd runs on Python 3.13, which warns
        // "option '--threads-num' is deprecated" on use
        $deprecated_kwargs['deprecated'] = true;
        $cloned->add_argument(
            '--threads-num',
            ...$deprecated_kwargs,
            help: 'Number of CPU threads - deprecated & always 1. To be removed in a future version',
            type: 'int',
            default: 1,
        );
        $cloned->add_argument(
            '--domain',
            help: "Which iCloud root domain to use. Use 'cn' for mainland China. Default: %(default)s",
            choices: ['com', 'cn'],
            default: 'com',
        );
        $cloned->add_argument(
            '--watch-with-interval',
            help: 'Run downloading in an infinite cycle, waiting the specified seconds between runs',
            type: 'int',
            default: null,
        );
        $cloned->add_argument(
            '--password-provider',
            dest: 'password_providers',
            help: 'Specify password providers to check in the given order. Default: [`parameter`, `keyring`, `console`]',
            choices: ['console', 'keyring', 'parameter', 'webui'],
            default: null,
            action: 'append',
            type: StringUtils::lower(...),
        );
        $cloned->add_argument(
            '--mfa-provider',
            help: 'Specify where to get the MFA code from',
            choices: ['console', 'webui'],
            default: 'console',
            type: StringUtils::lower(...),
        );
        return $cloned;
    }

    public static function log_level(string $inp): LogLevel
    {
        if ($inp == 'debug') {
            return LogLevel::DEBUG;
        } elseif ($inp == 'info') {
            return LogLevel::INFO;
        } elseif ($inp == 'error') {
            return LogLevel::ERROR;
        } else {
            throw new ArgumentTypeError("Unsupported log level {$inp}");
        }
    }

    /** Convert ISO dates to datetime with tz and interval in days to time interval. Raise exception in case of error. */
    public static function parse_timestamp_or_timedelta_tz_error(?string $formatted): \DateTimeImmutable|\DateInterval|null
    {
        if ($formatted === null) {
            return null;
        }
        $result = StringHelpers::parse_timestamp_or_timedelta($formatted);
        if ($result === null) {
            throw new ArgumentTypeError('Not an ISO timestamp or time interval in days');
        }
        // `ensure_tzinfo(get_localzone(), result)` for a naive datetime: nothing to do, a PHP datetime
        // always has a time zone and parse_timestamp() has put a naive one in the local zone (see PORTING.md)
        return $result;
    }

    /**
     * `pathlib.Path(string)`, as the string it prints as: no empty or "." components, no trailing slash.
     * ponytail: `--notification-script` is only ever turned back into a string (`str(path)`), so no Path class.
     */
    public static function path(string $string): string
    {
        $root = '';
        if (str_starts_with($string, '/')) {
            // POSIX keeps exactly two leading slashes
            $root = str_starts_with($string, '//') && !str_starts_with($string, '///') ? '//' : '/';
        }
        $parts = array_filter(explode('/', $string), fn($part) => $part !== '' && $part !== '.');
        $path = $root . implode('/', $parts);
        return $path === '' ? '.' : $path;
    }

    public static function format_help_for_parser_(ArgumentParser $parser): string
    {
        return $parser->format_help();
    }

    /** `itertools.dropwhile` (ponytail: itertools is not ported, this is its only user) */
    private static function dropwhile(callable $predicate, iterable $iterable): \Generator
    {
        $dropping = true;
        foreach ($iterable as $x) {
            if ($dropping && $predicate($x)) {
                continue;
            }
            $dropping = false;
            yield $x;
        }
    }

    public static function format_help(): string
    {
        // create fake parser and return it's help
        $pre_options_predicate = Core::compose(fn($x) => !$x, Core::partial_1_1(fn($a, $b) => $a === $b, 'options:'));
        $skip_to_options_header = Core::partial_1_1(self::dropwhile(...), $pre_options_predicate);
        $skip_to_options = Core::compose(Core::partial_1_1(Core::skip(...), 1), $skip_to_options_header);

        $help_in_lines = Core::compose(StringHelpers::splitlines(...), self::format_help_for_parser_(...));

        $extract_option_lines = Core::compose($skip_to_options, $help_in_lines);

        $dummy_parser = new ArgumentParser(exit_on_error: false, add_help: false, allow_abbrev: false);

        $global_help = Core::compose($extract_option_lines, self::add_global_options(...))($dummy_parser);

        $default_help = Core::compose($extract_option_lines, self::add_options_for_user(...))($dummy_parser);

        $user_help = Core::compose($extract_option_lines, self::add_user_option(...))($dummy_parser);

        $all_help = Core::chain_from_iterable([
            ['usage: icloudpd [GLOBAL] [COMMON] [<USER> [COMMON] <USER> [COMMON] ...]', ''],
            ['GLOBAL options. Applied for all user settings.'],
            $global_help,
            [
                '',
                'COMMON options. If specified before the first username, then used as defaults for settings for all users.',
            ],
            $default_help,
            ['', 'USER options. Can be specified for setting user configuration only.'],
            $user_help,
        ]);

        return implode("\n", iterator_to_array($all_help, false));
    }

    public static function map_to_config(Namespace_ $user_ns): UserConfig
    {
        return new UserConfig(
            username: $user_ns->username,
            password: $user_ns->password,
            directory: $user_ns->directory,
            auth_only: $user_ns->auth_only,
            adp_web_access: $user_ns->adp_web_access,
            cookie_directory: $user_ns->cookie_directory,
            sizes: iterator_to_array(
                Core::map_(AssetVersionSize::from(...), Foundation::unique_sequence($user_ns->sizes ?: ['original'])),
                false,
            ),
            live_photo_size: LivePhotoVersionSize::from($user_ns->live_photo_size),
            recent: $user_ns->recent,
            until_found: $user_ns->until_found,
            albums: $user_ns->albums,
            list_albums: $user_ns->list_albums,
            library: $user_ns->library,
            list_libraries: $user_ns->list_libraries,
            skip_videos: $user_ns->skip_videos,
            skip_live_photos: $user_ns->skip_live_photos,
            xmp_sidecar: $user_ns->xmp_sidecar,
            force_size: $user_ns->force_size,
            auto_delete: $user_ns->auto_delete,
            folder_structure: $user_ns->folder_structure,
            set_exif_datetime: $user_ns->set_exif_datetime,
            smtp_username: $user_ns->smtp_username,
            smtp_password: $user_ns->smtp_password,
            smtp_host: $user_ns->smtp_host,
            smtp_port: $user_ns->smtp_port,
            smtp_no_tls: $user_ns->smtp_no_tls,
            notification_email: $user_ns->notification_email,
            notification_email_from: $user_ns->notification_email_from,
            notification_script: $user_ns->notification_script,
            delete_after_download: $user_ns->delete_after_download,
            keep_icloud_recent_days: $user_ns->keep_icloud_recent_days,
            dry_run: $user_ns->dry_run,
            keep_unicode_in_filenames: $user_ns->keep_unicode_in_filenames,
            live_photo_mov_filename_policy: LivePhotoMovFilenamePolicy::from($user_ns->live_photo_mov_filename_policy),
            align_raw: self::map_align_raw_to_enum($user_ns->align_raw),
            file_match_policy: FileMatchPolicy::from($user_ns->file_match_policy),
            skip_created_before: $user_ns->skip_created_before,
            skip_created_after: $user_ns->skip_created_after,
            skip_photos: $user_ns->skip_photos,
        );
    }

    /**
     * @param list<string> $args
     * @return array{GlobalConfig, list<UserConfig>}
     */
    public static function parse(array $args): array
    {
        // default --help
        if (count($args) == 0) {
            $args = ['--help'];
        }

        // Extract global options first from anywhere in the args using parse_known_args
        $global_parser = self::add_global_options(
            new ArgumentParser(exit_on_error: false, add_help: false, allow_abbrev: false)
        );
        [$global_ns, $non_global_args] = $global_parser->parse_known_args($args);

        // Now split the remaining non-global args by username boundaries
        $splitted_args = Foundation::split_with_alternatives(['-u', '--username'], $non_global_args);
        $default_args = $splitted_args[0];

        $default_parser = self::add_options_for_user(
            new ArgumentParser(exit_on_error: false, add_help: false, allow_abbrev: false)
        );

        $default_ns = $default_parser->parse_args($default_args);

        $user_parser = self::add_user_option(self::add_options_for_user(
            new ArgumentParser(exit_on_error: false, add_help: false, allow_abbrev: false)
        ));
        $user_nses = array_map(
            // copy.deepcopy(default_ns)
            fn($user_args) => self::map_to_config($user_parser->parse_args($user_args, clone $default_ns)),
            array_slice($splitted_args, 1),
        );

        return [
            new GlobalConfig(
                help: $global_ns->help,
                version: $global_ns->version,
                use_os_locale: $global_ns->use_os_locale,
                only_print_filenames: $global_ns->only_print_filenames,
                log_level: self::log_level($global_ns->log_level),
                no_progress_bar: $global_ns->no_progress_bar,
                threads_num: $global_ns->threads_num,
                domain: $global_ns->domain,
                watch_with_interval: $global_ns->watch_with_interval,
                password_providers: iterator_to_array(
                    Core::map_(
                        PasswordProvider::from(...),
                        Foundation::unique_sequence(
                            $global_ns->password_providers ?: ['parameter', 'keyring', 'console']
                        ),
                    ),
                    false,
                ),
                mfa_provider: MFAProvider::from($global_ns->mfa_provider),
            ),
            $user_nses,
        ];
    }

    /**
     * The entry point of `bin/icloudpd`: parses `$_SERVER['argv']` (`sys.argv`) and returns the exit code.
     */
    public static function cli(): int
    {
        try {
            [$global_ns, $user_nses] = self::parse(array_slice($_SERVER['argv'] ?? [], 1));
        } catch (ArgumentError $error) {
            echo $error->getMessage(), "\n";
            return 2;
        }
        if ($global_ns->use_os_locale) {
            setlocale(LC_ALL, '');
            // Python's own number formatting is not locale-dependent, PHP's printf() is
            setlocale(LC_NUMERIC, 'C');
        }
        if ($global_ns->help) {
            echo self::format_help(), "\n";
            return 0;
        } elseif ($global_ns->version) {
            echo Foundation::version_info_formatted(), "\n";
            return 0;
        } else {
            // check param compatibility
            if (array_filter($user_nses, fn($user_ns) => $user_ns->skip_videos && $user_ns->skip_photos)) {
                echo 'Only one of --skip-videos and --skip-photos can be used at a time for each configuration', "\n";
                return 2;
            }

            // check required directory param only if not list albums
            elseif (array_filter(
                $user_nses,
                fn($user_ns) => !$user_ns->list_albums
                    && !$user_ns->list_libraries
                    && !Py::truthy($user_ns->directory)
                    && !$user_ns->auth_only,
            )) {
                echo '--auth-only, --directory, --list-libraries, or --list-albums are required for each configuration', "\n";
                return 2;
            } elseif (array_filter(
                $user_nses,
                fn($user_ns) => $user_ns->auto_delete && $user_ns->delete_after_download,
            )) {
                echo '--auto-delete and --delete-after-download are mutually exclusive per configuration', "\n";
                return 2;
            } elseif (array_filter(
                $user_nses,
                fn($user_ns) => Py::truthy($user_ns->keep_icloud_recent_days) && $user_ns->delete_after_download,
            )) {
                echo '--keep-icloud-recent-days and --delete-after-download should not be used together in one configuration', "\n";
                return 2;
            } elseif (Py::truthy($global_ns->watch_with_interval) && (
                array_filter(
                    $user_nses,
                    fn($user_ns) => $user_ns->list_albums || $user_ns->auth_only || $user_ns->list_libraries,
                )
                || $global_ns->only_print_filenames
            )) {
                echo '--watch-with-interval is not compatible with --list-albums, --list-libraries, --only-print-filenames, and --auth-only', "\n";
                return 2;
            } else {
                return Base::run_with_configs($global_ns, $user_nses);
            }
        }
    }

    public static function validate_folder_structure(string $folder_structure): string
    {
        if (StringUtils::lower($folder_structure) == 'none') {
            return 'none';
        } else {
            try {
                Py::format($folder_structure, Clock::now());
                return $folder_structure;
            } catch (\Throwable) {
                throw new ArgumentTypeError(
                    "Format {$folder_structure} specified in --folder-structure is incorrect"
                );
            }
        }
    }
}
