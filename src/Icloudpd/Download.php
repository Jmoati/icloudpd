<?php

namespace Icloudpd;

use Foundation\Clock;
use Foundation\Http\RequestException;
use Foundation\Http\Response;
use Foundation\Logging\Logger;
use Foundation\Os;
use Foundation\OSError;
use Foundation\Patch;
use Foundation\Time;
use PyicloudIpd\AssetVersion;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudAPIResponseException;
use PyicloudIpd\Services\Photos\PhotoAsset;

/**
 * Handles file downloads with retries and error handling
 */
final class Download
{
    /**
     * Set the modification time of the downloaded file to the photo creation date
     */
    public static function update_mtime(\DateTimeImmutable $created, string $download_path): void
    {
        if ($created) {
            $created_date = null;
            try {
                $created_date = $created->setTimezone(Clock::get_localzone());
            } catch (\ValueError | OSError) {
                // We already show the timezone conversion error in base.py,
                // when generating the download directory.
                // So just return silently without touching the mtime.
                return;
            }
            self::set_utime($download_path, $created_date);
        }
    }

    /**
     * Set date & time of the file
     */
    public static function set_utime(string $download_path, \DateTimeInterface $created_date): void
    {
        // ponytail: time.mktime(created_date.timetuple()) turns the local wall-clock fields back into an
        // instant, which is the timestamp itself (no OverflowError fallback to 1970-01-01 either)
        $ctime = $created_date->getTimestamp();
        Os::utime($download_path, [$ctime, $ctime]);
    }

    /**
     * Creates hierarchy of folders for file path if it needed
     */
    public static function mkdirs_for_path(Logger $logger, string $download_path): bool
    {
        try {
            // get back the directory for the file to be downloaded and create it if
            // not there already
            $download_dir = Os::path_dirname($download_path);
            Os::makedirs($download_dir, exist_ok: true);
            return true;
        } catch (OSError) {
            $logger->error(
                "Could not create folder %s",
                $download_dir,
            );
            return false;
        }
    }

    /**
     * DRY Run for Creating hierarchy of folders for file path
     */
    public static function mkdirs_for_path_dry_run(Logger $logger, string $download_path): bool
    {
        $download_dir = Os::path_dirname($download_path);
        if (!Os::path_exists($download_dir)) {
            $logger->debug(
                "[DRY RUN] Would create folder hierarchy %s",
                $download_dir,
            );
        }
        return true;
    }

