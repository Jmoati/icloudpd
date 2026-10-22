<?php

namespace Icloudpd;

use Foundation\Logging\Logger;
use Foundation\Patch;
use Icloudpd\ExifDatetime\InvalidImageDataError;
use Image\ExifTool\ExifTool;

/**
 * Get/set EXIF dates from photos
 *
 * piexif is replaced by jmoati/image-exiftool. `piexif_load` and `piexif_insert` stand for the two piexif calls
 * that the upstream tests patch (`piexif.load`, `piexif.insert`): they are the seams of this port, with the same
 * failure mode (InvalidImageDataError).
 */
final class ExifDatetime
{
    /** EXIF tag 36867 (ExifIFD DateTimeOriginal) */
    private const DATE_TIME_ORIGINAL = 36867;

    /** piexif accepts JPEG, TIFF and WebP when loading, JPEG and WebP when inserting */
    private static function image_type(string $path): ?string
    {
        // ponytail: piexif lets an unreadable file escape as OSError, here it is "not an image"
        $head = @file_get_contents($path, false, null, 0, 12);
        if ($head === false) {
            return null;
        }
        if (str_starts_with($head, "\xFF\xD8")) {
            return 'jpeg';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($head, 'II') || str_starts_with($head, 'MM')) {
            return 'tiff';
        }

        return null;
    }

    /**
     * piexif.load(path), reduced to what icloudpd reads: `["Exif" => [36867 => "YYYY:MM:DD HH:MM:SS"]]`
     * (the Exif key is empty when the file has no DateTimeOriginal).
     *
     * ponytail: ExifTool is lenient where piexif raises ValueError on a damaged EXIF block (the tag is read
     * when ExifTool can find it) and the other IFDs of the dict are not loaded.
     *
     * @return array{Exif: array<int, string>}
     */
    public static function piexif_load(string $path): array
    {
        if ($p = Patch::get(__METHOD__)) return $p($path);
        if (self::image_type($path) === null) {
            throw new InvalidImageDataError('Given file is neither JPEG nor TIFF.');
        }
        try {
            // raw string, as stored; FastScan: the headers are enough
            $info = (new ExifTool())->ImageInfo($path, 'ExifIFD:DateTimeOriginal', ['PrintConv' => 0, 'FastScan' => 1]);
        } catch (\Throwable $e) {
            throw new InvalidImageDataError($e->getMessage());
        }
        $date = $info['DateTimeOriginal'] ?? null;

        return ['Exif' => is_string($date) ? [self::DATE_TIME_ORIGINAL => $date] : []];
    }

    /**
     * piexif.dump(exif_dict) + piexif.insert(exif_bytes, path) for the three tags icloudpd sets:
     * 306 in "1st" (IFD1:ModifyDate), 36867 and 36868 in "Exif" (ExifIFD:DateTimeOriginal, ExifIFD:CreateDate)
     */
    public static function piexif_insert(string $path, string $date): void
    {
        if ($p = Patch::get(__METHOD__)) { $p($path, $date); return; }
        if (!in_array(self::image_type($path), ['jpeg', 'webp'], true)) {
            throw new InvalidImageDataError();
        }
        try {
            $et = new ExifTool();
            foreach (['IFD1:ModifyDate', 'ExifIFD:DateTimeOriginal', 'ExifIFD:CreateDate'] as $tag) {
                [$count, $error] = $et->SetNewValueList($tag, $date);
                if (!$count) {
                    throw new \ValueError($error ?? "Cannot set $tag");
                }
            }
            if (!$et->WriteInfo($path)) {
                throw new InvalidImageDataError((string) $et->GetValue('Error'));
            }
        } catch (InvalidImageDataError|\ValueError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidImageDataError($e->getMessage());
        }
    }

    /** Get EXIF date for a photo, return nothing if there is an error */
    public static function get_photo_exif(Logger $logger, string $path): ?string
    {
        if ($p = Patch::get(__METHOD__)) return $p($logger, $path);
        try {
            $exif_dict = self::piexif_load($path);

            return $exif_dict['Exif'][36867] ?? null;
        } catch (\ValueError|InvalidImageDataError) {
            $logger->debug('Error fetching EXIF data for %s', $path);

            return null;
        }
    }

    /** Set EXIF date on a photo, do nothing if there is an error */
    public static function set_photo_exif(Logger $logger, string $path, string $date): void
    {
        if ($p = Patch::get(__METHOD__)) { $p($logger, $path, $date); return; }
        try {
            // piexif.load first: refuses what is not an image, as piexif does
            self::piexif_load($path);
            self::piexif_insert($path, $date);
        } catch (\ValueError|InvalidImageDataError) {
            $logger->debug('Error setting EXIF data for %s', $path);

            return;
        }
    }
}
