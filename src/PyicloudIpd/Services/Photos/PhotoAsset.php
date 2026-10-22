<?php

namespace PyicloudIpd\Services\Photos;

use DateTimeImmutable;
use DateTimeZone;
use Foundation\Clock;
use Foundation\Core;
use Foundation\Foundation;
use Foundation\Http\Response;
use Foundation\Http\Session;
use Foundation\OSError;
use Foundation\Patch;
use Foundation\Py;
use Foundation\StringUtils;
use PyicloudIpd\AssetVersion as AssetVersionModule;
use PyicloudIpd\AssetVersion\AssetVersion;
use PyicloudIpd\FileMatch\FileMatchPolicy;
use PyicloudIpd\ItemType\AssetItemType;
use PyicloudIpd\RawPolicy\RawTreatmentPolicy;
use PyicloudIpd\Services\Photos as PhotosModule;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;
use WeakMap;

class PhotoAsset
{
    /** @var array<string, mixed> */
    public array $_master_record;
    /** @var array<string, mixed> */
    public array $_asset_record;

    /**
     * `Dict[VersionSize, AssetVersion] | None`: a `WeakMap` keyed by `AssetVersionSize` /
     * `LivePhotoVersionSize` cases (see `PyicloudIpd\AssetVersion::VERSION_FILENAME_SUFFIX_LOOKUP`).
     *
     * @var WeakMap<AssetVersionSize|LivePhotoVersionSize, AssetVersion>|null
     */
    public ?WeakMap $_versions;

    /**
     * @param array<string, mixed> $master_record
     * @param array<string, mixed> $asset_record
     */
    public function __construct(array $master_record, array $asset_record)
    {
        $this->_master_record = $master_record;
        $this->_asset_record = $asset_record;

        $this->_versions = null;
    }

    public const ITEM_TYPES = [
        "public.heic" => AssetItemType::IMAGE,
        "public.heif" => AssetItemType::IMAGE,
        "public.jpeg" => AssetItemType::IMAGE,
        "public.png" => AssetItemType::IMAGE,
        "com.apple.quicktime-movie" => AssetItemType::MOVIE,
        "com.adobe.raw-image" => AssetItemType::IMAGE,
        "com.canon.cr2-raw-image" => AssetItemType::IMAGE,
        "com.canon.crw-raw-image" => AssetItemType::IMAGE,
        "com.sony.arw-raw-image" => AssetItemType::IMAGE,
        "com.fuji.raw-image" => AssetItemType::IMAGE,
        "com.panasonic.rw2-raw-image" => AssetItemType::IMAGE,
        "com.nikon.nrw-raw-image" => AssetItemType::IMAGE,
        "com.pentax.raw-image" => AssetItemType::IMAGE,
        "com.nikon.raw-image" => AssetItemType::IMAGE,
        "com.olympus.raw-image" => AssetItemType::IMAGE,
        "com.canon.cr3-raw-image" => AssetItemType::IMAGE,
        "com.olympus.or-raw-image" => AssetItemType::IMAGE,
    ];