    /**
     * `base64.b32encode(data).decode()`
     *
     * ponytail: PHP has no base32 in the standard library (RFC 4648 alphabet, padded)
     */
    private static function b32encode(string $data): string
    {
        $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
        $bits = "";
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, "0", STR_PAD_LEFT);
        }
        $out = "";
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, "0"))];
        }
        return $out . str_repeat("=", (8 - strlen($out) % 8) % 8);
    }

    /**
     * Thin wrapper over the builtin `open` of this module (patched by the upstream tests).
     *
     * @return resource
     */
    public static function open(string $file, string $mode)
    {
        if ($p = Patch::get(__METHOD__)) return $p($file, $mode);
        $file_obj = @fopen($file, $mode);
        if ($file_obj === false) {
            throw new OSError("[Errno 2] Cannot open '{$file}'");
        }
        return $file_obj;
    }

    /**
     * Saves response content into file with desired created date
     */
    public static function download_response_to_path(
        Response $response,
        string $temp_download_path,
        bool $append_mode,
        string $download_path,
        \DateTimeImmutable $created_date,
    ): bool {
        $file_obj = self::open($temp_download_path, $append_mode ? "ab" : "wb");
        try {
            foreach ($response->iter_content(chunk_size: 1024) as $chunk) {
                if ($chunk !== '') {
                    fwrite($file_obj, $chunk);
                }
            }
        } finally {
            fclose($file_obj);
        }
        Os::rename($temp_download_path, $download_path);
        self::update_mtime($created_date, $download_path);
        return true;
    }

    /**
     * Pretends to save response content into a file with desired created date
     */
    public static function download_response_to_path_dry_run(
        Logger $logger,
        Response $_response,
        string $_temp_download_path,
        bool $_append_mode,
        string $download_path,
        \DateTimeImmutable $_created_date,
    ): bool {
        $logger->info(
            "[DRY RUN] Would download %s",
            $download_path,
        );
        return true;
    }

    /**
     * Download the photo to path, with retries and error handling
     *
     * @param callable(PhotoAsset): string $filename_builder
     */
    public static function download_media(
        Logger $logger,
        bool $dry_run,
        PyiCloudService $icloud,
        PhotoAsset $photo,
        string $download_path,
        AssetVersion\AssetVersion $version,
        mixed $size,
        callable $filename_builder,
    ): bool {
        if ($p = Patch::get(__METHOD__)) return $p($logger, $dry_run, $icloud, $photo, $download_path, $version, $size, $filename_builder);

        $mkdirs_local = $dry_run ? self::mkdirs_for_path_dry_run(...) : self::mkdirs_for_path(...);
        if (!$mkdirs_local($logger, $download_path)) {
            return false;
        }

        $checksum = base64_decode($version->checksum);
        $checksum32 = self::b32encode($checksum);
        $download_dir = Os::path_dirname($download_path);
        $temp_download_path = Os::path_join($download_dir, $checksum32) . ".part";

        $download_local = $dry_run
            ? fn(...$args) => self::download_response_to_path_dry_run($logger, ...$args)
            : self::download_response_to_path(...);

        $retries = 0;
        while (true) {
            try {
                $append_mode = Os::path_exists($temp_download_path);
                $current_size = $append_mode ? Os::path_getsize($temp_download_path) : 0;
                if ($append_mode) {
                    $logger->debug("Resuming downloading of {$download_path} from {$current_size}");
                }

                $photo_response = $photo->download($icloud->photos()->session, $version->url, $current_size);
                if ($photo_response->ok()) {
                    return $download_local(
                        $photo_response, $temp_download_path, $append_mode, $download_path, $photo->created()
                    );
                } else {
                    // Use the standard original filename generator for error logging
                    $simple_lp_filename_generator = Base::lp_filename_original(...);

                    // Get the proper filename using filename_builder
                    $base_filename = $filename_builder($photo);
                    $version_filename = AssetVersion::calculate_version_filename(
                        $base_filename, $version, $size, $simple_lp_filename_generator, $photo->item_type()
                    );
                    $logger->error(
                        "Could not find URL to download %s for size %s",
                        $version_filename,
                        $size->value,
                    );
                    break;
                }
            } catch (PyiCloudAPIResponseException $ex) {
                if (str_contains($ex->getMessage(), "Invalid global session")) {
                    $logger->error("Session error, re-authenticating...");
                    if ($retries > 0) {
                        // If the first re-authentication attempt failed,
                        // start waiting a few seconds before retrying in case
                        // there are some issues with the Apple servers
                        Time::sleep(Constants::$WAIT_SECONDS);
                    }

                    $icloud->authenticate();
                } else {
                    // short circuiting 0 retries
                    if ($retries == Constants::MAX_RETRIES) {
                        break;
                    }
                    // you end up here when p.e. throttling by Apple happens
                    $wait_time = ($retries + 1) * Constants::$WAIT_SECONDS;
                    // Get the proper filename for error messages
                    $error_filename = $filename_builder($photo);
                    $logger->error(
                        "Error downloading %s, retrying after %s seconds...", $error_filename, $wait_time
                    );
                    Time::sleep($wait_time);
                }
            } catch (OSError | RequestException) {
                // requests.exceptions.RequestException is an IOError (OSError) upstream
                $logger->error(
                    "IOError while writing file to %s. "
                    . "You might have run out of disk space, or the file "
                    . "might be too large for your OS. "
                    . "Skipping this file...",
                    $download_path,
                );
                break;
            }
            $retries = $retries + 1;
            if ($retries >= Constants::MAX_RETRIES) {
                break;
            }
        }
        if ($retries >= Constants::MAX_RETRIES) {
            // Get the proper filename for error messages
            $error_filename = $filename_builder($photo);
            $logger->error(
                "Could not download %s. Please try again later.",
                $error_filename,
            );
        }

        return false;
    }
}
