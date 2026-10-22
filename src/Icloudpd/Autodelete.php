<?php

namespace Icloudpd;

use Foundation\Clock;
use Foundation\Logging\Logger;
use Foundation\Os;
use Foundation\OSError;
use Foundation\Py;
use Foundation\StringUtils;
use PyicloudIpd\AssetVersion;
use PyicloudIpd\RawPolicy\RawTreatmentPolicy;
use PyicloudIpd\Services\Photos\PhotoLibrary;
use PyicloudIpd\Utils;
use PyicloudIpd\VersionSize\AssetVersionSize;

/**
 * Delete any files found in "Recently Deleted"
 */
final class Autodelete
{
    /**
     * Actual deletion of files
     */
    public static function delete_file(Logger $logger, string $path): bool
    {
        Os::remove($path);
        $logger->info("Deleted %s", $path);
        return true;
    }

    /**
     * Dry run deletion of files
     */
    public static function delete_file_dry_run(Logger $logger, string $path): bool
    {
        $logger->info("[DRY RUN] Would delete %s", $path);
        return true;
    }

    /**
     * Scans the "Recently Deleted" folder and deletes any matching files
     * from the download directory.
     * (I.e. If you delete a photo on your phone, it's also deleted on your computer.)
     *
     * @param AssetVersionSize[] $_sizes
     * @param callable(string): string $lp_filename_generator
     */
    public static function autodelete_photos(
        Logger $logger,
        bool $dry_run,
        PhotoLibrary $library_object,
        string $folder_structure,
        string $directory,
        array $_sizes,
        callable $lp_filename_generator,
        RawTreatmentPolicy $raw_policy,
    ): void {
        $logger->info("Deleting any files found in 'Recently Deleted'...");

        $recently_deleted = $library_object->recently_deleted();

        foreach ($recently_deleted as $media) {
            try {
                $created_date = $media->created()->setTimezone(Clock::get_localzone());
            } catch (\ValueError | OSError) {
                $logger->error("Could not convert media created date to local timezone %s", $media->created());
                $created_date = $media->created();
            }

            if (StringUtils::lower($folder_structure) === "none") {
                $date_path = "";
            } else {
                try {
                    $date_path = Py::format($folder_structure, $created_date);
                } catch (\ValueError) { // pragma: no cover
                    // This error only seems to happen in Python 2
                    $logger->error("Photo created date was not valid (%s)", $created_date);
                    // e.g. ValueError: year=5 is before 1900
                    // (https://github.com/icloud-photos-downloader/icloud_photos_downloader/issues/122)
                    // Just use the Unix epoch
                    $created_date = (new \DateTimeImmutable('@0'))->setTimezone(Clock::get_localzone());
                    $date_path = Py::format($folder_structure, $created_date);
                }
            }

            $download_dir = Os::path_join($directory, $date_path);

            $paths = [];
            [$versions, $filename_overrides] = Utils::disambiguate_filenames(
                $media->versions_with_raw_policy($raw_policy), $_sizes, $media, $lp_filename_generator
            );
            foreach ($versions as $_size => $_version) {
                if (in_array($_size, [AssetVersionSize::ALTERNATIVE, AssetVersionSize::ADJUSTED], true)) {
                    $version_filename = AssetVersion::calculate_version_filename(
                        $media->filename(),
                        $_version,
                        $_size,
                        $lp_filename_generator,
                        $media->item_type(),
                        $filename_overrides[$_size] ?? null,
                    );
                    $paths[Os::path_normpath(Paths::local_download_path($version_filename, $download_dir))] = true;
                    $paths[Os::path_normpath(Paths::local_download_path($version_filename, $download_dir)) . ".xmp"] = true;
                }
            }
            foreach ($media->versions_with_raw_policy($raw_policy) as $_size => $_version) {
                if (!in_array($_size, [AssetVersionSize::ALTERNATIVE, AssetVersionSize::ADJUSTED], true)) {
                    $version_filename = AssetVersion::calculate_version_filename(
                        $media->filename(),
                        $_version,
                        $_size,
                        $lp_filename_generator,
                        $media->item_type(),
                    );
                    $paths[Os::path_normpath(Paths::local_download_path($version_filename, $download_dir))] = true;
                    $paths[Os::path_normpath(Paths::local_download_path($version_filename, $download_dir)) . ".xmp"] = true;
                }
            }
            foreach (array_keys($paths) as $path) {
                if (Os::path_exists($path)) {
                    $logger->debug("Deleting %s...", $path);
                    $delete_local = $dry_run ? self::delete_file_dry_run(...) : self::delete_file(...);
                    $delete_local($logger, $path);
                }
            }
        }
    }
}
