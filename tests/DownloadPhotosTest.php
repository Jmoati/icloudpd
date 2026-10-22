<?php

namespace Tests;

use Foundation\Http\Response;
use Foundation\Os;
use Foundation\Patch;
use Icloudpd\Constants;
use PyicloudIpd\AssetVersion\AssetVersion;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudAPIResponseException;
use PyicloudIpd\Services\Photos\PhotoAlbum;
use PyicloudIpd\Services\Photos\PhotoAsset;
use PyicloudIpd\Services\Photos\PhotoLibrary;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;

class DownloadPhotosTest extends TestCase
{
    /** unittest.mock.ANY */
    private const ANY = "\0mock.ANY\0";

    private const PHOTO_ASSET_DOWNLOAD = 'PyicloudIpd\Services\Photos\PhotoAsset::download';
    private const PHOTO_ASSET_VERSIONS = 'PyicloudIpd\Services\Photos\PhotoAsset::versions';
    private const PHOTO_ASSET_CREATED = 'PyicloudIpd\Services\Photos\PhotoAsset::created';
    private const PHOTO_ALBUM_PHOTOS_REQUEST = 'PyicloudIpd\Services\Photos\PhotoAlbum::photos_request';
    private const PHOTO_LIBRARY_FETCH_FOLDERS = 'PyicloudIpd\Services\Photos\PhotoLibrary::_fetch_folders';
    private const PYICLOUD_SERVICE_AUTHENTICATE = 'PyicloudIpd\Base\PyiCloudService::authenticate';
    private const DOWNLOAD_MEDIA = 'Icloudpd\Download::download_media';
    private const DOWNLOAD_OPEN = 'Icloudpd\Download::open';
    private const BASE_DELETE_PHOTO = 'Icloudpd\Base::delete_photo';
    private const GET_PHOTO_EXIF = 'Icloudpd\ExifDatetime::get_photo_exif';
    private const SET_PHOTO_EXIF = 'Icloudpd\ExifDatetime::set_photo_exif';
    private const TIME_SLEEP = 'Foundation\Time::sleep';
    private const OS_UTIME = 'Foundation\Os::utime';
    private const OS_MAKEDIRS = 'Foundation\Os::makedirs';

    /** `setattr(PhotoAsset, "already_downloaded", True)`: a class attribute, so it outlives the test */
    private static bool $PhotoAsset_already_downloaded = false;

    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    // ---------------------------------------------------------------- unittest.mock stand-ins

    /** `self.assertEqual` as handed to `run_icloudpd_test` */
    private function assertEqual(mixed $first, mixed $second, string $msg = ''): void
    {
        $this->assertSame($second, $first, $msg);
    }

    /** Runs the original of a patched function (`orig = X; ... orig(...)` upstream) */
    private function call_original(string $key, callable $call): mixed
    {
        $patch = Patch::get($key);
        Patch::clear($key);
        try {
            return $call();
        } finally {
            if ($patch !== null) {
                Patch::set($key, $patch);
            }
        }
    }

    /** `mock.MagicMock()` standing for a `Response`: truthy `ok`, `iter_content` iterates nothing */
    private function magic_mock_response(): Response
    {
        return new Response(200, [], '');
    }

    /** `mocked_download` of the upstream tests: first call is real, the following ones return a mock */
    private function patch_download_first_real(): void
    {
        $mocked_download = function (PhotoAsset $pa, mixed $session, string $_url, int $start) {
            if (!self::$PhotoAsset_already_downloaded) {
                $response = $this->call_original(self::PHOTO_ASSET_DOWNLOAD, fn() => $pa->download($session, $_url, $start));
                self::$PhotoAsset_already_downloaded = true;
                return $response;
            }
            return $this->magic_mock_response();
        };
        Patch::set(self::PHOTO_ASSET_DOWNLOAD, $mocked_download);
    }

    /** `mocked_authenticate`: let the initial authenticate() call succeed, but do nothing on the second try */
    private function patch_authenticate_once(): void
    {
        $already_authenticated = new \WeakMap();
        $mocked_authenticate = function (PyiCloudService $self) use ($already_authenticated): void {
            if (!isset($already_authenticated[$self])) {
                $this->call_original(self::PYICLOUD_SERVICE_AUTHENTICATE, fn() => $self->authenticate());
                $already_authenticated[$self] = true;
            }
        };
        Patch::set(self::PYICLOUD_SERVICE_AUTHENTICATE, $mocked_authenticate);
    }

    /** `mock.patch.object(piexif, "load")` with `side_effect = InvalidImageDataError`: both functions end in their except branch */
    private function patch_piexif_load_invalid_image_data(): void
    {
        Patch::set(self::GET_PHOTO_EXIF, function ($logger, string $path) {
            $logger->debug('Error fetching EXIF data for %s', $path);
            return null;
        });
        Patch::set(self::SET_PHOTO_EXIF, function ($logger, string $path, string $date): void {
            $logger->debug('Error setting EXIF data for %s', $path);
        });
    }

