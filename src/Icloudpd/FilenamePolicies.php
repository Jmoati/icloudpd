<?php

namespace Icloudpd;

use Foundation\Core;
use PyicloudIpd\FileMatch\FileMatchPolicy;
use PyicloudIpd\Services\Photos;
use PyicloudIpd\Services\Photos\PhotoAsset;

/** Filename building utilities with policy application */
final class FilenamePolicies
{
    /** Build filename applying cleaner and file match policy transformations. */
    public static function build_filename_with_policies(
        FileMatchPolicy $file_match_policy,
        callable $filename_cleaner,
        PhotoAsset $photo,
    ): string {
        $extract_with_fallback = Photos::filename_with_fallback($photo->id(), $photo->item_type_extension());
        $raw_filename = $extract_with_fallback($photo->calculate_filename());
        $filename_cleaner_transformer = Photos::apply_filename_cleaner($filename_cleaner);
        $cleaned_filename = $filename_cleaner_transformer($raw_filename);
        $policy_transformer = Photos::apply_file_match_policy($file_match_policy, $photo->id());
        return $policy_transformer($cleaned_filename);
    }

    /**
     * Create a filename builder function with pre-configured policy and cleaner.
     *
     * This factory function uses partial_2_1 for better type safety compared to functools.partial.
     * The resulting function only needs a PhotoAsset to produce the final filename.
     *
     * Args:
     *     file_match_policy: Policy for transforming filenames (e.g., NAME_ID_DEDUPE_SUFFIX)
     *     filename_cleaner: Function to clean/transform raw filenames (unicode handling)
     *
     * Returns:
     *     A function that takes only a PhotoAsset and returns the processed filename
     *
     * Example:
     *     # Set up once per config
     *     filename_builder = create_filename_builder(policy, cleaner)
     *
     *     # Use many times per photo
     *     for photo in photos:
     *         filename = filename_builder(photo)
     *
     * @param callable(string): string $filename_cleaner
     * @return \Closure(PhotoAsset): string
     */
    public static function create_filename_builder(
        FileMatchPolicy $file_match_policy,
        callable $filename_cleaner,
    ): \Closure {
        return Core::partial_2_1(self::build_filename_with_policies(...), $file_match_policy, $filename_cleaner);
    }
}
