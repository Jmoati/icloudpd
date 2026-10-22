<?php

namespace Icloudpd;

use Foundation\Clock;
use Foundation\Core;
use Foundation\Http\ChunkedEncodingError;
use Foundation\Http\ContentDecodingError;
use Foundation\Http\StreamConsumedError;
use Foundation\Http\Urllib;
use Foundation\Http\UnrewindableBodyError;
use Foundation\Logging;
use Foundation\Logging\Logger;
use Foundation\Os;
use Foundation\Patch;
use Foundation\Py;
use Foundation\StringUtils;
use Foundation\Time;
use Foundation\Tqdm;
use Icloudpd\Authentication\SystemExit;
use Icloudpd\Config\GlobalConfig;
use Icloudpd\Config\UserConfig;
use Icloudpd\Counter\Counter;
use Icloudpd\LogLevel\LogLevel;
use Icloudpd\MfaProvider\MFAProvider;
use Icloudpd\PasswordProvider\PasswordProvider;
use Icloudpd\Status\Status;
use Icloudpd\Status\StatusExchange;
use PyicloudIpd\AssetVersion;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudAPIResponseException;
use PyicloudIpd\Exceptions\PyiCloudConnectionErrorException;
use PyicloudIpd\Exceptions\PyiCloudFailedLoginException;
use PyicloudIpd\Exceptions\PyiCloudFailedMFAException;
use PyicloudIpd\Exceptions\PyiCloudServiceNotActivatedException;
use PyicloudIpd\Exceptions\PyiCloudServiceUnavailableException;
use PyicloudIpd\FileMatch\FileMatchPolicy;
use PyicloudIpd\ItemType\AssetItemType;
use PyicloudIpd\LivePhotoMovFilenamePolicy\LivePhotoMovFilenamePolicy;
use PyicloudIpd\RawPolicy\RawTreatmentPolicy;
use PyicloudIpd\Services\Photos\PhotoAlbum;
use PyicloudIpd\Services\Photos\PhotoAsset;
use PyicloudIpd\Services\Photos\PhotoLibrary;
use PyicloudIpd\Utils;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;
use WeakMap;

/**
 * Main script that uses Click to parse command-line arguments
 */
final class Base
{
    // ponytail: freeze_support() (fixing tqdm on macos) is a multiprocessing matter, nothing in PHP

    /**
     * Build filename cleaner based on unicode preference.
     *
     * Args:
     *     keep_unicode: If True, preserve Unicode characters. If False, remove non-ASCII characters.
     *
     * Returns:
     *     Function that processes filenames according to unicode preference.
     *     Note: Basic filesystem character cleaning (clean_filename) is always applied in calculate_filename.
     *
     * @return callable(string): string
     */
    public static function build_filename_cleaner(bool $keep_unicode): callable
    {
        if ($keep_unicode) {
            // Only basic cleaning is needed (already applied in calculate_filename)
            return Core::identity(...);
        } else {
            // Apply unicode removal in addition to basic cleaning
            return Paths::remove_unicode_chars(...);
        }
    }

    /**
     * Generate concatenator-style live photo filename, adding HEVC suffix for HEIC files
     */
    public static function lp_filename_concatinator(string $filename): string
    {
        [$name, $ext] = Os::path_splitext($filename);
        if (!$ext) {
            return $filename;
        }

        $is_heic = Core::compose(StringUtils::endswith(".heic"), StringUtils::lower(...))($ext);
        return $name . ($is_heic ? "_HEVC.MOV" : ".MOV");
    }

    /**
     * Generate original-style live photo filename by replacing extension with .MOV
     */
    public static function lp_filename_original(string $filename): string
    {
        $replace_with_mov = StringUtils::replace_extension(".MOV");
        return $replace_with_mov($filename);
    }

    /**
     * `str.rsplit(sep, maxsplit)` for a single-character separator
     *
     * @return string[]
     */
    private static function rsplit(string $string, string $sep, int $maxsplit): array
    {
        $parts = explode($sep, $string);
        if (count($parts) <= $maxsplit + 1) {
            return $parts;
        }
        $tail = array_splice($parts, -$maxsplit);
        return [implode($sep, $parts), ...$tail];
    }

    public static function ask_password_in_console(string $_user): ?string
    {
        return self::getpass("iCloud Password for {$_user}:");
    }

    /**
     * `getpass.getpass(prompt)`
     *
     * ponytail: no termios in PHP, `stty -echo` on a terminal; when stdin is not a terminal the line is
     * read as is (Python does the same after warning that it cannot control echo)
     */
    private static function getpass(string $prompt): string
    {
        fwrite(STDERR, $prompt);
        $tty = stream_isatty(STDIN);
        if ($tty) {
            shell_exec('stty -echo 2>/dev/null');
        }
        try {
            $line = fgets(STDIN);
        } finally {
            if ($tty) {
                shell_exec('stty echo 2>/dev/null');
                fwrite(STDERR, "\n");
            }
        }
        if ($line === false) {
            throw new \RuntimeException("EOF when reading a line"); // EOFError
        }
        return rtrim($line, "\r\n");
    }

    /**
     * Request two-factor authentication through Webui.
     */
    public static function get_password_from_webui(
        Logger $logger,
        StatusExchange $status_exchange,
        string $_user,
    ): ?string {
        // ponytail: the WebUI (Flask server in a thread feeding StatusExchange) is not ported,
        // nothing could ever supply the password
        throw new \RuntimeException("WebUI is not available in the PHP port");
    }

    public static function update_password_status_in_webui(StatusExchange $status_exchange, string $_u, string $_p): void
    {
        $status_exchange->replace_status(Status::CHECKING_PASSWORD, Status::NO_INPUT_NEEDED);
    }

    public static function update_auth_error_in_webui(StatusExchange $status_exchange, string $error): bool
    {
        return $status_exchange->set_error($error);
    }

    public static function dummy_password_writter(string $_u, string $_p): void
    {
    }

