<?php

namespace Icloudpd;

use Foundation\Os;

/** Path functions */
final class Paths
{
    /** Removes unicode chars from the string */
    public static function remove_unicode_chars(string $value): string
    {
        // value.encode("utf-8").decode("ascii", "ignore"): drops the bytes of the multi-byte characters
        return preg_replace('/[\x80-\xff]/', '', $value);
    }

    /** Replaces invalid chars in filenames with '_' */
    public static function clean_filename(string $filename): string
    {
        $invalid = "<>:\"/\\|?*\0";
        $result = $filename;

        foreach (str_split($invalid) as $char) {
            $result = str_replace($char, '_', $result);
        }

        return $result;
    }

    /** Returns the full download path, including size */
    public static function local_download_path(string $filename, string $download_dir): string
    {
        $download_path = Os::path_join($download_dir, $filename);
        return $download_path;
    }
}
