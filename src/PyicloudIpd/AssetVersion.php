<?php

namespace PyicloudIpd;

use Foundation\Os;
use PyicloudIpd\AssetVersion\AssetVersion as AssetVersionClass;
use PyicloudIpd\ItemType\AssetItemType;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;
use WeakMap;

/**
 * Module `pyicloud_ipd/asset_version.py` (the class `AssetVersion` itself is
 * `PyicloudIpd\AssetVersion\AssetVersion`).
 */
final class AssetVersion
{
    // Constants moved from PhotoAsset class
    public const ITEM_TYPE_EXTENSIONS = [
        "public.heic" => "HEIC",
        "public.heif" => "HEIF",
        "public.jpeg" => "JPG",
        "public.png" => "PNG",
        "com.apple.quicktime-movie" => "MOV",
        "com.adobe.raw-image" => "DNG",
        "com.canon.cr2-raw-image" => "CR2",
        "com.canon.crw-raw-image" => "CRW",
        "com.sony.arw-raw-image" => "ARW",
        "com.fuji.raw-image" => "RAF",
        "com.panasonic.rw2-raw-image" => "RW2",
        "com.nikon.nrw-raw-image" => "NRF",
        "com.pentax.raw-image" => "PEF",
        "com.nikon.raw-image" => "NEF",
        "com.olympus.raw-image" => "ORF",
        "com.canon.cr3-raw-image" => "CR3",
        "com.olympus.or-raw-image" => "ORF",
    ];

    /**
     * `VERSION_FILENAME_SUFFIX_LOOKUP: Dict[VersionSize, str]`
     *
     * A Python dict keyed by enum members is a `WeakMap` keyed by enum cases (PHP arrays cannot
     * have enum keys, and `AssetVersionSize::MEDIUM` / `LivePhotoVersionSize::MEDIUM` share a value).
     * It is a function because a class constant cannot hold a `WeakMap`.
     *
     * @return WeakMap<AssetVersionSize|LivePhotoVersionSize, string>
     */
    public static function VERSION_FILENAME_SUFFIX_LOOKUP(): WeakMap
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = new WeakMap();
            $lookup[AssetVersionSize::MEDIUM] = "medium";
            $lookup[AssetVersionSize::THUMB] = "thumb";
        }
        return $lookup;
    }

    /** Add suffix to filename before extension. */
    public static function add_suffix_to_filename(string $suffix, string $filename): string
    {
        [$_n, $_e] = Os::path_splitext($filename);
        return $_n . $suffix . $_e;
    }

    /**
     * Calculate filename for a specific asset version with optional override.
     *
     * Args:
     *     base_filename: The base filename from PhotoAsset
     *     version: The AssetVersion object
     *     version_size: The size key for this version
     *     lp_filename_generator: Function to generate live photo filenames
     *     item_type: Asset item type for live photo detection
     *     filename_override: Optional override filename for disambiguation
     *
     * Returns:
     *     The calculated or override filename for this asset version
     */
    public static function calculate_version_filename(
        string $base_filename,
        AssetVersionClass $version,
        AssetVersionSize|LivePhotoVersionSize $version_size,
        callable $lp_filename_generator,
        ?AssetItemType $item_type = null,
        ?string $filename_override = null,
    ): string {
        if ($filename_override !== null) {
            return $filename_override;
        }

        return self::calculate_asset_version_filename(
            $base_filename,
            $version->type,
            $version_size,
            $lp_filename_generator,
            self::ITEM_TYPE_EXTENSIONS,
            self::VERSION_FILENAME_SUFFIX_LOOKUP(),
            ($item_type ?? AssetItemType::IMAGE) === AssetItemType::IMAGE,
        );
    }

    /**
     * Calculate filename for an AssetVersion based on the base filename and version properties.
     *
     * Args:
     *     base_filename: The base filename from PhotoAsset
     *     asset_type: The type field from the version
     *     version_size: The size key for this version
     *     lp_filename_generator: Function to generate live photo filenames
     *     item_type_extensions: Mapping of types to extensions
     *     version_filename_suffix_lookup: Mapping of sizes to filename suffixes
     *     is_image_item_type: Whether the parent asset is an image (for live photo detection)
     *
     * Returns:
     *     The calculated filename for this asset version
     *
     * @param array<string, string> $item_type_extensions
     * @param WeakMap<AssetVersionSize|LivePhotoVersionSize, string> $version_filename_suffix_lookup
     */
    public static function calculate_asset_version_filename(
        string $base_filename,
        string $asset_type,
        AssetVersionSize|LivePhotoVersionSize $version_size,
        callable $lp_filename_generator,
        array $item_type_extensions,
        WeakMap $version_filename_suffix_lookup,
        bool $is_image_item_type = true,
    ): string {
        $filename = $base_filename;

        // Change live photo movie file extension to .MOV
        if ($is_image_item_type && $asset_type === "com.apple.quicktime-movie") {
            $filename = $lp_filename_generator($base_filename);  // without size
        } else {
            // for non live photo movie, try to change file type to match asset type
            [$_f, $_e] = Os::path_splitext($filename);
            $filename = $_f . "." . ($item_type_extensions[$asset_type] ?? substr($_e, 1));
        }

        // add size suffix
        if (isset($version_filename_suffix_lookup[$version_size])) {
            $_size_suffix = $version_filename_suffix_lookup[$version_size];
            $filename = self::add_suffix_to_filename("-{$_size_suffix}", $filename);
        }

        return $filename;
    }
}