    /**
     * `PHOTO_VERSION_LOOKUP: Dict[VersionSize, str]` (a function because a class constant cannot
     * hold a `WeakMap`).
     *
     * @return WeakMap<AssetVersionSize|LivePhotoVersionSize, string>
     */
    public static function PHOTO_VERSION_LOOKUP(): WeakMap
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = new WeakMap();
            $lookup[AssetVersionSize::ORIGINAL] = "resOriginal";
            $lookup[AssetVersionSize::ALTERNATIVE] = "resOriginalAlt";
            $lookup[AssetVersionSize::MEDIUM] = "resJPEGMed";
            $lookup[AssetVersionSize::THUMB] = "resJPEGThumb";
            $lookup[AssetVersionSize::ADJUSTED] = "resJPEGFull";
            $lookup[LivePhotoVersionSize::ORIGINAL] = "resOriginalVidCompl";
            $lookup[LivePhotoVersionSize::MEDIUM] = "resVidMed";
            $lookup[LivePhotoVersionSize::THUMB] = "resVidSmall";
        }
        return $lookup;
    }

    /**
     * `VIDEO_VERSION_LOOKUP: Dict[VersionSize, str]`
     *
     * @return WeakMap<AssetVersionSize|LivePhotoVersionSize, string>
     */
    public static function VIDEO_VERSION_LOOKUP(): WeakMap
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = new WeakMap();
            $lookup[AssetVersionSize::ORIGINAL] = "resOriginal";
            $lookup[AssetVersionSize::MEDIUM] = "resVidMed";
            $lookup[AssetVersionSize::THUMB] = "resVidSmall";
        }
        return $lookup;
    }

    public function id(): string
    {
        return $this->_master_record["recordName"];
    }

    /**
     * Calculate the raw filename for this asset from filenameEnc if present.
     * Returns None if filenameEnc is not available.
     *
     * Filename cleaning should be applied by composing this with apply_filename_cleaner().
     * File match policy transformations should be applied by composing this with apply_file_match_policy().
     * Fallback to fingerprint filename should be handled by filename_with_fallback().
     */
    public function calculate_filename(): ?string
    {
        $fields = $this->_master_record["fields"];
        if (array_key_exists("filenameEnc", $fields)) {
            $filename_enc = $fields["filenameEnc"];

            $_get_value = function (array $input): string {
                return self::getitem($input, "value");
            };

            $_get_type = function (array $input): string {
                return self::getitem($input, "type");
            };

            $_match_type = function (callable $string_parser, callable $base64_parser): \Closure {
                $_internal = function (string $type) use ($string_parser, $base64_parser): \Closure {
                    if ($type === "STRING") {
                        return \Closure::fromCallable($string_parser);
                    } elseif ($type === "ENCRYPTED_BYTES") {
                        return \Closure::fromCallable($base64_parser);
                    } else {
                        throw new \ValueError("Unsupported filename encoding {$type}");
                    }
                };

                return $_internal;
            };

            $parse_base64_value = Core::compose(
                Foundation::bytes_decode("utf-8"),
                PhotosModule::b64decode(...),
            );

            $parser_selector = Core::compose($_match_type(Core::identity(...), $parse_base64_value), $_get_type);

            $type_parser = Foundation::wrap_param_in_exception("Parsing filenameEnc type", $parser_selector);

            $_value_parser = $type_parser($filename_enc);

            // Just extract and parse the raw value, no cleaning applied
            $extract_value_and_parse = Core::compose(
                $_value_parser,
                $_get_value,
            );
            $parser = Foundation::wrap_param_in_exception("Parsing filenameEnc", $extract_value_and_parse);

            return $parser($filename_enc);
        } else {
            // No filenameEnc available, return None - caller should use fallback
            return null;
        }
    }

    /** Backward compatibility property - use calculate_filename() with explicit parameters */
    public function filename(): string
    {
        // Use default file match policy for backward compatibility and compose with calculate_filename

        // Use fromMaybe to extract filename with fallback, then apply cleaning and file match policy
        $extract_with_fallback = PhotosModule::filename_with_fallback($this->id(), $this->item_type_extension());
        $raw_filename = $extract_with_fallback($this->calculate_filename());
        $filename_cleaner_transformer = PhotosModule::apply_filename_cleaner(Core::identity(...));
        $cleaned_filename = $filename_cleaner_transformer($raw_filename);
        $policy_transformer = PhotosModule::apply_file_match_policy(
            FileMatchPolicy::NAME_SIZE_DEDUP_WITH_SUFFIX,
            $this->id()
        );
        return $policy_transformer($cleaned_filename);
    }

    public function size(): int
    {
        return $this->_master_record["fields"]["resOriginalRes"]["value"]["size"];
    }

    public function created(): DateTimeImmutable
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this);
        }
        try {
            $created_date = $this->asset_date()->setTimezone(Clock::get_localzone());
        } catch (\ValueError | OSError) {
            PhotosModule::logger()->error(
                "Could not convert photo created date to local timezone (%s)",
                $this->asset_date()
            );
            $created_date = $this->asset_date();
        }

        return $created_date;
    }

    public function asset_date(): DateTimeImmutable
    {
        try {
            $value = self::getitem(self::getitem(self::getitem($this->_asset_record, "fields"), "assetDate"), "value");
            if (!is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new \TypeError("unsupported operand type(s) for /: '" . get_debug_type($value) . "' and 'float'");
            }
            $dt = self::fromtimestamp($value / 1000.0);
        } catch (\OutOfBoundsException | \TypeError | \ValueError) {
            // naive `datetime.fromtimestamp(0)` is local time, a `DateTimeImmutable` is always aware (see Foundation\Py)
            $dt = (new DateTimeImmutable('@0'))->setTimezone(Clock::get_localzone());
        }
        return $dt;
    }

    public function added_date(): DateTimeImmutable
    {
        $dt = self::fromtimestamp(
            self::getitem(self::getitem(self::getitem($this->_asset_record, "fields"), "addedDate"), "value") / 1000.0
        );
        return $dt;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function dimensions(): array
    {
        return [
            $this->_master_record["fields"]["resOriginalWidth"]["value"],
            $this->_master_record["fields"]["resOriginalHeight"]["value"],
        ];
    }

    public function item_type(): ?AssetItemType
    {
        $fields = $this->_master_record["fields"];
        if (!array_key_exists("itemType", $fields)) {
            // raise ValueError(f"Cannot find itemType in {fields!r}")
            return null;
        }
        $item_type_field = $fields["itemType"];
        if (!array_key_exists("value", $item_type_field)) {
            // raise ValueError(f"Cannot find value in itemType {item_type_field!r}")
            return null;
        }
        $item_type = $item_type_field["value"];
        if (array_key_exists($item_type, self::ITEM_TYPES)) {
            return self::ITEM_TYPES[$item_type];
        }

        $is_image_ext = Core::compose(StringUtils::endswith([".heic", ".png", ".jpg", ".jpeg"]), StringUtils::lower(...));

        if ($is_image_ext($this->filename())) {
            return AssetItemType::IMAGE;
        }
        return AssetItemType::MOVIE;
    }

    public function item_type_extension(): string
    {
        $fields = $this->_master_record["fields"];
        if (!array_key_exists("itemType", $fields) || !array_key_exists("value", $fields["itemType"])) {
            return "unknown";
        }
        $item_type = $this->_master_record["fields"]["itemType"]["value"];
        if (array_key_exists($item_type, AssetVersionModule::ITEM_TYPE_EXTENSIONS)) {
            return AssetVersionModule::ITEM_TYPE_EXTENSIONS[$item_type];
        }
        return "unknown";
    }

    /** Calculate filename for a specific asset version. */
    public function calculate_version_filename(
        AssetVersion $version,
        AssetVersionSize|LivePhotoVersionSize $version_size,
        callable $lp_filename_generator,
        ?string $filename_override = null,
    ): string {
        return AssetVersionModule::calculate_version_filename(
            $this->filename(),
            $version,
            $version_size,
            $lp_filename_generator,
            $this->item_type(),
            $filename_override,
        );
    }

    /**
     * @return WeakMap<AssetVersionSize|LivePhotoVersionSize, AssetVersion>
     */
    public function versions(): WeakMap
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this);
        }
        if ($this->_versions === null || count($this->_versions) === 0) {
            $_versions = new WeakMap();
            if ($this->item_type() === AssetItemType::MOVIE) {
                $typed_version_lookup = self::VIDEO_VERSION_LOOKUP();
            } else {
                $typed_version_lookup = self::PHOTO_VERSION_LOOKUP();
            }

            // self._master_record["dummy"] ## to trigger dump

            foreach ($typed_version_lookup as $key => $prefix) {
                $f = null;
                if (array_key_exists("{$prefix}Res", $this->_asset_record["fields"])) {
                    $f = $this->_asset_record["fields"];
                }
                if (!$f && array_key_exists("{$prefix}Res", $this->_master_record["fields"])) {
                    $f = $this->_master_record["fields"];
                }
                if ($f) {
                    $size_entry = $f["{$prefix}Res"] ?? null;
                    if ($size_entry) {
                        $size = self::getitem(self::getitem($size_entry, "value"), "size");
                        $url = self::getitem(self::getitem($size_entry, "value"), "downloadURL");
                        $checksum = self::getitem(self::getitem($size_entry, "value"), "fileChecksum");
                    } else {
                        throw new \ValueError("Expected {$prefix}Res, but missing it");
                    }

                    $type_entry = $f["{$prefix}FileType"] ?? null;
                    if ($type_entry) {
                        $asset_type = self::getitem($type_entry, "value");
                    } else {
                        throw new \ValueError("Expected {$prefix}FileType, but missing it");
                    }

                    $_versions[$key] = new AssetVersion($size, $url, $asset_type, $checksum);
                }
            }

            $this->_versions = $_versions;
        }

        return $this->_versions;
    }

    /**
     * Get asset versions with the specified raw policy applied.
     *
     * Args:
     *     raw_policy: The raw treatment policy to apply
     *
     * Returns:
     *     Dictionary of versions with raw policy applied
     *
     * @return WeakMap<AssetVersionSize|LivePhotoVersionSize, AssetVersion>
     */
    public function versions_with_raw_policy(RawTreatmentPolicy $raw_policy): WeakMap
    {
        return PhotosModule::apply_raw_policy($this->versions(), $raw_policy);
    }

    /** Download this asset using the provided session. */
    public function download(Session $session, string $url, int $start = 0): Response
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this, $session, $url, $start);
        }
        return PhotosModule::download_asset($session, $url, $start);
    }

    public function __repr__(): string
    {
        return "<" . substr(strrchr(static::class, "\\"), 1) . ": id={$this->id()}>";
    }

    /**
     * `datetime.fromtimestamp(t, tz=pytz.utc)`: microseconds rounded half to even, year 1 to 9999.
     *
     * @throws \ValueError
     */
    private static function fromtimestamp(float $t): DateTimeImmutable
    {
        $frac = fmod($t, 1.0);
        $secs = $t - $frac;
        $us = (int) round($frac * 1e6, 0, PHP_ROUND_HALF_EVEN);
        if ($us >= 1000000) {
            $secs += 1;
            $us -= 1000000;
        } elseif ($us < 0) {
            $secs -= 1;
            $us += 1000000;
        }
        if ($secs < -62135596800 || $secs > 253402300799) {
            throw new \ValueError("year is out of range");
        }
        return (new DateTimeImmutable('@' . (int) $secs))->setTimezone(new DateTimeZone("UTC"))->modify("+{$us} usec");
    }

    /**
     * `d[key]` on a dict: a missing key raises `KeyError` (`\OutOfBoundsException` whose message is `repr(key)`), PHP only warns.
     *
     * @param array<string, mixed> $d
     */
    private static function getitem(array $d, string $key): mixed
    {
        if (!array_key_exists($key, $d)) {
            throw new \OutOfBoundsException(Py::repr($key));
        }
        return $d[$key];
    }
}
