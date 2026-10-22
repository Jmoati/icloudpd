<?php

namespace Icloudpd\Config;

use PyicloudIpd\FileMatch\FileMatchPolicy;
use PyicloudIpd\LivePhotoMovFilenamePolicy\LivePhotoMovFilenamePolicy;
use PyicloudIpd\RawPolicy\RawTreatmentPolicy;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;

/**
 * `@dataclass(kw_only=True)`: build it with named arguments
 *
 * ponytail: upstream's `_DefaultConfig` base class (the fields up to `skip_photos`, which has no other
 * subclass) is folded in, with `username` and `password` after them as in the dataclass.
 * `directory` is nullable although annotated `str` upstream: `--auth-only` runs without one.
 * `notification_script` is a `pathlib.Path` upstream, a string here (its normalized form).
 */
final class UserConfig
{
    /**
     * @param list<AssetVersionSize> $sizes
     * @param list<string> $albums
     */
    public function __construct(
        public ?string $directory,
        public bool $auth_only,
        public string $cookie_directory,
        public array $sizes,
        public LivePhotoVersionSize $live_photo_size,
        public ?int $recent,
        public ?int $until_found,
        public array $albums,
        public bool $list_albums,
        public string $library,
        public bool $list_libraries,
        public bool $skip_videos,
        public bool $skip_live_photos,
        public bool $xmp_sidecar,
        public bool $force_size,
        public bool $auto_delete,
        public string $folder_structure,
        public bool $set_exif_datetime,
        public ?string $smtp_username,
        public ?string $smtp_password,
        public string $smtp_host,
        public int $smtp_port,
        public bool $smtp_no_tls,
        public ?string $notification_email,
        public ?string $notification_email_from,
        public ?string $notification_script,
        public bool $delete_after_download,
        public ?int $keep_icloud_recent_days,
        public bool $dry_run,
        public bool $keep_unicode_in_filenames,
        public LivePhotoMovFilenamePolicy $live_photo_mov_filename_policy,
        public RawTreatmentPolicy $align_raw,
        public FileMatchPolicy $file_match_policy,
        public \DateTimeImmutable|\DateInterval|null $skip_created_before,
        public \DateTimeImmutable|\DateInterval|null $skip_created_after,
        public bool $skip_photos,
        public string $username,
        public ?string $password,
    ) {
    }
}