    /** `mock.patch.object(piexif, "insert")` with `side_effect = InvalidImageDataError`: set_photo_exif ends in its except branch */
    private function patch_piexif_insert_invalid_image_data(): void
    {
        Patch::set(self::SET_PHOTO_EXIF, function ($logger, string $path, string $date): void {
            $logger->debug('Error setting EXIF data for %s', $path);
        });
    }

    /** `mock.patch("icloudpd.exif_datetime.get_photo_exif")` with `return_value = False` */
    private function patch_get_photo_exif_false(): void
    {
        Patch::set(self::GET_PHOTO_EXIF, fn(...$_args) => false);
    }

    /** `{AssetVersionSize.ORIGINAL: ..., ...}`: a dict keyed by enum members is a `WeakMap` in the port */
    private function versions_dict(array $pairs): \WeakMap
    {
        $versions = new \WeakMap();
        foreach ($pairs as [$size, $version]) {
            $versions[$size] = $version;
        }
        return $versions;
    }

    private function call_matches(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $i => $e) {
            if ($e !== self::ANY && $e !== $actual[$i]) {
                return false;
            }
        }
        return true;
    }

    private function format_calls(array $calls): string
    {
        $format_arg = fn($arg) => match (true) {
            $arg instanceof \UnitEnum => $arg::class . '::' . $arg->name,
            is_object($arg) => $arg::class,
            $arg === self::ANY => 'ANY',
            default => var_export($arg, true),
        };
        return implode("\n", array_map(fn($call) => 'call(' . implode(', ', array_map($format_arg, $call)) . ')', $calls));
    }

    /** `mock.assert_has_calls(expected_calls)`: the calls must be sequential */
    private function assert_has_calls(array $expected_calls, array $mock_calls): void
    {
        $n = count($expected_calls);
        for ($i = 0; $i + $n <= count($mock_calls); $i++) {
            $matches = true;
            for ($j = 0; $j < $n; $j++) {
                if (!$this->call_matches($expected_calls[$j], $mock_calls[$i + $j])) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail("Calls not found.\nExpected:\n" . $this->format_calls($expected_calls) . "\nActual:\n" . $this->format_calls($mock_calls));
    }

    private function assert_called_once(array $mock_calls): void
    {
        $this->assertCount(1, $mock_calls, 'Expected to have been called once. Called ' . count($mock_calls) . ' times.');
    }

    private function assert_called_once_with(array $expected_call, array $mock_calls): void
    {
        $this->assert_called_once($mock_calls);
        $this->assertTrue(
            $this->call_matches($expected_call, $mock_calls[0]),
            "expected call not found.\nExpected: " . $this->format_calls([$expected_call]) . "\nActual: " . $this->format_calls($mock_calls),
        );
    }

    /** `os.path.getmtime` */
    private function getmtime(string $path): int
    {
        clearstatcache(true, $path);
        return filemtime($path);
    }

    /** `os.path.getsize` */
    private function getsize(string $path): int
    {
        clearstatcache(true, $path);
        return filesize($path);
    }

    // ---------------------------------------------------------------- tests

    public function test_download_and_skip_existing_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 5 original photos to {$data_dir} ...",
            $result->output,
        );
        foreach ($files_to_download as [$dir_name, $file_name]) {
            $file_path = Os::path_normpath(Os::path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                'Downloading ' . Os::path_join($data_dir, $file_path),
                $result->output,
            );
        }
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        foreach ($files_to_create as [$dir_name, $file_name]) {
            $file_path = Os::path_normpath(Os::path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                Os::path_join($data_dir, $file_path) . ' already exists',
                $result->output,
            );
        }

        $this->assertStringContainsString(
            'Skipping IMG_7405.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7404.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that file was downloaded
        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->getmtime(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
        );
        $photo_modified_time = gmdate('Y-m-d H:i:s', $photo_mtime);
        $this->assertSame('2018-07-31 07:22:24', $photo_modified_time);
    }

    public function test_download_photos_and_set_exif(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $files_to_download = [
            ['2018/07/30', 'IMG_7405.MOV'],
            ['2018/07/30', 'IMG_7407.MOV'],
            ['2018/07/30', 'IMG_7408.MOV'],
            ['2018/07/31', 'IMG_7409.JPG'],
            ['2018/07/31', 'IMG_7409.MOV'],
        ];

        // Download the first photo, but mock the video download
        $this->patch_download_first_real();

        $this->patch_get_photo_exif_false();
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '4',
                '--set-exif-datetime',
                // '--skip-videos',
                // "--skip-live-photos",
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading 4 original photos and videos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $result->output,
        );
        // 2018:07:31 07:22:24 utc
        $expectedDatetime = (new \DateTimeImmutable('2018-07-31 07:22:24', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:sO');
        $this->assertStringContainsString(
            'Setting EXIF timestamp for ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')) . ": {$expectedDatetime}",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
    }

    public function test_download_photos_and_get_exif_exceptions(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        $this->patch_piexif_load_invalid_image_data();

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString(
            'Error fetching EXIF data for ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString(
            'Error setting EXIF data for ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
    }

    public function test_skip_existing_downloads(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/31', 'IMG_7409.JPG', 1884695],
            ['2018/07/31', 'IMG_7409.MOV', 3294075],
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                // '--skip-videos',
                // "--skip-live-photos",
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos and videos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')) . ' already exists',
            $result->output,
        );
        $this->assertStringContainsString(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.MOV')) . ' already exists',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
    }

    public function test_until_found(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download_ext = [
            ['2018/07/31', 'IMG_7409.JPG', 'photo'],
            ['2018/07/31', 'IMG_7409-medium.MOV', 'photo'],
            ['2018/07/30', 'IMG_7407.JPG', 'photo'],
            ['2018/07/30', 'IMG_7407-medium.MOV', 'photo'],
            ['2018/07/30', 'IMG_7403.MOV', 'video'],
            ['2018/07/30', 'IMG_7402.MOV', 'video'],
            ['2018/07/30', 'IMG_7399-medium.MOV', 'photo'],
        ];
        $files_to_create_ext = [
            ['2018/07/30', 'IMG_7408.JPG', 'photo', 1151066],
            ['2018/07/30', 'IMG_7408-medium.MOV', 'photo', 894467],
            ['2018/07/30', 'IMG_7405.MOV', 'video', 36491351],
            ['2018/07/30', 'IMG_7404.MOV', 'video', 225935003],
            // TODO large files on Windows times out
            ['2018/07/30', 'IMG_7401.MOV', 'photo', 565699696],
            ['2018/07/30', 'IMG_7400.JPG', 'photo', 2308885],
            ['2018/07/30', 'IMG_7400-medium.MOV', 'photo', 1238639],
            ['2018/07/30', 'IMG_7399.JPG', 'photo', 2251047],
        ];
        $files_to_create = array_map(
            fn($f) => [$f[0], $f[1], $f[3]],
            $files_to_create_ext,
        );

        $dp_calls = [];
        Patch::set(self::DOWNLOAD_MEDIA, function (...$args) use (&$dp_calls) {
            $dp_calls[] = $args;
            return true;
        });
        Patch::set(self::OS_UTIME, fn(...$_args) => null);
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            [],  // we fake downloading
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--live-photo-size',
                'medium',
                '--until-found',
                '3',
                '--recent',
                '20',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $expected_calls = array_map(
            fn($f) => [
                self::ANY,
                false,
                self::ANY,
                self::ANY,
                Os::path_join($data_dir, Os::path_normpath($f[0]), $f[1]),
                self::ANY,
                ($f[2] === 'photo' && str_ends_with($f[1], '.MOV'))
                    ? LivePhotoVersionSize::MEDIUM
                    : AssetVersionSize::ORIGINAL,
                self::ANY,  // filename_builder
            ],
            $files_to_download_ext,
        );
        $this->assert_has_calls($expected_calls, $dp_calls);

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading ??? original photos and videos to {$data_dir} ...",
            $result->output,
        );

        foreach ($files_to_create as $s) {
            $expected_message = Os::path_join($data_dir, Os::path_normpath($s[0]), $s[1]) . ' already exists';
            $this->assertStringContainsString($expected_message, $result->output);
        }

        foreach ($files_to_download_ext as $d) {
            $expected_message = Os::path_join($data_dir, Os::path_normpath($d[0]), $d[1]) . ' already exists';
            $this->assertStringNotContainsString($expected_message, $result->output);
        }

        $this->assertStringContainsString(
            'Found 3 consecutive previously downloaded photos. Exiting',
            $result->output,
        );
        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_io_error(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        // Raise IOError when we try to write to the destination file
        Patch::set(self::DOWNLOAD_OPEN, function (...$_args): never {
            throw new \Foundation\OSError();
        });

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'IOError while writing file to '
            . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')) . '. '
            . 'You might have run out of disk space, or the file might '
            . 'be too large for your OS. Skipping this file...',
            $result->output,
        );
        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_session_error_during_download(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function (...$_args): never {
            throw new PyiCloudAPIResponseException('Invalid global session', '100');
        };

        $sleep_count = 0;
        Patch::set(self::TIME_SLEEP, function (...$_args) use (&$sleep_count) {
            $sleep_count++;
        });
        Patch::set(self::PHOTO_ASSET_DOWNLOAD, $mock_raise_response_error);

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $this->patch_authenticate_once();

        // Pass fixed client ID via environment variable
        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertSame(
            max(1, Constants::$MAX_RETRIES),
            substr_count($result->output, 'Session error, re-authenticating...'),
            'retry count',
        );

        $this->assertStringContainsString(
            'Could not download IMG_7409.JPG. Please try again later.',
            $result->output,
        );

        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertSame(
            max(0, Constants::$MAX_RETRIES - 1),
            $sleep_count,
            'sleep count',
        );
        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_session_error_during_photo_iteration(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function (...$_args): never {
            throw new PyiCloudAPIResponseException('Invalid global session', '100');
        };

        $sleep_count = 0;
        Patch::set(self::TIME_SLEEP, function (...$_args) use (&$sleep_count) {
            $sleep_count++;
        });
        Patch::set(self::PHOTO_ALBUM_PHOTOS_REQUEST, $mock_raise_response_error);

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $this->patch_authenticate_once();

        // Pass fixed client ID via environment variable
        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertSame(
            max(0, Constants::$MAX_RETRIES),
            substr_count($result->output, 'Session error, re-authenticating...'),
            'retry count',
        );

        $this->assertStringContainsString(
            'Invalid global session',
            $result->output,
        );
        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertSame(
            max(0, Constants::$MAX_RETRIES - 1),
            $sleep_count,
            'sleep count',
        );

        $this->assertSame(1, $result->exit_code);
    }

    public function test_handle_albums_error(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function (...$_args): never {
            throw new PyiCloudAPIResponseException('Api Error', '100');
        };

        Patch::set(self::PHOTO_LIBRARY_FETCH_FOLDERS, $mock_raise_response_error);

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $this->patch_authenticate_once();

        $orig_wait_seconds = Constants::$WAIT_SECONDS;
        Constants::$WAIT_SECONDS = 0;
        try {
            [, $result] = Helpers::run_icloudpd_test(
                $this->assertEqual(...),
                $this->root_path,
                $base_dir,
                'listing_albums.yml',
                [],
                [],
                [
                    '--username',
                    'jdoe@gmail.com',
                    '--password',
                    'password1',
                    '--recent',
                    '1',
                    '--skip-videos',
                    '--skip-live-photos',
                    '--no-progress-bar',
                    '--threads-num',
                    '1',
                ],
            );
        } finally {
            Constants::$WAIT_SECONDS = $orig_wait_seconds;
        }

        $this->assertSame(1, $result->exit_code);
    }

    public function test_missing_size(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        // `mock.patch.object(Response, "ok")` with `__get__` returning False, and `download` returning a bare
        // `Response()`: the response the download gets is not ok.
        // ponytail: only the response returned by `download` is not ok here, Response::ok stays real elsewhere
        Patch::set(self::PHOTO_ASSET_DOWNLOAD, fn(...$_args) => new Response(404, [], ''));

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '3',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading 3 original photos and videos to {$data_dir} ...",
            $result->output,
        );

        // These error messages should not be repeated more than once for each size
        foreach (['IMG_7409.JPG', 'IMG_7408.JPG', 'IMG_7407.JPG'] as $filename) {
            foreach (['original'] as $size) {
                $this->assertSame(
                    1,
                    count(array_filter(
                        Helpers::splitlines($result->output),
                        fn($line) => $line === "Could not find URL to download {$filename} for size {$size}",
                    )),
                    "Errors for {$filename} size {$size}",
                );
            }
        }

        foreach (['IMG_7409.MOV', 'IMG_7408.MOV', 'IMG_7407.MOV'] as $filename) {
            foreach (['original'] as $size) {
                $this->assertSame(
                    1,
                    count(array_filter(
                        Helpers::splitlines($result->output),
                        fn($line) => $line === "Could not find URL to download {$filename} for size {$size}",
                    )),
                    "Errors for {$filename} size {$size}",
                );
            }
        }

        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code, 'Exit code');
    }

    public function test_size_fallback_to_original(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $dp_calls = [];
        Patch::set(self::DOWNLOAD_MEDIA, function (...$args) use (&$dp_calls) {
            $dp_calls[] = $args;
            return true;
        });

        Patch::set(self::OS_UTIME, fn(...$_args) => null);

        $versions = $this->versions_dict([
            [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'jpeg', 'blah')],
            [AssetVersionSize::MEDIUM, new AssetVersion(2, 'ftp', 'movie', 'blah')],
        ]);
        Patch::set(self::PHOTO_ASSET_VERSIONS, fn(...$_args) => $versions);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--size',
                'thumb',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first thumb photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        // Should be called once for thumb size (fallback to original)
        $this->assert_called_once($dp_calls);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_adjusted_size_fallback_to_original(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $dp_calls = [];
        Patch::set(self::DOWNLOAD_MEDIA, function (...$args) use (&$dp_calls) {
            $dp_calls[] = $args;
            return true;
        });

        Patch::set(self::OS_UTIME, fn(...$_args) => null);

        $versions = $this->versions_dict([
            [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'jpeg', 'blah')],
            // AssetVersionSize.ADJUSTED: AssetVersion("IMG_7409.JPG", 2, "ftp", "movie"),
        ]);
        Patch::set(self::PHOTO_ASSET_VERSIONS, fn(...$_args) => $versions);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--size',
                'adjusted',
                '--size',
                'alternative',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first adjusted,alternative photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        // Should be called twice - once for adjusted (fallback to original) and once for alternative (fallback to original)
        $this->assertSame(2, count($dp_calls));

        $this->assertSame(0, $result->exit_code);
    }

    public function test_force_size(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $dp_calls = [];
        Patch::set(self::DOWNLOAD_MEDIA, function (...$args) use (&$dp_calls) {
            $dp_calls[] = $args;
            return true;
        });

        $versions = $this->versions_dict([
            [AssetVersionSize::ORIGINAL, ['filename' => 'IMG1.JPG']],
            [AssetVersionSize::MEDIUM, ['filename' => 'IMG_1.JPG']],
        ]);
        Patch::set(self::PHOTO_ASSET_VERSIONS, fn(...$_args) => $versions);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--size',
                'thumb',
                '--force-size',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first thumb photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'thumb size does not exist for IMG_7409.JPG. Skipping...',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertCount(0, $dp_calls);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_two_sizes_with_force_size(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $dp_calls = [];
        Patch::set(self::DOWNLOAD_MEDIA, function (...$args) use (&$dp_calls) {
            $dp_calls[] = $args;
            return true;
        });

        Patch::set(self::OS_UTIME, fn(...$_args) => null);

        $versions = $this->versions_dict([
            [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'jpeg', 'blah')],
            [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'jpeg', 'blah')],
        ]);
        Patch::set(self::PHOTO_ASSET_VERSIONS, fn(...$_args) => $versions);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--size',
                'medium',
                '--size',
                'thumb',
                '--force-size',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first medium,thumb photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'medium size does not exist for IMG_7409.JPG. Skipping...',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assert_called_once_with(
            [
                self::ANY,
                false,
                self::ANY,
                self::ANY,
                Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409-thumb.JPG')),
                self::ANY,
                AssetVersionSize::THUMB,
                self::ANY,  // filename_builder
            ],
            $dp_calls,
        );

        $this->assertSame(0, $result->exit_code);
    }

    public function test_invalid_creation_date(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/01/01', 'IMG_7409.JPG']];

        // Can't mock `astimezone` because it's a readonly property, so have to
        // create a new class that inherits from datetime.datetime
        $new_date_time = new class ('2018-01-01 00:00:00', new \DateTimeZone('UTC')) extends \DateTimeImmutable {
            public function setTimezone(\DateTimeZone $timezone): static
            {
                throw new \ValueError('Invalid date');
            }
        };
        Patch::set(self::PHOTO_ASSET_CREATED, fn(...$_args) => $new_date_time);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Could not convert photo created date to local timezone (2018-01-01 00:00:00)',
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading ' . Os::path_join($data_dir, Os::path_normpath('2018/01/01/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_creation_date_without_century(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('does not run on windows');
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('does not run on mac');
        }

        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['5/01/01', 'IMG_7409.JPG']];

        // Can't mock `astimezone` because it's a readonly property, so have to
        // create a new class that inherits from datetime.datetime
        // class NewDateTime(datetime.datetime):
        //     def astimezone(self, _tz: (Optional[Any]) = None) -> NoReturn:
        //         raise ValueError("Invalid date")

        $created = new \DateTimeImmutable('0005-01-01 00:00:00', new \DateTimeZone('America/Los_Angeles'));
        Patch::set(self::PHOTO_ASSET_CREATED, fn(...$_args) => $created);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        // self.assertIn(
        //     "Could not convert photo created date to local timezone (0005-01-01 00:00:00)",
        //     result.output,
        // )
        $this->assertStringContainsString(
            'Downloading ' . Os::path_join($data_dir, Os::path_normpath('5/01/01/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_creation_date_prior_1970(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['1965/01/01', 'IMG_7409.JPG']];

        // Can't mock `astimezone` because it's a readonly property, so have to
        // create a new class that inherits from datetime.datetime
        // class NewDateTime(datetime.datetime):
        //     def astimezone(self, _tz: (Optional[Any]) = None) -> NoReturn:
        //         raise ValueError("Invalid date")

        $created = new \DateTimeImmutable('1965-01-01 00:00:00', new \DateTimeZone('UTC'));
        Patch::set(self::PHOTO_ASSET_CREATED, fn(...$_args) => $created);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-live-photos',
                '--no-progress-bar',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading ' . Os::path_join($data_dir, Os::path_normpath('1965/01/01/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_missing_item_type(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409.JPG'],
        ];

        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_missing_item_type.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--no-progress-bar',
                '--skip-live-photos',
            ],
        );

        $this->assertSame(0, $result->exit_code);
    }

    public function test_missing_item_type_value(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409.JPG'],
        ];

        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_missing_item_type_value.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--no-progress-bar',
                '--skip-live-photos',
            ],
        );

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_and_dedupe_existing_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/31', 'IMG_7409.JPG', 1],
            ['2018/07/31', 'IMG_7409.MOV', 1],
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7408.MOV', 1606512],
        ];

        $files_to_download = [
            ['2018/07/31', 'IMG_7409-1884695.JPG'],
            ['2018/07/31', 'IMG_7409-3294075.MOV'],
            ['2018/07/30', 'IMG_7407.JPG'],
            ['2018/07/30', 'IMG_7407.MOV'],
        ];

        // Download the first photo, but mock the video download
        $this->patch_download_first_real();

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                // "--set-exif-datetime",
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 5 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409-1884695.JPG')) . ' deduplicated',
            $result->output,
        );
        $this->assertStringContainsString(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409-3294075.MOV')) . ' deduplicated',
            $result->output,
        );
        $this->assertStringContainsString('Skipping IMG_7405.MOV, only downloading photos.', $result->output);
        $this->assertStringContainsString('Skipping IMG_7404.MOV, only downloading photos.', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->getmtime(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409-1884695.JPG')),
        );
        $photo_modified_time = gmdate('Y-m-d H:i:s', $photo_mtime);
        $this->assertSame('2018-07-31 07:22:24', $photo_modified_time);
        $this->assertTrue(
            file_exists(
                Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409-3294075.MOV')),
            ),
        );
        $photo_mtime = $this->getmtime(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409-3294075.MOV')),
        );
        $photo_modified_time = gmdate('Y-m-d H:i:s', $photo_mtime);
        $this->assertSame('2018-07-31 07:22:24', $photo_modified_time);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_photos_and_set_exif_exceptions(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        $this->patch_piexif_insert_invalid_image_data();
        $this->patch_get_photo_exif_false();
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        // 2018:07:31 07:22:24 utc
        $expectedDatetime = (new \DateTimeImmutable('2018-07-31 07:22:24', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:sO');
        $this->assertStringContainsString(
            'Setting EXIF timestamp for ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')) . ": {$expectedDatetime}",
            $result->output,
        );
        $this->assertStringContainsString(
            'Error setting EXIF data for ' . Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_chinese(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__, '中文');

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->getmtime(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
        );
        $photo_modified_time = gmdate('Y-m-d H:i:s', $photo_mtime);
        $this->assertSame('2018-07-31 07:22:24', $photo_modified_time);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_one_recent_live_photo(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409.JPG'],
            ['2018/07/31', 'IMG_7409.MOV'],
        ];

        // Download the first photo, but mock the video download
        $this->patch_download_first_real();

        $this->patch_get_photo_exif_false();
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                // "--set-exif-datetime",
                // '--skip-videos',
                // "--skip-live-photos",
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_one_recent_live_photo_chinese(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_中文_7409.JPG'],  // SU1HX+S4reaWh183NDA5LkpQRw==
            ['2018/07/31', 'IMG_中文_7409.MOV'],
        ];

        // Download the first photo, but mock the video download
        $this->patch_download_first_real();

        $this->patch_get_photo_exif_false();
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_chinese.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                // "--set-exif-datetime",
                // '--skip-videos',
                // "--skip-live-photos",
                '--no-progress-bar',
                '--keep-unicode-in-filenames',
                'true',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_and_delete_after(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        $this->patch_piexif_insert_invalid_image_data();
        $this->patch_get_photo_exif_false();
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('Deleted IMG_7409.JPG in iCloud', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TODO assert cass.all_played
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_and_not_delete_after_when_exists(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [['2018/07/31', 'IMG_7409.JPG', 1884695]];

        $this->patch_piexif_insert_invalid_image_data();
        $this->patch_get_photo_exif_false();
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString('Deleted IMG_7409.JPG in iCloud', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TODO assert cass.all_played
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_and_delete_after_fail(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_no_delete.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString('Deleted IMG_7409.JPG in iCloud', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TODO assert cass.all_played
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_over_old_original_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408-original.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 5 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7405.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7404.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->getmtime(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
        );
        $photo_modified_time = gmdate('Y-m-d H:i:s', $photo_mtime);
        $this->assertSame('2018-07-31 07:22:24', $photo_modified_time);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_normalized_names(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $files_to_download = [
            // <>:"/\|?*  -- windows
            // / & \0x00 -- linux
            // SU1HXzc0MDkuSlBH -> i/n v:a\0l*i?d\p<a>t"h|.JPG -> aS9uIHY6YQBsKmk/ZFxwPGE+dCJofC5KUEc=
            ['2018/07/31', 'i_n v_a_l_i_d_p_a_t_h_.JPG'],
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_bad_filename.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_internal_error_during_download(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function (...$_args): never {
            throw new PyiCloudAPIResponseException('INTERNAL_ERROR', 'INTERNAL_ERROR');
        };

        $sleep_count = 0;
        Patch::set(self::TIME_SLEEP, function (...$_args) use (&$sleep_count) {
            $sleep_count++;
        });
        Patch::set(self::PHOTO_ASSET_DOWNLOAD, $mock_raise_response_error);

        // Pass fixed client ID via environment variable
        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertSame(
            Constants::$MAX_RETRIES,
            substr_count($result->output, 'Error downloading'),
            'retry count',
        );

        $this->assertStringContainsString(
            'Could not download IMG_7409.JPG. Please try again later.',
            $result->output,
        );

        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertSame(Constants::$MAX_RETRIES, $sleep_count, 'sleep count');
        $this->assertSame(0, $result->exit_code, 'Exit Code');
    }

    public function test_handle_internal_error_during_photo_iteration(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function (...$_args): never {
            throw new PyiCloudAPIResponseException('Internal Error at Apple.', 'INTERNAL_ERROR');
        };

        $sleep_count = 0;
        Patch::set(self::TIME_SLEEP, function (...$_args) use (&$sleep_count) {
            $sleep_count++;
        });
        Patch::set(self::PHOTO_ALBUM_PHOTOS_REQUEST, $mock_raise_response_error);

        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertSame(
            Constants::$MAX_RETRIES,
            substr_count($result->output, 'Internal Error at Apple, retrying...'),
            'retry count',
        );

        $this->assertStringContainsString(
            'Internal Error at Apple.',
            $result->output,
        );

        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertSame(Constants::$MAX_RETRIES, $sleep_count, 'sleep count');

        $this->assertSame(1, $result->exit_code, 'Exit Code');
    }

    public function test_handle_io_error_mkdir(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        // Raise IOError when we try to write to the destination file
        $my_makedirs = function (string $name, int $mode = 0777, bool $exist_ok = false) use ($base_dir): void {
            if (strcmp($name, Helpers::calc_data_dir($base_dir)) > 0) {
                throw new \Foundation\OSError();
            }
            $this->call_original(self::OS_MAKEDIRS, fn() => Os::makedirs($name, $mode, $exist_ok));
        };
        Patch::set(self::OS_MAKEDIRS, $my_makedirs);

        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertSame(0, $result->exit_code, 'Exit code');
    }

    public function test_dry_run(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        [, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--dry-run',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        // self.assertIn(
        //     f"Downloading 2 original photos to {data_dir} ...",
        //     result.output,
        // )
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringNotContainsString(
            'ERROR',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_after_delete_dry_run(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $raise_response_error = function ($a0_, $a1_, $a2_): never {
            throw new \Exception('Unexpected call to delete_photo');
        };

        $this->patch_piexif_insert_invalid_image_data();
        $this->patch_get_photo_exif_false();
        Patch::set(self::BASE_DELETE_PHOTO, $raise_response_error);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--dry-run',
                '--threads-num',
                '1',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('[DRY RUN] Would delete IMG_7409.JPG in iCloud', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TDOO self.assertEqual(
        //     cass.all_played, False, "All mocks played")
        $this->assertSame(0, $result->exit_code, 'Exit code');
    }

    public function test_download_raw_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409.DNG'],  // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_raw.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_two_sizes(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $files_to_download = [['2018/07/31', 'IMG_7409.JPG'], ['2018/07/31', 'IMG_7409-thumb.JPG']];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_two_sizes.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--size',
                'original',
                '--size',
                'thumb',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original,thumb photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_raw_alt_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            [
                '2018/07/31',
                'IMG_7409.CR2',
            ],  // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            ['2018/07/31', 'IMG_7409.JPG'],
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_raw_alt.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--size',
                'original',
                '--size',
                'alternative',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original,alternative photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    /** raw+jpeg does not have adj and we do not need raw, just jpeg (orig) */
    public function test_download_raw_photos_policy_alt_with_adj(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            // '2018/07/31/IMG_7409.CR2', # SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            ['2018/07/31', 'IMG_7409.JPG'],
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_raw_alt_adj.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--size',
                'adjusted',
                '--align-raw',
                'alternative',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first adjusted photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_raw_photos_policy_orig(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            [
                '2018/07/31',
                'IMG_7409.CR2',
            ],  // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            // '2018/07/31/IMG_7409.JPG'
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_raw_alt.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                // "--size",
                // "original",
                '--align-raw',
                'original',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_raw_photos_policy_as_is(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            [
                '2018/07/31',
                'IMG_7409.CR2',
            ],  // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            // '2018/07/31/IMG_7409.JPG'
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_raw_alt.yml',
            [],
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                // "--size",
                // "original",
                '--align-raw',
                'as-is',
                '--threads-num',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_bad_filename_base64_encoding(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $files_to_download = [
            // <>:"/\|?*  -- windows
            // / & \0x00 -- linux
            // aS9uIHY6YQBsKmk/ZFxwPGE+dCJofC5KUE
            // ("2018/07/31", "i_n v_a_l_i_d_p_a_t_h_.JPG")
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_bad_filename_base64_encoding.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertInstanceOf(\ValueError::class, $result->exception);
        // self.assertEqual(result.exception, ValueError("Invalid Input: 'aS9uIHY6YQBsKmk/ZFxwPGE+dCJofC5KUE'"))
    }

    public function test_download_bad_filename_utf8_encoding(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $files_to_download = [
            // <>:"/\|?*  -- windows
            // / & \0x00 -- linux
            // aS9uIHY6YQBsKmk/ZFxwPGE+dCJofC5KUE -> abcdefgh
            // ("2018/07/31", "i_n v_a_l_i_d_p_a_t_h_.JPG")
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_bad_filename_utf8_encoding.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertInstanceOf(\ValueError::class, $result->exception);
        // self.assertEqual(result.exception, ValueError("Invalid Input: b'i\\xb7\\x1dy\\xf8!'"))
    }

    public function test_download_filename_string_encoding(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $files_to_download = [
            // SU1HXzc0MDkuSlBH -> IMG_7409.JPG
            ['2018/07/31', 'IMG_7409.JPG'],
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos_filename_string_encoding.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        Helpers::print_result_exception($result);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_from_shared_library(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--library',
                'SharedSync-00000000-1111-2222-3333-444444444444',
                '--dry-run',
                '--no-progress-bar',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos and videos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
    }

    public function test_download_and_skip_old(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            // ("2018/07/30", "IMG_7408.JPG", 1151066),
            // ("2018/07/30", "IMG_7407.JPG", 656257),
        ];

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--skip-created-before',
                '2018-07-31',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 5 original photos to {$data_dir} ...",
            $result->output,
        );
        foreach ($files_to_download as [$dir_name, $file_name]) {
            $file_path = Os::path_normpath(Os::path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                'Downloading ' . Os::path_join($data_dir, $file_path),
                $result->output,
            );
        }
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        foreach ($files_to_create as [$dir_name, $file_name]) {
            $file_path = Os::path_normpath(Os::path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                Os::path_join($data_dir, $file_path) . ' already exists',
                $result->output,
            );
        }

        $this->assertStringContainsString(
            'Skipping IMG_7405.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7404.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7407.JPG, as it was created 2018-07-30 11:44:05.108000+00:00, before 2018-07-31 00:00:00+00:00.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7408.JPG, as it was created 2018-07-30 11:44:10.176000+00:00, before 2018-07-31 00:00:00+00:00.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that file was downloaded
        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->getmtime(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
        );
        $photo_modified_time = gmdate('Y-m-d H:i:s', $photo_mtime);
        $this->assertSame('2018-07-31 07:22:24', $photo_modified_time);
    }

    public function test_download_and_skip_new(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            // ("2018/07/30", "IMG_7408.JPG", 1151066),
            // ("2018/07/30", "IMG_7407.JPG", 656257),
        ];

        $files_to_download = [
            // ("2018/07/30", "IMG_7408.JPG"),
            // ("2018/07/30", "IMG_7407.JPG"),
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--set-exif-datetime',
                '--no-progress-bar',
                '--skip-created-after',
                '2018-07-31',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        foreach ($files_to_download as [$dir_name, $file_name]) {
            $file_path = Os::path_normpath(Os::path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                'Downloading ' . Os::path_join($data_dir, $file_path),
                $result->output,
            );
        }
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        foreach ($files_to_create as [$dir_name, $file_name]) {
            $file_path = Os::path_normpath(Os::path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                Os::path_join($data_dir, $file_path) . ' already exists',
                $result->output,
            );
        }

        $this->assertStringContainsString(
            'Skipping IMG_7409.JPG, as it was created 2018-07-31 07:22:24.816000+00:00, after 2018-07-31 00:00:00+00:00',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
    }

    public function test_resume_download(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $vcr_path = Os::path_join($this->root_path, 'vcr_cassettes');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files_to_create = [
            ['2018/07/31', 'AGHJZ6A3ATJDRWS7D5WZSUMJGNIPMVOBBY======.part', 1234],
        ];

        Helpers::create_files($data_dir, $files_to_create);

        $result = Helpers::run_cassette(
            Os::path_join($vcr_path, 'listing_photos_resume.yml'),
            [
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
            ],
        );

        $this->assertSame(0, $result->exit_code);
        $out_path = Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG'));
        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            "Resuming downloading of {$out_path} from 1234",
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that file was downloaded
        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->getmtime($out_path);
        $photo_modified_time = gmdate('Y-m-d H:i:s', $photo_mtime);
        $this->assertSame(
            '2018-07-31 07:22:24',
            $photo_modified_time,
            'photo timestamp',
        );

        // check size
        $photo_size = $this->getsize($out_path);

        $this->assertSame(617 + 1234, $photo_size, 'photo size');
    }
}