    /**
     * @return callable(string, string): void
     */
    public static function keyring_password_writter(Logger $logger): callable
    {
        return function (string $username, string $password) use ($logger): void {
            try {
                Utils::store_password_in_keyring($username, $password);
            } catch (\Throwable) {
                $logger->warning("Password was not saved to keyring");
            }
        };
    }

    /**
     * Converts ISO dates to datetime and interval in days to timeinterval using supplied name as part of raised exception in case of the error
     */
    public static function skip_created_generator(string $name, ?string $formatted): \DateTimeInterface|\DateInterval|null
    {
        if ($formatted === null) {
            return null;
        }
        $result = StringHelpers::parse_timestamp_or_timedelta($formatted);
        if ($result === null) {
            throw new \ValueError("{$name} parameter did not parse ISO timestamp or interval successfully");
        }
        if ($result instanceof \DateTimeInterface) {
            return self::ensure_tzinfo(Clock::get_localzone(), $result);
        }
        return $result;
    }

    public static function ensure_tzinfo(\DateTimeZone $tz, \DateTimeInterface $input): \DateTimeInterface
    {
        // ponytail: a datetime of the port is always timezone-aware (`StringHelpers::parse_timestamp`
        // reads a naive ISO timestamp in the local zone), so there is no naive value to convert
        return $input;
    }

    // Must import the constants object so that we can mock values in tests.

    public static function create_logger(GlobalConfig $config): Logger
    {
        Logging::basicConfig(
            format: "%(asctime)s %(levelname)-8s %(message)s",
            datefmt: "%Y-%m-%d %H:%M:%S",
            stream: Py::stdout(),
        );
        $logger = Logging::getLogger("icloudpd");
        if ($config->only_print_filenames) {
            $logger->disabled = true;
        } else {
            // Need to make sure disabled is reset to the correct value,
            // because the logger instance is shared between tests.
            $logger->disabled = false;
            if ($config->log_level === LogLevel::DEBUG) {
                $logger->setLevel(Logging::DEBUG);
            } elseif ($config->log_level === LogLevel::INFO) {
                $logger->setLevel(Logging::INFO);
            } elseif ($config->log_level === LogLevel::ERROR) {
                $logger->setLevel(Logging::ERROR);
            } else {
                // Developer's error - not an exhaustive match
                throw new \ValueError("Unsupported logging level {$config->log_level->name}");
            }
        }
        return $logger;
    }

    /**
     * Run the application with the new configuration system
     *
     * @param UserConfig[] $user_configs
     */
    public static function run_with_configs(GlobalConfig $global_config, array $user_configs): int
    {
        try {
            return self::_run_with_configs($global_config, $user_configs);
        } catch (SystemExit $exit) {
            // ponytail: `sys.exit(code)` (request_2sa) ends the Python process with that status; here
            // the run ends and the entry point gets the code as its return value
            return $exit->getCode();
        }
    }

    /**
     * @param UserConfig[] $user_configs
     */
    private static function _run_with_configs(GlobalConfig $global_config, array $user_configs): int
    {
        // Create shared logger
        $logger = self::create_logger($global_config);

        // Create shared status exchange for web server and progress tracking
        $shared_status_exchange = new StatusExchange();

        // Check if any user needs web server (webui for MFA or passwords)
        $needs_web_server = $global_config->mfa_provider === MFAProvider::WEBUI || array_any(
            $global_config->password_providers,
            fn($provider) => $provider === PasswordProvider::WEBUI,
        );

        // Start web server ONCE if needed, outside all loops
        if ($needs_web_server) {
            // ponytail: the WebUI (Flask server, `serve_app` in a daemon thread) is not ported;
            // `--mfa-provider webui` / `--password-provider webui` stop here instead of waiting forever
            throw new \RuntimeException("WebUI is not available in the PHP port");
        }

        // Check if we're in watch mode
        $watch_interval = $global_config->watch_with_interval;

        if (!$watch_interval) {
            // No watch mode - process each user once and exit
            return self::_process_all_users_once($global_config, $user_configs, $logger, $shared_status_exchange);
        } else {
            // Watch mode - infinite loop processing all users, then wait
            $skip_bar = !Py::truthy(Os::environ_get("FORCE_TQDM")) && (
                $global_config->only_print_filenames
                || $global_config->no_progress_bar
                || !stream_isatty(STDOUT)
            );

            while (true) {
                // Process all user configs in this iteration
                $result = self::_process_all_users_once(
                    $global_config, $user_configs, $logger, $shared_status_exchange
                );

                // If any critical operation (auth-only, list commands) succeeded, exit
                if ($result == 0) {
                    $first_user = $user_configs[0] ?? null;
                    if ($first_user && (
                        $first_user->auth_only || $first_user->list_albums || $first_user->list_libraries
                    )) {
                        return 0;
                    }
                }

                // Wait for the watch interval before next iteration
                // Clear current user during wait period to avoid misleading UI
                $shared_status_exchange->clear_current_user();
                $logger->info("Waiting for {$watch_interval} sec...");
                $interval = $watch_interval > 1 ? range(1, $watch_interval - 1) : [];
                $iterable = $skip_bar
                    ? $interval
                    : new Tqdm(
                        iterable: $interval,
                        desc: "Waiting...",
                        ascii: true,
                        leave: false,
                        dynamic_ncols: true,
                    );
                foreach ($iterable as $counter) {
                    // Update shared status exchange with wait progress
                    $shared_status_exchange->get_progress()->waiting = $watch_interval - $counter;
                    if ($shared_status_exchange->get_progress()->resume) {
                        $shared_status_exchange->get_progress()->reset();
                        break;
                    }
                    Time::sleep(1);
                }
            }
        }
    }

