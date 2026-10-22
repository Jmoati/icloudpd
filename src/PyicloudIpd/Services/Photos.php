<?php

namespace PyicloudIpd\Services;

use Foundation\Core;
use Foundation\Core\Optional;
use Foundation\Http\Response;
use Foundation\Http\Session;
use Foundation\Logging;
use Icloudpd\Paths;
use PyicloudIpd\AssetVersion;
use PyicloudIpd\AssetVersion\AssetVersion as AssetVersionClass;
use PyicloudIpd\FileMatch\FileMatchPolicy;
use PyicloudIpd\RawPolicy\RawTreatmentPolicy;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;
use WeakMap;

/**
 * Module-level functions of `pyicloud_ipd/services/photos.py` (the classes are in
 * `PyicloudIpd\Services\Photos\`).
 */
final class Photos
{
    /**
     * Create a function that applies the specified file match policy to a filename.
     *
     * Args:
     *     file_match_policy: The file match policy to apply
     *     asset_id: The asset ID used for generating unique suffixes
     *
     * Returns:
     *     A function that transforms filenames according to the policy
     */
    public static function apply_file_match_policy(FileMatchPolicy $file_match_policy, string $asset_id): \Closure
    {
        $transform_filename = function (string $filename) use ($file_match_policy, $asset_id): string {
            if ($file_match_policy === FileMatchPolicy::NAME_ID7) {
                // Generate 7-character base64 suffix from asset ID
                $id_suffix = substr(base64_encode($asset_id), 0, 7);
                return AssetVersion::add_suffix_to_filename("_{$id_suffix}", $filename);
            } else {
                // For NAME_SIZE_DEDUP_WITH_SUFFIX and other policies, return filename as-is
                // (the deduplication logic is handled elsewhere in the download process)
                return $filename;
            }
        };

        return $transform_filename;
    }

    /**
     * Create a function that applies filename cleaning to a raw filename.
     *
     * Args:
     *     filename_cleaner: The filename cleaner function to apply (e.g., unicode handling)
     *
     * Returns:
     *     A function that cleans filenames by composing basic cleaning with additional cleaning
     */
    public static function apply_filename_cleaner(callable $filename_cleaner): \Closure
    {
        $clean_filename_transform = function (string $raw_filename) use ($filename_cleaner): string {
            // Apply basic filesystem character cleaning first, then additional cleaning
            return Core::compose($filename_cleaner, Paths::clean_filename(...))($raw_filename);
        };

        return $clean_filename_transform;
    }

    /**
     * Generate a filename based on asset fingerprint when filenameEnc is not available.
     *
     * Args:
     *     asset_id: The asset ID to use for generating the fingerprint
     *     item_type_extension: The file extension based on item type
     *
     * Returns:
     *     A filename based on truncated fingerprint hash with proper extension
     */
    public static function generate_fingerprint_filename(string $asset_id, string $item_type_extension): string
    {
        // Use the truncated fingerprint (hash) plus the correct extension
        $fingerprint = substr(preg_replace("/[^0-9a-zA-Z]/u", "_", $asset_id), 0, 12);
        return implode(".", [$fingerprint, $item_type_extension]);
    }

    /**
     * Create a function that extracts filename from Maybe, using fingerprint fallback as default.
     *
     * Args:
     *     asset_id: The asset ID for generating fingerprint fallback
     *     item_type_extension: The file extension for fingerprint fallback
     *
     * Returns:
     *     A function that takes an optional filename and returns a filename,
     *     falling back to fingerprint if the input is None
     */
    public static function filename_with_fallback(string $asset_id, string $item_type_extension): \Closure
    {
        $fallback = self::generate_fingerprint_filename($asset_id, $item_type_extension);
        return Optional::fromMaybe($fallback);
    }

    /**
     * `logger = logging.getLogger(__name__)`
     */
    public static function logger()
    {
        return Logging::getLogger("pyicloud_ipd.services.photos");
    }

    /**
     * Download an asset from the given URL using the provided session.
     *
     * Args:
     *     session: The authenticated session to use for the download
     *     url: The URL to download from
     *     start: The byte offset to start downloading from (for resume capability)
     *
     * Returns:
     *     The HTTP response for the download request
     */
    public static function download_asset(Session $session, string $url, int $start = 0): Response
    {
        $headers = ["Range" => "bytes={$start}-"];
        return $session->get($url, ["headers" => $headers, "stream" => true]);
    }

    /**
     * Apply raw treatment policy to asset versions, swapping original and alternative if needed.
     *
     * Args:
     *     versions: Dictionary of asset versions
     *     raw_policy: The raw treatment policy to apply
     *
     * Returns:
     *     Dictionary of versions with raw policy applied
     *
     * @param WeakMap<AssetVersionSize|LivePhotoVersionSize, AssetVersionClass> $versions
     * @return WeakMap<AssetVersionSize|LivePhotoVersionSize, AssetVersionClass>
     */
    public static function apply_raw_policy(WeakMap $versions, RawTreatmentPolicy $raw_policy): WeakMap
    {
        // Make a copy to avoid modifying the original
        $result_versions = clone $versions;

        // swap original & alternative according to raw_policy
        if (isset($result_versions[AssetVersionSize::ALTERNATIVE]) && (
            (
                str_contains($result_versions[AssetVersionSize::ALTERNATIVE]->type, "raw")
                && $raw_policy === RawTreatmentPolicy::AS_ORIGINAL
            )
            || (
                str_contains($result_versions[AssetVersionSize::ORIGINAL]->type, "raw")
                && $raw_policy === RawTreatmentPolicy::AS_ALTERNATIVE
            )
        )) {
            $_a = clone $result_versions[AssetVersionSize::ALTERNATIVE];
            $_o = clone $result_versions[AssetVersionSize::ORIGINAL];
            $result_versions[AssetVersionSize::ALTERNATIVE] = $_o;
            $result_versions[AssetVersionSize::ORIGINAL] = $_a;
        }

        return $result_versions;
    }
}