    /**
     * Process all user configs once (used by both single run and watch mode)
     *
     * @param UserConfig[] $user_configs
     */
    public static function _process_all_users_once(
        GlobalConfig $global_config,
        array $user_configs,
        Logger $logger,
        StatusExchange $shared_status_exchange,
    ): int {
        // Set global config and all user configs to status exchange once, before processing
        $shared_status_exchange->set_global_config($global_config);
        $shared_status_exchange->set_user_configs($user_configs);

        foreach ($user_configs as $user_config) {
            $result = Tqdm::logging_redirect_tqdm(function () use ($global_config, $user_config, $logger, $shared_status_exchange): ?int {
                // Use shared status exchange instead of creating new ones per user
                $status_exchange = $shared_status_exchange;

                // Set up password providers with proper function replacements
                /** @var WeakMap<PasswordProvider, array{0: callable(string): ?string, 1: callable(string, string): void}> $password_providers_dict */
                $password_providers_dict = new WeakMap();

                foreach ($global_config->password_providers as $provider) {
                    if ($provider === PasswordProvider::WEBUI) {
                        $password_providers_dict[$provider] = [
                            fn(string $_user) => self::get_password_from_webui($logger, $status_exchange, $_user),
                            fn(string $_u, string $_p) => self::update_password_status_in_webui($status_exchange, $_u, $_p),
                        ];
                    } elseif ($provider === PasswordProvider::CONSOLE) {
                        $password_providers_dict[$provider] = [
                            self::ask_password_in_console(...),
                            self::dummy_password_writter(...),
                        ];
                    } elseif ($provider === PasswordProvider::KEYRING) {
                        $password_providers_dict[$provider] = [
                            Utils::get_password_from_keyring(...),
                            self::keyring_password_writter($logger),
                        ];
                    } elseif ($provider === PasswordProvider::PARAMETER) {
                        $create_constant_password_provider = function (?string $password): callable {
                            return function (string $_username) use ($password): ?string {
                                return $password;
                            };
                        };

                        $password_providers_dict[$provider] = [
                            $create_constant_password_provider($user_config->password),
                            self::dummy_password_writter(...),
                        ];
                    }
                }

                // Only set current user - global config and user configs are already set
                $status_exchange->set_current_user($user_config->username);

                // Web server is now started once outside the user loop - no need to start it here

                // Set up filename processors directly since we don't have click context
                // filename_cleaner was removed from services and should be passed explicitly to functions that need it

                // Set up live photo filename generator directly
                $lp_filename_generator = $user_config->live_photo_mov_filename_policy === LivePhotoMovFilenamePolicy::ORIGINAL
                    ? self::lp_filename_original(...)
                    : self::lp_filename_concatinator(...);

                // Set up filename cleaner based on user preference
                $filename_cleaner = self::build_filename_cleaner($user_config->keep_unicode_in_filenames);

                // Create filename builder with pre-configured policy and cleaner
                $filename_builder = FilenamePolicies::create_filename_builder(
                    $user_config->file_match_policy, $filename_cleaner
                );

                // Set up function builders
                $passer = fn(PhotoAsset $photo) => self::where_builder(
                    $logger,
                    $user_config->skip_videos,
                    $user_config->skip_created_before,
                    $user_config->skip_created_after,
                    $user_config->skip_photos,
                    $filename_builder,
                    $photo,
                );

                $downloader = $user_config->directory !== null
                    ? fn(PyiCloudService $icloud, Counter $counter, PhotoAsset $photo) => self::download_builder(
                        $logger,
                        $user_config->folder_structure,
                        $user_config->directory,
                        $user_config->sizes,
                        $user_config->force_size,
                        $global_config->only_print_filenames,
                        $user_config->set_exif_datetime,
                        $user_config->skip_live_photos,
                        $user_config->live_photo_size,
                        $user_config->dry_run,
                        $user_config->file_match_policy,
                        $user_config->xmp_sidecar,
                        $lp_filename_generator,
                        $filename_builder,
                        $user_config->align_raw,
                        $icloud,
                        $counter,
                        $photo,
                    )
                    : fn($_s, $_c, $_p) => false;

                $notificator = fn() => self::notificator_builder(
                    $logger,
                    $user_config->username,
                    $user_config->smtp_username,
                    $user_config->smtp_password,
                    $user_config->smtp_host,
                    $user_config->smtp_port,
                    $user_config->smtp_no_tls,
                    $user_config->notification_email,
                    $user_config->notification_email_from,
                    Py::truthy($user_config->notification_script) ? (string) $user_config->notification_script : null,
                );

                // Use core_single_run since we've disabled watch at this level
                $logger->info("Processing user: {$user_config->username}");
                $result = self::core_single_run(
                    $logger,
                    $status_exchange,
                    $global_config,
                    $user_config,
                    $password_providers_dict,
                    $passer,
                    $downloader,
                    $notificator,
                    $lp_filename_generator,
                );

                // If any user config fails and we're not in watch mode, return the error code
                if ($result != 0) {
                    if (!$global_config->watch_with_interval) {
                        return $result;
                    } else {
                        // In watch mode, log error and continue with next user
                        $logger->error(
                            "Error processing user {$user_config->username}, continuing with next user..."
                        );
                    }
                }
                return null;
            });
            if ($result !== null) {
                return $result;
            }
        }

        return 0;
    }

    /**
     * `subprocess.call(args)`: runs the command (no shell) with the standard streams of the process and
     * returns its exit status (patchable: the upstream tests replace `subprocess.call`)
     *
     * @param list<string> $args
     */
    public static function subprocess_call(array $args): int
    {
        if ($p = Patch::get(__METHOD__)) return $p($args);
        $process = @proc_open($args, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if ($process === false) {
            throw new \Foundation\OSError("[Errno 2] No such file or directory: " . Py::repr($args[0]));
        }
        return proc_close($process);
    }

    public static function notificator_builder(
        Logger $logger,
        string $username,
        ?string $smtp_username,
        ?string $smtp_password,
        string $smtp_host,
        int $smtp_port,
        bool $smtp_no_tls,
        ?string $notification_email,
        ?string $notification_email_from,
        ?string $notification_script,
    ): void {
        try {
            if ($notification_script !== null) {
                $logger->debug("Executing notification script...");
                self::subprocess_call([$notification_script]);
            }
            if ($smtp_username !== null || $notification_email !== null) {
                EmailNotifications::send_2sa_notification(
                    $logger,
                    $username,
                    $smtp_username,
                    $smtp_password,
                    $smtp_host,
                    $smtp_port,
                    $smtp_no_tls,
                    $notification_email,
                    $notification_email_from,
                );
            }
        } catch (\Throwable $error) {
            $logger->error("Notification of the required MFA failed");
            $logger->debug($error->getMessage());
        }
    }

    /**
     * `@singledispatch offset_to_datetime`: a datetime is itself, a timedelta is now minus it
     */
    public static function offset_to_datetime(mixed $offset): \DateTimeInterface
    {
        if ($offset instanceof \DateTimeInterface) {
            return $offset;
        }
        if ($offset instanceof \DateInterval) {
            return Clock::now(Clock::get_localzone())->sub($offset);
        }
        throw new \BadMethodCallException('NotImplementedError');
    }

    /**
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function where_builder(
        Logger $logger,
        bool $skip_videos,
        \DateTimeInterface|\DateInterval|null $skip_created_before,
        \DateTimeInterface|\DateInterval|null $skip_created_after,
        bool $skip_photos,
        callable $filename_builder,
        PhotoAsset $photo,
    ): bool {
        if ($skip_videos && $photo->item_type() === AssetItemType::MOVIE) {
            $logger->debug(self::asset_type_skip_message(AssetItemType::IMAGE, $filename_builder, $photo));
            return false;
        }
        if ($skip_photos && $photo->item_type() === AssetItemType::IMAGE) {
            $logger->debug(self::asset_type_skip_message(AssetItemType::MOVIE, $filename_builder, $photo));
            return false;
        }

        if ($skip_created_before !== null) {
            $temp_created_before = self::offset_to_datetime($skip_created_before);
            if ($photo->created() < $temp_created_before) {
                $logger->debug(self::skip_created_before_message($temp_created_before, $photo, $filename_builder));
                return false;
            }
        }

        if ($skip_created_after !== null) {
            $temp_created_after = self::offset_to_datetime($skip_created_after);
            if ($photo->created() > $temp_created_after) {
                $logger->debug(self::skip_created_after_message($temp_created_after, $photo, $filename_builder));
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function skip_created_before_message(
        \DateTimeInterface $target_created_date,
        PhotoAsset $photo,
        callable $filename_builder,
    ): string {
        $filename = $filename_builder($photo);
        return "Skipping {$filename}, as it was created " . Py::str($photo->created()) . ", before " . Py::str($target_created_date) . ".";
    }

    /**
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function skip_created_after_message(
        \DateTimeInterface $target_created_date,
        PhotoAsset $photo,
        callable $filename_builder,
    ): string {
        $filename = $filename_builder($photo);
        return "Skipping {$filename}, as it was created " . Py::str($photo->created()) . ", after " . Py::str($target_created_date) . ".";
    }

    /**
     * Thin wrapper over the builtin `open` of this module (patched by the upstream tests).
     *
     * @return resource
     */
    public static function open(string $file, string $mode, string $encoding = "utf8")
    {
        if ($p = Patch::get(__METHOD__)) return $p($file, $mode, $encoding);
        $file_obj = @fopen($file, $mode);
        if ($file_obj === false) {
            throw new \Foundation\OSError("[Errno 2] Cannot open '{$file}'");
        }
        return $file_obj;
    }

    /**
     * `json.dump(obj, fp)` (patchable: the upstream tests replace `json.dump`)
     *
     * @param resource $fp
     */
    public static function json_dump(mixed $obj, $fp): void
    {
        if ($p = Patch::get(__METHOD__)) { $p($obj, $fp); return; }
        fwrite($fp, Py::json_dumps($obj));
    }

    /**
     * function for actually downloading the photos
     *
     * @param AssetVersionSize[] $primary_sizes
     * @param callable(string): string $lp_filename_generator
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function download_builder(
        Logger $logger,
        string $folder_structure,
        string $directory,
        array $primary_sizes,
        bool $force_size,
        bool $only_print_filenames,
        bool $set_exif_datetime,
        bool $skip_live_photos,
        LivePhotoVersionSize $live_photo_size,
        bool $dry_run,
        FileMatchPolicy $file_match_policy,
        bool $xmp_sidecar,
        callable $lp_filename_generator,
        callable $filename_builder,
        RawTreatmentPolicy $raw_policy,
        PyiCloudService $icloud,
        Counter $counter,
        PhotoAsset $photo,
    ): bool {
        // ponytail: no `except (ValueError, OSError)` around the conversion ("Could not convert photo created
        // date to local timezone"), setTimezone() cannot overflow like astimezone()
        $created_date = $photo->created()->setTimezone(Clock::get_localzone());

        if (StringUtils::lower($folder_structure) === "none") {
            $date_path = "";
        } else {
            try {
                $date_path = Py::format($folder_structure, $created_date);
            } catch (\ValueError) { // pragma: no cover
                // This error only seems to happen in Python 2
                $logger->error("Photo created date was not valid (%s)", $photo->created());
                // e.g. ValueError: year=5 is before 1900
                // (https://github.com/icloud-photos-downloader/icloud_photos_downloader/issues/122)
                // Just use the Unix epoch
                $created_date = (new \DateTimeImmutable('@0'))->setTimezone(Clock::get_localzone());
                $date_path = Py::format($folder_structure, $created_date);
            }
        }

        try {
            [$versions, $filename_overrides] = Utils::disambiguate_filenames(
                $photo->versions_with_raw_policy($raw_policy), $primary_sizes, $photo, $lp_filename_generator
            );
        } catch (\OutOfBoundsException $ex) { // KeyError
            echo "KeyError: " . Py::str($ex) . " attribute was not found in the photo fields.\n";
            $outfile = self::open(file: "icloudpd-photo-error.json", mode: "w", encoding: "utf8");
            try {
                self::json_dump(
                    [
                        "master_record" => $photo->_master_record,
                        "asset_record" => $photo->_asset_record,
                    ],
                    $outfile,
                );
            } finally {
                fclose($outfile);
            }
            echo "icloudpd has saved the photo record to: ./icloudpd-photo-error.json\n";
            echo "Please create a Gist with the contents of this file: https://gist.github.com\n";
            echo "Then create an issue on GitHub: "
                . "https://github.com/icloud-photos-downloader/icloud_photos_downloader/issues\n";
            echo "Include a link to the Gist in your issue, so that we can see what went wrong.\n\n";
            return false;
        }

        $download_dir = Os::path_normpath(Os::path_join($directory, $date_path));
        $success = false;

        foreach ($primary_sizes as $download_size) {
            if (!isset($versions[$download_size]) && $download_size !== AssetVersionSize::ORIGINAL) {
                if ($force_size) {
                    $error_filename = $filename_builder($photo);
                    $logger->error(
                        "%s size does not exist for %s. Skipping...",
                        $download_size->value,
                        $error_filename,
                    );
                    continue;
                }
                if (in_array(AssetVersionSize::ORIGINAL, $primary_sizes, true)) {
                    continue; // that should avoid double download for original
                }
                $download_size = AssetVersionSize::ORIGINAL;
            }

            $version = $versions[$download_size];
            $photo_filename = $filename_builder($photo);
            $filename = AssetVersion::calculate_version_filename(
                $photo_filename,
                $version,
                $download_size,
                $lp_filename_generator,
                $photo->item_type(),
                $filename_overrides[$download_size] ?? null,
            );

            $download_path = Paths::local_download_path($filename, $download_dir);

            $original_download_path = null;
            $file_exists = Os::path_isfile($download_path);
            if (!$file_exists && $download_size === AssetVersionSize::ORIGINAL) {
                // Deprecation - We used to download files like IMG_1234-original.jpg,
                // so we need to check for these.
                // Now we match the behavior of iCloud for Windows: IMG_1234.jpg
                $original_download_path = AssetVersion::add_suffix_to_filename("-original", $download_path);
                $file_exists = Os::path_isfile($original_download_path);
            }

            if ($file_exists) {
                if ($file_match_policy === FileMatchPolicy::NAME_SIZE_DEDUP_WITH_SUFFIX) {
                    // for later: this crashes if download-size medium is specified
                    $file_size = Os::stat($original_download_path ?? $download_path)["st_size"];
                    $photo_size = $version->size;
                    if ($file_size != $photo_size) {
                        $download_path = implode("-{$photo_size}.", self::rsplit($download_path, ".", 1));
                        $logger->debug("%s deduplicated", StringHelpers::truncate_middle($download_path, 96));
                        $file_exists = Os::path_isfile($download_path);
                    }
                }
                if ($file_exists) {
                    $counter->increment();
                    $logger->debug("%s already exists", StringHelpers::truncate_middle($download_path, 96));
                }
            }

            if (!$file_exists) {
                $counter->reset();
                if ($only_print_filenames) {
                    echo $download_path . "\n";
                } else {
                    $truncated_path = StringHelpers::truncate_middle($download_path, 96);
                    $logger->debug("Downloading %s...", $truncated_path);

                    $download_result = Download::download_media(
                        $logger,
                        $dry_run,
                        $icloud,
                        $photo,
                        $download_path,
                        $version,
                        $download_size,
                        $filename_builder,
                    );
                    $success = $download_result;

                    if ($download_result) {
                        $is_jpeg = Core::compose(StringUtils::endswith([".jpg", ".jpeg"]), StringUtils::lower(...));

                        if (
                            !$dry_run
                            && $set_exif_datetime
                            && $is_jpeg($filename)
                            && !ExifDatetime::get_photo_exif($logger, $download_path)
                        ) {
                            // %Y:%m:%d looks wrong, but it's the correct format
                            $date_str = Py::strftime($created_date, "%Y-%m-%d %H:%M:%S%z");
                            $logger->debug("Setting EXIF timestamp for %s: %s", $download_path, $date_str);
                            ExifDatetime::set_photo_exif(
                                $logger,
                                $download_path,
                                Py::strftime($created_date, "%Y:%m:%d %H:%M:%S"),
                            );
                        }
                        if (!$dry_run) {
                            Download::set_utime($download_path, $created_date);
                        }
                        $logger->info("Downloaded %s", $truncated_path);
                    }
                }
            }

            if ($xmp_sidecar) {
                XmpSidecar::generate_xmp_file($logger, $download_path, $photo->_asset_record, $dry_run);
            }
        }

        // Also download the live photo if present
        if (!$skip_live_photos) {
            $lp_size = $live_photo_size;
            $photo_versions_with_policy = $photo->versions_with_raw_policy($raw_policy);
            if (isset($photo_versions_with_policy[$lp_size])) {
                $version = $photo_versions_with_policy[$lp_size];
                $lp_photo_filename = $filename_builder($photo);
                $lp_filename = AssetVersion::calculate_version_filename(
                    $lp_photo_filename,
                    $version,
                    $lp_size,
                    $lp_filename_generator,
                    $photo->item_type(),
                );
                if ($live_photo_size !== LivePhotoVersionSize::ORIGINAL) {
                    // Add size to filename if not original
                    $lp_filename = AssetVersion::add_suffix_to_filename(
                        Utils::size_to_suffix($live_photo_size),
                        $lp_filename,
                    );
                }
                $lp_download_path = Os::path_join($download_dir, $lp_filename);

                $lp_file_exists = Os::path_isfile($lp_download_path);

                if ($only_print_filenames) {
                    if (!$lp_file_exists) {
                        echo $lp_download_path . "\n";
                    }
                    // Handle deduplication case for only_print_filenames
                    if (
                        $lp_file_exists
                        && $file_match_policy === FileMatchPolicy::NAME_SIZE_DEDUP_WITH_SUFFIX
                    ) {
                        $lp_file_size = Os::stat($lp_download_path)["st_size"];
                        $lp_photo_size = $version->size;
                        if ($lp_file_size != $lp_photo_size) {
                            $lp_download_path = implode("-{$lp_photo_size}.", self::rsplit($lp_download_path, ".", 1));
                            $logger->debug("%s deduplicated", StringHelpers::truncate_middle($lp_download_path, 96));
                            // Print the deduplicated filename but don't download
                            echo $lp_download_path . "\n";
                        }
                    }
                } else {
                    if ($lp_file_exists) {
                        if ($file_match_policy === FileMatchPolicy::NAME_SIZE_DEDUP_WITH_SUFFIX) {
                            $lp_file_size = Os::stat($lp_download_path)["st_size"];
                            $lp_photo_size = $version->size;
                            if ($lp_file_size != $lp_photo_size) {
                                $lp_download_path = implode("-{$lp_photo_size}.", self::rsplit($lp_download_path, ".", 1));
                                $logger->debug("%s deduplicated", StringHelpers::truncate_middle($lp_download_path, 96));
                                $lp_file_exists = Os::path_isfile($lp_download_path);
                            }
                        }
                        if ($lp_file_exists) {
                            $logger->debug("%s already exists", StringHelpers::truncate_middle($lp_download_path, 96));
                        }
                    }
                    if (!$lp_file_exists) {
                        $truncated_path = StringHelpers::truncate_middle($lp_download_path, 96);
                        $logger->debug("Downloading %s...", $truncated_path);
                        $download_result = Download::download_media(
                            $logger,
                            $dry_run,
                            $icloud,
                            $photo,
                            $lp_download_path,
                            $version,
                            $lp_size,
                            $filename_builder,
                        );
                        $success = $download_result && $success;
                        if ($download_result) {
                            $logger->info("Downloaded %s", $truncated_path);
                        }
                    }
                }
            }
        }
        return $success;
    }

    /**
     * Delete a photo from the iCloud account.
     *
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function delete_photo(
        Logger $logger,
        PhotoLibrary $library_object,
        PhotoAsset $photo,
        callable $filename_builder,
    ): void {
        if ($p = Patch::get(__METHOD__)) { $p($logger, $library_object, $photo, $filename_builder); return; }

        $clean_filename_local = $filename_builder($photo);
        $logger->debug("Deleting %s in iCloud...", $clean_filename_local);
        $url = "{$library_object->service_endpoint}/records/modify?"
            . Urllib::urlencode($library_object->params);
        $post_data = Py::json_dumps(
            [
                "atomic" => true,
                "desiredKeys" => ["isDeleted"],
                "operations" => [
                    [
                        "operationType" => "update",
                        "record" => [
                            "fields" => ["isDeleted" => ["value" => 1]],
                            "recordChangeTag" => $photo->_asset_record["recordChangeTag"],
                            "recordName" => $photo->_asset_record["recordName"],
                            "recordType" => "CPLAsset",
                        ],
                    ],
                ],
                "zoneID" => $library_object->zone_id,
            ]
        );
        $library_object->session->post($url, data: $post_data, headers: ["Content-type" => "application/json"]);
        $logger->info("Deleted %s in iCloud", $clean_filename_local);
    }

    /**
     * Dry run for deleting a photo from the iCloud
     *
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function delete_photo_dry_run(
        Logger $logger,
        PhotoLibrary $library_object,
        PhotoAsset $photo,
        callable $filename_builder,
    ): void {
        $filename = $filename_builder($photo);
        $logger->info(
            "[DRY RUN] Would delete %s in iCloud library %s",
            $filename,
            $library_object->zone_id["zoneName"],
        );
    }

    /**
     * @param callable(string): void $dumper
     * @param array[] $responses
     */
    public static function dump_responses(callable $dumper, array $responses): void
    {
        // dump captured responses
        foreach ($responses as $entry) {
            // compose(logger.debug, compose(json.dumps, response_to_har))(response)
            $dumper(Py::json_dumps($entry, indent: 2));
        }
    }

    /**
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function asset_type_skip_message(
        AssetItemType $desired_item_type,
        callable $filename_builder,
        PhotoAsset $photo,
    ): string {
        $photo_video_phrase = $desired_item_type === AssetItemType::IMAGE ? "photos" : "videos";
        $filename = $filename_builder($photo);
        return "Skipping {$filename}, only downloading {$photo_video_phrase}. (Item type was: " . ($photo->item_type()?->name ?? "None") . ")";
    }

    /**
     * Download all iCloud photos to a local directory for a single execution (no watch loop)
     *
     * @param WeakMap<PasswordProvider, array{0: callable(string): ?string, 1: callable(string, string): void}> $password_providers_dict
     * @param callable(PhotoAsset): bool $passer
     * @param callable(PyiCloudService, Counter, PhotoAsset): bool $downloader
     * @param callable(): void $notificator
     * @param callable(string): string $lp_filename_generator
     */
    public static function core_single_run(
        Logger $logger,
        StatusExchange $status_exchange,
        GlobalConfig $global_config,
        UserConfig $user_config,
        WeakMap $password_providers_dict,
        callable $passer,
        callable $downloader,
        callable $notificator,
        callable $lp_filename_generator,
    ): int {
        $skip_bar = !Py::truthy(Os::environ_get("FORCE_TQDM")) && (
            $global_config->only_print_filenames
            || $global_config->no_progress_bar
            || !stream_isatty(STDOUT)
        );
        while (true) { // retry loop (not watch - only for immediate retries)
            $captured_responses = [];

            $append_response = function (array $response) use (&$captured_responses): void {
                $captured_responses[] = $response;
            };

            try {
                $password_providers = [];
                foreach ($password_providers_dict as $provider => $functions) {
                    $password_providers[$provider->value] = $functions;
                }
                $icloud = Authentication::authenticator(
                    $logger,
                    $global_config->domain,
                    $password_providers,
                    $global_config->mfa_provider,
                    $status_exchange,
                    $user_config->username,
                    $notificator,
                    $append_response,
                    $user_config->cookie_directory,
                    Os::environ_get("CLIENT_ID"),
                );

                // dump captured responses for debugging
                // dump_responses(logger.debug, captured_responses)

                // turn off response capture
                $icloud->response_observer = null;

                if ($user_config->auth_only) {
                    $logger->info("Authentication completed successfully");
                    return 0;
                } elseif ($user_config->list_libraries) {
                    $library_names = array_unique([
                        ...array_keys($icloud->photos()->private_libraries()),
                        ...array_keys($icloud->photos()->shared_libraries()),
                    ]);
                    echo implode("\n", $library_names) . "\n";
                    return 0;
                } else {
                    // Access to the selected library. Defaults to the primary photos object.
                    if (Py::truthy($user_config->library)) {
                        if (array_key_exists($user_config->library, $icloud->photos()->private_libraries())) {
                            $library_object = $icloud->photos()->private_libraries()[$user_config->library];
                        } elseif (array_key_exists($user_config->library, $icloud->photos()->shared_libraries())) {
                            $library_object = $icloud->photos()->shared_libraries()[$user_config->library];
                        } else {
                            $logger->error("Unknown library: %s", $user_config->library);
                            return 1;
                        }
                    } else {
                        $library_object = $icloud->photos();
                    }

                    if ($user_config->list_albums) {
                        echo "Albums:\n";
                        $album_titles = array_map(fn($a) => Py::str($a), array_values($library_object->albums()));
                        echo implode("\n", $album_titles) . "\n";
                        return 0;
                    } else {
                        if (!Py::truthy($user_config->directory)) {
                            // should be checked upstream
                            throw new \BadMethodCallException('NotImplementedError');
                        }

                        $directory = Os::path_normpath($user_config->directory);

                        if ($user_config->skip_photos || $user_config->skip_videos) {
                            $photo_video_phrase = $user_config->skip_videos ? "photos" : "videos";
                        } else {
                            $photo_video_phrase = "photos and videos";
                        }
                        if (count($user_config->albums) == 0) {
                            $album_phrase = "";
                        } elseif (count($user_config->albums) == 1) {
                            $album_phrase = " from album " . implode(",", $user_config->albums);
                        } else {
                            $album_phrase = " from albums " . implode(",", $user_config->albums);
                        }

                        $logger->debug("Looking up all {$photo_video_phrase}{$album_phrase}...");

                        $albums = count($user_config->albums) > 0
                            ? array_map(
                                fn(string $name) => $library_object->albums()[$name]
                                    ?? throw new \OutOfBoundsException(Py::repr($name)), // KeyError
                                $user_config->albums,
                            )
                            : [$library_object->all()];

                        $photos_count = array_sum(array_map(fn(PhotoAlbum $album) => count($album), $albums));
                        foreach ($albums as $photo_album) {
                            $photos_enumerator = $photo_album;

                            // Optional: Only download the x most recent photos.
                            if ($user_config->recent !== null) {
                                $photos_count = $user_config->recent;
                                $photos_top = Core::take($user_config->recent, $photos_enumerator);
                            } else {
                                $photos_top = $photos_enumerator;
                            }

                            if ($user_config->until_found !== null) {
                                $photos_count = null;
                                // ensure photos iterator doesn't have a known length
                                // photos_enumerator = (p for p in photos_enumerator)
                            }

                            // Skip the one-line progress bar if we're only printing the filenames,
                            // or if the progress bar is explicitly disabled,
                            // or if this is not a terminal (e.g. cron or piping output to file)
                            if ($skip_bar) {
                                $photos_bar = $photos_top;
                                // logger.set_tqdm(None)
                            } else {
                                $photos_bar = new Tqdm(
                                    iterable: $photos_top,
                                    total: $photos_count,
                                    leave: false,
                                    dynamic_ncols: true,
                                    ascii: true,
                                );
                                // logger.set_tqdm(photos_enumerator)
                            }

                            if ($photos_count !== null) {
                                $plural_suffix = $photos_count == 1 ? "" : "s";
                                $photos_count_str = $photos_count == 1 ? "the first" : (string) $photos_count;

                                if ($user_config->skip_photos || $user_config->skip_videos) {
                                    $photo_video_phrase = ($user_config->skip_videos ? "photo" : "video") . $plural_suffix;
                                } else {
                                    $photo_video_phrase = $photos_count == 1 ? "photo or video" : "photos and videos";
                                }
                            } else {
                                $photos_count_str = "???";
                                if ($user_config->skip_photos || $user_config->skip_videos) {
                                    $photo_video_phrase = $user_config->skip_videos ? "photos" : "videos";
                                } else {
                                    $photo_video_phrase = "photos and videos";
                                }
                            }
                            $logger->info(
                                "Downloading %s %s %s to %s ...",
                                $photos_count_str,
                                implode(",", array_map(fn($_s) => $_s->value, $user_config->sizes)),
                                $photo_video_phrase,
                                $directory,
                            );

                            $consecutive_files_found = new Counter(0);

                            /** Exit if until_found condition is reached */
                            $should_break = function (Counter $counter) use ($user_config): bool {
                                return $user_config->until_found !== null
                                    && $counter->value() >= $user_config->until_found;
                            };

                            $status_exchange->get_progress()->photos_count = $photos_count === null ? 0 : $photos_count;
                            $photos_counter = 0;

                            $now = Clock::now(Clock::get_localzone());
                            // photos_iterator = iter(photos_enumerator)

                            $download_photo = fn(Counter $counter, PhotoAsset $photo) => $downloader($icloud, $counter, $photo);

                            foreach ($photos_bar as $item) {
                                // ponytail: no `except StopIteration` (the `next(photos_iterator)` it guarded is commented out upstream)
                                if ($should_break($consecutive_files_found)) {
                                    $logger->info(
                                        "Found %s consecutive previously downloaded photos. Exiting",
                                        $user_config->until_found,
                                    );
                                    break;
                                }
                                // item = next(photos_iterator)
                                $should_delete = false;

                                $passer_result = $passer($item);
                                $download_result = $passer_result && $download_photo(
                                    $consecutive_files_found, $item
                                );
                                if ($download_result && $user_config->delete_after_download) {
                                    $should_delete = true;
                                }

                                if (
                                    $passer_result
                                    && $user_config->keep_icloud_recent_days !== null
                                ) {
                                    $created_date = $item->created()->setTimezone(Clock::get_localzone());
                                    // (now - created_date).days: timedelta.days floors
                                    $micros = ($now->getTimestamp() - $created_date->getTimestamp()) * 1_000_000
                                        + ((int) $now->format('u') - (int) $created_date->format('u'));
                                    $age_days = intdiv($micros, 86_400_000_000) - ($micros % 86_400_000_000 < 0 ? 1 : 0);
                                    $logger->debug("Created date: " . Py::str($created_date));
                                    $logger->debug(
                                        "Keep iCloud recent days: {$user_config->keep_icloud_recent_days}"
                                    );
                                    $logger->debug("Age days: {$age_days}");
                                    if ($age_days < $user_config->keep_icloud_recent_days) {
                                        // Create filename cleaner for debug message
                                        $filename_cleaner_for_debug = self::build_filename_cleaner(
                                            $user_config->keep_unicode_in_filenames
                                        );
                                        $debug_filename = FilenamePolicies::build_filename_with_policies(
                                            $user_config->file_match_policy,
                                            $filename_cleaner_for_debug,
                                            $item,
                                        );
                                        $logger->debug(
                                            "Skipping deletion of %s as it is within the keep_icloud_recent_days period (%d days old)",
                                            $debug_filename,
                                            $age_days,
                                        );
                                    } else {
                                        $should_delete = true;
                                    }
                                }

                                if ($should_delete) {
                                    // Create filename cleaner and builder for delete operations
                                    $filename_cleaner_for_delete = self::build_filename_cleaner(
                                        $user_config->keep_unicode_in_filenames
                                    );
                                    $filename_builder_for_delete = FilenamePolicies::create_filename_builder(
                                        $user_config->file_match_policy, $filename_cleaner_for_delete
                                    );
                                    if ($user_config->dry_run) {
                                        self::delete_photo_dry_run(
                                            $logger,
                                            $library_object,
                                            $item,
                                            $filename_builder_for_delete,
                                        );
                                    } else {
                                        self::delete_photo(
                                            $logger,
                                            $library_object,
                                            $item,
                                            $filename_builder_for_delete,
                                        );
                                    }

                                    // retrier(delete_local, error_handler)
                                    $photo_album->increment_offset(-1);
                                }

                                $photos_counter += 1;
                                $status_exchange->get_progress()->photos_counter = $photos_counter;

                                if ($status_exchange->get_progress()->cancel) {
                                    break;
                                }
                            }

                            if ($global_config->only_print_filenames) {
                                return 0;
                            }

                            if ($status_exchange->get_progress()->cancel) {
                                $logger->info("Iteration was cancelled");
                                $status_exchange->get_progress()->photos_last_message = "Iteration was cancelled";
                            } else {
                                if ($user_config->skip_photos || $user_config->skip_videos) {
                                    $photo_video_phrase = $user_config->skip_videos ? "photos" : "videos";
                                } else {
                                    $photo_video_phrase = "photos and videos";
                                }
                                $message = "All {$photo_video_phrase} have been downloaded";
                                $logger->info($message);
                                $status_exchange->get_progress()->photos_last_message = $message;
                            }
                            $status_exchange->get_progress()->reset();
                        }

                        if ($user_config->auto_delete) {
                            Autodelete::autodelete_photos(
                                $logger,
                                $user_config->dry_run,
                                $library_object,
                                $user_config->folder_structure,
                                $directory,
                                $user_config->sizes,
                                $lp_filename_generator,
                                $user_config->align_raw,
                            );
                        }
                    }
                }
            } catch (PyiCloudFailedLoginException $error) {
                $logger->info($error->getMessage());
                self::dump_responses($logger->debug(...), $captured_responses);
                if (in_array(PasswordProvider::WEBUI, $global_config->password_providers, true)) {
                    self::update_auth_error_in_webui($status_exchange, $error->getMessage());
                    continue;
                } else {
                    return 1;
                }
            } catch (PyiCloudFailedMFAException $error) {
                $logger->info($error->getMessage());
                self::dump_responses($logger->debug(...), $captured_responses);
                if ($global_config->mfa_provider === MFAProvider::WEBUI) {
                    self::update_auth_error_in_webui($status_exchange, $error->getMessage());
                    continue;
                } else {
                    return 1;
                }
            } catch (
                PyiCloudServiceNotActivatedException
                | PyiCloudServiceUnavailableException
                | PyiCloudAPIResponseException
                | PyiCloudConnectionErrorException $error
            ) {
                $logger->info($error->getMessage());
                self::dump_responses($logger->debug(...), $captured_responses);
                // webui will display error and wait for password again
                if (
                    in_array(PasswordProvider::WEBUI, $global_config->password_providers, true)
                    || $global_config->mfa_provider === MFAProvider::WEBUI
                ) {
                    if (self::update_auth_error_in_webui($status_exchange, $error->getMessage())) {
                        // retry if it was during auth
                        continue;
                    }
                }
                // In single run mode, return error after webui retry attempts
                return 1;
            } catch (
                ChunkedEncodingError
                | ContentDecodingError
                | StreamConsumedError
                | UnrewindableBodyError $error
            ) {
                $logger->debug($error->getMessage());
                $logger->debug("Retrying...");
                // these errors we can safely retry
                continue;
            } catch (\Throwable $e) {
                if (!($e instanceof SystemExit)) { // SystemExit is not an `Exception`
                    self::dump_responses($logger->debug(...), $captured_responses);
                }
                throw $e;
            }

            // In single run mode, we don't handle watch intervals - that's done at higher level
            break;
        }

        return 0;
    }
}
