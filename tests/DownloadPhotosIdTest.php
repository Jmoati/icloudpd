<?php

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use Foundation\Http\Response;
use Foundation\OSError;
use Foundation\Os;
use Foundation\Patch;
use Icloudpd\Constants;
use Icloudpd\ExifDatetime\InvalidImageDataError;
use Icloudpd\StringHelpers;
use PyicloudIpd\AssetVersion\AssetVersion;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudAPIResponseException;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;

/**
 * tests/test_download_photos_id.py (class DownloadPhotoNameIDTestCase)
 */
class DownloadPhotosIdTest extends TestCase
{
    /** `mock.ANY` */
    private const ANY = "\0ANY\0";

    private string $root_path;
    private string $fixtures_path;

    /** `@pytest.fixture(autouse=True) inject_fixtures` */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    /** `self.assertEqual(first, second, msg)` */
    private function assertEqual(mixed $first, mixed $second, string $msg = ''): void
    {
        $this->assertSame($second, $first, $msg);
    }

    /** `mock.patch.object(...)` with `new=` wrapper calling the original: lifts the patch of $method while $fn runs */
    private function unpatched(string $method, callable $fn): mixed
    {
        $patch = Patch::get($method);
        Patch::clear($method);
        try {
            return $fn();
        } finally {
            if ($patch) {
                Patch::set($method, $patch);
            }
        }
    }

    /** `mock.assert_has_calls(expected_calls)`: the expected calls appear consecutively in the recorded ones */
    private function assert_has_calls(array $calls, array $expected_calls): void
    {
        $n = count($expected_calls);
        $found = false;
        for ($i = 0; $i + $n <= count($calls) && !$found; $i++) {
            $found = true;
            for ($j = 0; $j < $n && $found; $j++) {
                $actual = $calls[$i + $j];
                $expected = $expected_calls[$j];
                if (count($actual) !== count($expected)) {
                    $found = false;
                    break;
                }
                foreach ($expected as $k => $arg) {
                    if ($arg !== self::ANY && $arg !== $actual[$k]) {
                        $found = false;
                        break;
                    }
                }
            }
        }
        $this->assertTrue($found, 'Calls not found.');
    }

    private function path_join(string ...$parts): string
    {
        return Os::path_join(...$parts);
    }

    private function normpath(string $path): string
    {
        return Os::path_normpath($path);
    }

    /** `datetime.datetime.fromtimestamp(os.path.getmtime(path), datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")` */
    private function mtime_utc(string $path): string
    {
        clearstatcache(true, $path);
        return gmdate('Y-m-d H:i:s', filemtime($path));
    }

    /** `datetime.datetime(2018, 7, 31, 7, 22, 24, tzinfo=datetime.timezone.utc).astimezone().strftime("%Y-%m-%d %H:%M:%S%z")` */
    private function expected_datetime(): string
    {
        return (new DateTimeImmutable('2018-07-31 07:22:24', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:sO');
    }

    /** `mock.MagicMock()` as a download response: truthy, `.ok` truthy, `iter_content()` yields nothing */
    private function magic_response(): Response
    {
        return new Response(200, [], '');
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

    /** `result.output.splitlines()` */
    private function splitlines(string $output): array
    {
        return preg_split('/\R/u', $output);
    }

    public function test_download_and_skip_existing_photos_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 1151066],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 656257],
        ];

        $files_to_download = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG']];

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 5 original photos to {$data_dir} ...",
            $result->output,
        );
        foreach ($files_to_download as [$dir_name, $file_name]) {
            $file_path = $this->normpath($this->path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                'Downloading '.StringHelpers::truncate_middle($this->path_join($data_dir, $file_path), 96),
                $result->output,
            );
        }
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        foreach (array_map(fn($f) => [$f[0], $f[1]], $files_to_create) as [$dir_name, $file_name]) {
            $file_path = $this->normpath($this->path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                StringHelpers::truncate_middle($this->path_join($data_dir, $file_path), 96).' already exists',
                $result->output,
            );
        }

        $this->assertStringContainsString(
            'Skipping IMG_7405_QVkrUjN.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7404_QVI5TWx.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that file was downloaded
        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->mtime_utc(
            $this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')),
        );
        $this->assertEqual('2018-07-31 07:22:24', $photo_mtime);
    }

    public function test_download_photos_and_set_exif_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 1151066],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 656257],
        ];

        $files_to_download = [
            ['2018/07/30', 'IMG_7405_QVkrUjN.MOV'],
            ['2018/07/30', 'IMG_7407_QVovd0F.MOV'],
            ['2018/07/30', 'IMG_7408_QVI4T2l.MOV'],
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
            ['2018/07/31', 'IMG_7409_QVk2Yyt.MOV'],
        ];

        // Download the first photo, but mock the video download
        $method = 'PyicloudIpd\Services\Photos\PhotoAsset::download';
        $already_downloaded = false; // setattr(PhotoAsset, "already_downloaded", True)
        $mocked_download = function ($pa, $session, string $_url, int $start) use ($method, &$already_downloaded) {
            if (!$already_downloaded) {
                $response = $this->unpatched($method, fn() => $pa->download($session, $_url, $start));
                $already_downloaded = true;
                return $response;
            }
            return $this->magic_response();
        };

        Patch::set($method, $mocked_download);
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn($logger, $path) => false);
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
                '--file-match-policy',
                'name-id7',
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
            'Downloading '.StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')), 96),
            $result->output,
        );
        // 2018:07:31 07:22:24 utc
        $expectedDatetime = $this->expected_datetime();
        $this->assertStringContainsString(
            'Setting EXIF timestamp for '.StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')), 96).": {$expectedDatetime}",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
    }

    public function test_download_photos_and_get_exif_exceptions_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG']];

        // mock.patch.object(piexif, "load") with side_effect InvalidImageDataError
        Patch::set('Icloudpd\ExifDatetime::piexif_load', function (...$_args): never {
            throw new InvalidImageDataError();
        });

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
                '--file-match-policy',
                'name-id7',
            ],
        );
        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        // self.assertIn(
        //     f"Downloading {os.path.join(data_dir, os.path.normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG'))}",
        //     result.output,
        // )
        $this->assertStringContainsString(
            'Error fetching EXIF data for '.$this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')),
            $result->output,
        );
        $this->assertStringContainsString(
            'Error setting EXIF data for '.$this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
    }

    public function test_skip_existing_downloads_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG', 1884695],
            ['2018/07/31', 'IMG_7409_QVk2Yyt.MOV', 3294075],
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
                '--file-match-policy',
                'name-id7',
            ],
        );
        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all photos and videos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo or video to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')), 96).' already exists',
            $result->output,
        );
        $this->assertStringContainsString(
            StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.MOV')), 96).' already exists',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
    }

    public function test_until_found_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download_ext = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG', 'photo'],
            ['2018/07/31', 'IMG_7409_QVk2Yyt-medium.MOV', 'photo'],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 'photo'],
            ['2018/07/30', 'IMG_7407_QVovd0F-medium.MOV', 'photo'],
            ['2018/07/30', 'IMG_7403_QVc0VWt.MOV', 'video'],
            ['2018/07/30', 'IMG_7402_QVdYaDd.MOV', 'video'],
            ['2018/07/30', 'IMG_7399_QVVMcXN-medium.MOV', 'photo'],
        ];
        $files_to_create_ext = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 'photo', 1151066],
            ['2018/07/30', 'IMG_7408_QVI4T2l-medium.MOV', 'photo', 894467],
            ['2018/07/30', 'IMG_7405_QVkrUjN.MOV', 'video', 36491351],
            ['2018/07/30', 'IMG_7404_QVI5TWx.MOV', 'video', 225935003],
            // TODO large files on Windows times out
            ['2018/07/30', 'IMG_7401_QVRJanZ.MOV', 'photo', 565699696],
            ['2018/07/30', 'IMG_7400_QVhFL01.JPG', 'photo', 2308885],
            ['2018/07/30', 'IMG_7400_QVhFL01-medium.MOV', 'photo', 1238639],
            ['2018/07/30', 'IMG_7399_QVVMcXN.JPG', 'photo', 2251047],
        ];
        $files_to_create = array_map(
            fn($f) => [$f[0], $f[1], $f[3]],
            $files_to_create_ext,
        );

        $dp_calls = [];
        Patch::set('Icloudpd\Download::download_media', function (...$args) use (&$dp_calls) {
            $dp_calls[] = $args;
            return true;
        });
        Patch::set('Foundation\Os::utime', fn(...$args) => null);
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            $this->assertEqual(...),
            $this->root_path,
            $base_dir,
            'listing_photos.yml',
            $files_to_create,
            [], // we fake downloading
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $expected_calls = array_map(
            fn($f) => [
                self::ANY,
                false,
                self::ANY,
                self::ANY,
                $this->path_join($data_dir, $this->normpath($f[0]), $f[1]),
                self::ANY,
                ($f[2] === 'photo' && str_ends_with($f[1], '.MOV'))
                    ? LivePhotoVersionSize::MEDIUM
                    : AssetVersionSize::ORIGINAL,
                self::ANY, // filename_builder
            ],
            $files_to_download_ext,
        );
        $this->assert_has_calls($dp_calls, $expected_calls);

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading ??? original photos and videos to {$data_dir} ...",
            $result->output,
        );

        foreach ($files_to_create as $s) {
            $expected_message = StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath($s[0]), $s[1]), 96).' already exists';
            $this->assertStringContainsString($expected_message, $result->output);
        }

        foreach ($files_to_download_ext as $d) {
            $expected_message = StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath($d[0]), $d[1]), 96).' already exists';
            $this->assertStringNotContainsString($expected_message, $result->output);
        }

        $this->assertStringContainsString(
            'Found 3 consecutive previously downloaded photos. Exiting',
            $result->output,
        );
        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_io_error_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        // Raise IOError when we try to write to the destination file
        Patch::set('Icloudpd\Download::open', function (...$args) {
            throw new OSError();
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'IOError while writing file to '
            .$this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')).'. '
            .'You might have run out of disk space, or the file might '
            .'be too large for your OS. Skipping this file...',
            $result->output,
        );
        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_session_error_during_download_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function ($_arg, $_session, $_size) {
            throw new PyiCloudAPIResponseException('Invalid global session', '100');
        };

        $sleep_count = 0;
        Patch::set('Foundation\Time::sleep', function ($seconds) use (&$sleep_count) {
            $sleep_count++;
        });
        // mock.patch.object(PhotoAsset, "download") is a MagicMock: no self, only (session, url, start)
        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::download', function ($self, ...$args) use ($mock_raise_response_error) {
            return $mock_raise_response_error(...$args);
        });

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $method = 'PyicloudIpd\Base\PyiCloudService::authenticate';
        $already_authenticated = new \WeakMap();
        $mocked_authenticate = function (PyiCloudService $self) use ($method, $already_authenticated) {
            if (!isset($already_authenticated[$self])) {
                $this->unpatched($method, fn() => $self->authenticate());
                $already_authenticated[$self] = true;
            }
        };
        Patch::set($method, $mocked_authenticate);

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertEqual(
            substr_count($result->output, 'Session error, re-authenticating...'),
            max(1, Constants::MAX_RETRIES),
            'retry count',
        );

        $this->assertStringContainsString(
            'Could not download IMG_7409_QVk2Yyt.JPG. Please try again later.',
            $result->output,
        );

        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertEqual(
            $sleep_count,
            max(0, Constants::MAX_RETRIES - 1),
            'sleep count',
        );
        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_session_error_during_photo_iteration_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function () {
            throw new PyiCloudAPIResponseException('Invalid global session', '100');
        };

        $sleep_count = 0;
        Patch::set('Foundation\Time::sleep', function ($seconds) use (&$sleep_count) {
            $sleep_count++;
        });
        // mock.patch.object(PhotoAlbum, "photos_request") is a MagicMock: no self
        Patch::set('PyicloudIpd\Services\Photos\PhotoAlbum::photos_request', function ($self, ...$args) use ($mock_raise_response_error) {
            return $mock_raise_response_error(...$args);
        });

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $method = 'PyicloudIpd\Base\PyiCloudService::authenticate';
        $already_authenticated = new \WeakMap();
        $mocked_authenticate = function (PyiCloudService $self) use ($method, $already_authenticated) {
            if (!isset($already_authenticated[$self])) {
                $this->unpatched($method, fn() => $self->authenticate());
                $already_authenticated[$self] = true;
            }
        };
        Patch::set($method, $mocked_authenticate);

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertEqual(
            substr_count($result->output, 'Session error, re-authenticating...'),
            max(0, Constants::MAX_RETRIES),
            'retry count',
        );

        $this->assertStringContainsString(
            'Invalid global session',
            $result->output,
        );
        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertEqual(
            $sleep_count,
            max(0, Constants::MAX_RETRIES - 1),
            'sleep count',
        );

        $this->assertSame(1, $result->exit_code);
    }

    public function test_handle_albums_error_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function () {
            throw new PyiCloudAPIResponseException('Api Error', '100');
        };

        Patch::set('PyicloudIpd\Services\Photos\PhotoLibrary::_fetch_folders', function ($self, ...$args) use ($mock_raise_response_error) {
            return $mock_raise_response_error(...$args);
        });

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $method = 'PyicloudIpd\Base\PyiCloudService::authenticate';
        $already_authenticated = new \WeakMap();
        $mocked_authenticate = function (PyiCloudService $self) use ($method, $already_authenticated) {
            if (!isset($already_authenticated[$self])) {
                $this->unpatched($method, fn() => $self->authenticate());
                $already_authenticated[$self] = true;
            }
        };

        $wait_seconds = Constants::$WAIT_SECONDS;
        Constants::$WAIT_SECONDS = 0; // mock.patch("icloudpd.constants.WAIT_SECONDS", 0)
        try {
            Patch::set($method, $mocked_authenticate);
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
                    '--file-match-policy',
                    'name-id7',
                ],
            );

            $this->assertSame(1, $result->exit_code);
        } finally {
            Constants::$WAIT_SECONDS = $wait_seconds;
        }
    }

    public function test_missing_size_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        // `mock.patch.object(Response, "ok")` with `__get__` returning False, and `download` returning a bare
        // `Response()`: the response the download gets is not ok.
        // ponytail: only the response returned by `download` is not ok here, Response::ok stays real elsewhere
        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::download', fn(...$_args) => new Response(404, [], ''));

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
                '--file-match-policy',
                'name-id7',
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
        foreach ([
            'IMG_7409_QVk2Yyt.JPG',
            'IMG_7408_QVI4T2l.JPG',
            'IMG_7407_QVovd0F.JPG',
        ] as $filename) {
            foreach (['original'] as $size) {
                $this->assertEqual(
                    count(array_filter(
                        $this->splitlines($result->output),
                        fn($line) => $line === "Could not find URL to download {$filename} for size {$size}",
                    )),
                    1,
                    "Errors for {$filename} size {$size}",
                );
            }
        }

        foreach ([
            'IMG_7409_QVk2Yyt.MOV',
            'IMG_7408_QVI4T2l.MOV',
            'IMG_7407_QVovd0F.MOV',
        ] as $filename) {
            foreach (['original'] as $size) {
                $this->assertEqual(
                    count(array_filter(
                        $this->splitlines($result->output),
                        fn($line) => $line === "Could not find URL to download {$filename} for size {$size}",
                    )),
                    1,
                    "Errors for {$filename} size {$size}",
                );
            }
        }

        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertEqual($result->exit_code, 0, 'Exit code');
    }

    public function test_size_fallback_to_original_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $dp_count = 0;
        Patch::set('Icloudpd\Download::download_media', function (...$args) use (&$dp_count) {
            $dp_count++;
            return true;
        });

        Patch::set('Foundation\Os::utime', fn(...$args) => null);

        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::versions', fn($self) => $this->versions_dict([
            [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'jpeg', 'blah')],
            [AssetVersionSize::MEDIUM, new AssetVersion(2, 'ftp', 'movie', 'blah')],
        ]));

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
                '--file-match-policy',
                'name-id7',
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
            'Downloading '.StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')), 96),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        // Should be called once for thumb size (fallback to original)
        $this->assertSame(1, $dp_count);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_adjusted_size_fallback_to_original_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $dp_count = 0;
        Patch::set('Icloudpd\Download::download_media', function (...$args) use (&$dp_count) {
            $dp_count++;
            return true;
        });

        Patch::set('Foundation\Os::utime', fn(...$args) => null);

        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::versions', fn($self) => $this->versions_dict([
            [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'jpeg', 'blah')],
            // AssetVersionSize.ADJUSTED: AssetVersion("IMG_7409.JPG", 2, "ftp", "movie"),
        ]));

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
                '--file-match-policy',
                'name-id7',
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
            'Downloading',
            $result->output,
        );
        $this->assertStringContainsString(
            'IMG_7409_QVk2Yyt.JPG',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        // Should be called twice - once for adjusted (fallback to original) and once for alternative (fallback to original)
        $this->assertEqual($dp_count, 2);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_force_size_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $dp_count = 0;
        Patch::set('Icloudpd\Download::download_media', function (...$args) use (&$dp_count) {
            $dp_count++;
            return true;
        });

        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::versions', fn($self) => $this->versions_dict([
            [AssetVersionSize::ORIGINAL, ['filename' => 'IMG1.JPG']],
            [AssetVersionSize::MEDIUM, ['filename' => 'IMG_1.JPG']],
        ]));

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
                '--file-match-policy',
                'name-id7',
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
            'thumb size does not exist for IMG_7409_QVk2Yyt.JPG. Skipping...',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $dp_count); // dp_patched.assert_not_called()

        $this->assertSame(0, $result->exit_code);
    }

    public function test_invalid_creation_date_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/01/01', 'IMG_7409_QVk2Yyt.JPG']];

        // Can't mock `astimezone` because it's a readonly property, so have to
        // create a new class that inherits from datetime.datetime
        $NewDateTime = new class('2018-01-01 00:00:00', new DateTimeZone('UTC')) extends DateTimeImmutable {
            public function setTimezone(DateTimeZone $timezone): static
            {
                throw new \ValueError('Invalid date');
            }
        };

        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::created', fn($self) => $NewDateTime);

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
                '--file-match-policy',
                'name-id7',
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
            'Downloading '.StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath('2018/01/01/IMG_7409_QVk2Yyt.JPG')), 96),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_creation_date_without_century_name_id7(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('does not run on windows');
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('does not run on mac');
        }

        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['5/01/01', 'IMG_7409_QVk2Yyt.JPG']];

        // Can't mock `astimezone` because it's a readonly property, so have to
        // create a new class that inherits from datetime.datetime
        $NewDateTime = new class('0005-01-01 00:00:00', new DateTimeZone('UTC')) extends DateTimeImmutable {
            public function setTimezone(DateTimeZone $timezone): static
            {
                throw new \ValueError('Invalid date');
            }
        };

        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::created', fn($self) => $NewDateTime);

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
                '--file-match-policy',
                'name-id7',
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
            'Could not convert photo created date to local timezone (0005-01-01 00:00:00)',
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading '.StringHelpers::truncate_middle($this->path_join($data_dir, $this->normpath('5/01/01/IMG_7409_QVk2Yyt.JPG')), 96),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_creation_date_prior_1970_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['1965/01/01', 'IMG_7409_QVk2Yyt.JPG']];

        // Can't mock `astimezone` because it's a readonly property, so have to
        // create a new class that inherits from datetime.datetime
        // class NewDateTime(datetime.datetime):
        //     def astimezone(self, _tz: (Optional[Any]) = None) -> NoReturn:
        //         raise ValueError("Invalid date")

        $created = new DateTimeImmutable('1965-01-01 00:00:00', new DateTimeZone('UTC'));
        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::created', fn($self) => $created);

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
                '--file-match-policy',
                'name-id7',
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
            'Downloading '.$this->path_join($data_dir, $this->normpath('1965/01/01/IMG_7409_QVk2Yyt.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_missing_item_type_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
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
                '--file-match-policy',
                'name-id7',
                '--skip-live-photos',
            ],
        );

        $this->assertSame(0, $result->exit_code);
    }

    public function test_missing_item_type_value_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
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
                '--file-match-policy',
                'name-id7',
                '--skip-live-photos',
            ],
        );

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_and_dedupe_existing_photos_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 1],
            ['2018/07/30', 'IMG_7408_QVI4T2l.MOV', 1],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 1],
            ['2018/07/30', 'IMG_7407_QVovd0F.MOV', 1],
        ];

        $files_to_download = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
            ['2018/07/31', 'IMG_7409_QVk2Yyt.MOV'],
        ];

        // Download the first photo, but mock the video download
        $method = 'PyicloudIpd\Services\Photos\PhotoAsset::download';
        $already_downloaded = false; // setattr(PhotoAsset, "already_downloaded", True)
        $mocked_download = function ($self, $session, string $_url, int $start) use ($method, &$already_downloaded) {
            if (!$already_downloaded) {
                $response = $this->unpatched($method, fn() => $self->download($session, $_url, $start));
                $already_downloaded = true;
                return $response;
            }
            return $this->magic_response();
        };

        Patch::set($method, $mocked_download);
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 5 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'deduplicated',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7405_QVkrUjN.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7404_QVI5TWx.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_photos_and_set_exif_exceptions_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG']];

        // mock.patch.object(piexif, "insert") with side_effect InvalidImageDataError
        Patch::set('Icloudpd\ExifDatetime::piexif_insert', function (...$_args): never {
            throw new InvalidImageDataError();
        });
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn($logger, $path) => false);
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        // 2018:07:31 07:22:24 utc
        $expectedDatetime = $this->expected_datetime();
        $this->assertStringContainsString(
            'Setting EXIF timestamp for '.$this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')).": {$expectedDatetime}",
            $result->output,
        );
        $this->assertStringContainsString(
            'Error setting EXIF data for '.$this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')),
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_chinese_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__, '中文');

        $files_to_download = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG']];

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->mtime_utc(
            $this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')),
        );
        $this->assertEqual('2018-07-31 07:22:24', $photo_mtime);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_one_recent_live_photo_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
            ['2018/07/31', 'IMG_7409_QVk2Yyt.MOV'],
        ];

        // Download the first photo, but mock the video download
        $method = 'PyicloudIpd\Services\Photos\PhotoAsset::download';
        $already_downloaded = false; // setattr(PhotoAsset, "already_downloaded", True)
        $mocked_download = function ($pa, $session, string $_url, int $start) use ($method, &$already_downloaded) {
            if (!$already_downloaded) {
                $response = $this->unpatched($method, fn() => $pa->download($session, $_url, $start));
                $already_downloaded = true;
                return $response;
            }
            return $this->magic_response();
        };

        Patch::set($method, $mocked_download);
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn($logger, $path) => false);
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
                '--file-match-policy',
                'name-id7',
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

    public function test_download_one_recent_live_photo_chinese_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_中文_7409_QVk2Yyt.JPG'], // SU1HX+S4reaWh183NDA5LkpQRw==
            ['2018/07/31', 'IMG_中文_7409_QVk2Yyt.MOV'],
        ];

        // Download the first photo, but mock the video download
        $method = 'PyicloudIpd\Services\Photos\PhotoAsset::download';
        $already_downloaded = false; // setattr(PhotoAsset, "already_downloaded", True)
        $mocked_download = function ($pa, $session, string $_url, int $start) use ($method, &$already_downloaded) {
            if (!$already_downloaded) {
                $response = $this->unpatched($method, fn() => $pa->download($session, $_url, $start));
                $already_downloaded = true;
                return $response;
            }
            return $this->magic_response();
        };

        Patch::set($method, $mocked_download);
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn($logger, $path) => false);
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
                '--file-match-policy',
                'name-id7',
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

    public function test_download_and_delete_after_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG']];

        // mock.patch.object(piexif, "insert") with side_effect InvalidImageDataError
        Patch::set('Icloudpd\ExifDatetime::piexif_insert', function (...$_args): never {
            throw new InvalidImageDataError();
        });
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn($logger, $path) => false);
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
                '--file-match-policy',
                'name-id7',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('Deleted IMG_7409_QVk2Yyt.JPG in iCloud', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TODO assert cass.all_played
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_and_not_delete_after_when_exists_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG', 1884695]];

        // mock.patch.object(piexif, "insert") with side_effect InvalidImageDataError
        Patch::set('Icloudpd\ExifDatetime::piexif_insert', function (...$_args): never {
            throw new InvalidImageDataError();
        });
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn($logger, $path) => false);
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
                '--file-match-policy',
                'name-id7',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString('Deleted IMG_7409_QVk2Yyt.JPG in iCloud', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TODO assert cass.all_played
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_and_delete_after_fail_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

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
                '--file-match-policy',
                'name-id7',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString('Deleted IMG_7409_QVk2Yyt.JPG in iCloud', $result->output);
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TODO assert cass.all_played
        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_over_old_original_photos_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l-original.JPG', 1151066],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 656257],
        ];

        $files_to_download = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG']];

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 5 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7405_QVkrUjN.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7404_QVI5TWx.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->mtime_utc(
            $this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')),
        );
        $this->assertEqual('2018-07-31 07:22:24', $photo_mtime);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_normalized_names_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 1151066],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 656257],
        ];

        $files_to_download = [
            // <>:"/\|?*  -- windows
            // / & \0x00 -- linux
            // SU1HXzc0MDkuSlBH -> i/n v:a\0l*i?d\p<a>t"h|.JPG -> aS9uIHY6YQBsKmk/ZFxwPGE+dCJofC5KUEc=
            ['2018/07/31', 'i_n v_a_l_i_d_p_a_t_h__QVk2Yyt.JPG'],
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertSame(0, $result->exit_code);
    }

    public function test_handle_internal_error_during_download_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function ($_arg, $_session, $_size) {
            throw new PyiCloudAPIResponseException('INTERNAL_ERROR', 'INTERNAL_ERROR');
        };

        $sleep_count = 0;
        Patch::set('Foundation\Time::sleep', function ($seconds) use (&$sleep_count) {
            $sleep_count++;
        });
        // mock.patch.object(PhotoAsset, "download") is a MagicMock: no self, only (session, url, start)
        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::download', function ($self, ...$args) use ($mock_raise_response_error) {
            return $mock_raise_response_error(...$args);
        });

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertEqual(
            substr_count($result->output, 'Error downloading'),
            Constants::MAX_RETRIES,
            'retry count',
        );

        $this->assertStringContainsString(
            'Could not download IMG_7409_QVk2Yyt.JPG. Please try again later.',
            $result->output,
        );

        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertEqual($sleep_count, Constants::MAX_RETRIES, 'sleep count');
        $this->assertEqual($result->exit_code, 0, 'Exit Code');
    }

    public function test_handle_internal_error_during_photo_iteration_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $mock_raise_response_error = function () {
            throw new PyiCloudAPIResponseException('Internal Error at Apple.', 'INTERNAL_ERROR');
        };

        $sleep_count = 0;
        Patch::set('Foundation\Time::sleep', function ($seconds) use (&$sleep_count) {
            $sleep_count++;
        });
        // mock.patch.object(PhotoAlbum, "photos_request") is a MagicMock: no self
        Patch::set('PyicloudIpd\Services\Photos\PhotoAlbum::photos_request', function ($self, ...$args) use ($mock_raise_response_error) {
            return $mock_raise_response_error(...$args);
        });

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        // Error msg should be repeated 5 times
        $this->assertEqual(
            substr_count($result->output, 'Internal Error at Apple, retrying...'),
            Constants::MAX_RETRIES,
            'retry count',
        );

        $this->assertStringContainsString(
            'Internal Error at Apple.',
            $result->output,
        );

        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertEqual($sleep_count, Constants::MAX_RETRIES, 'sleep count');

        $this->assertEqual($result->exit_code, 1, 'Exit Code');
    }

    public function test_handle_io_error_mkdir_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);
        $method = 'Foundation\Os::makedirs';
        // Raise IOError when we try to write to the destination file
        $my_makedirs = function (string $name, bool $exist_ok = false, int $mode = 0777) use ($method, $base_dir) {
            if (strcmp($name, Helpers::calc_data_dir($base_dir)) > 0) {
                throw new OSError();
            }
            $this->unpatched($method, fn() => Os::makedirs($name, $exist_ok, $mode));
        };
        Patch::set($method, $my_makedirs);
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
                '--file-match-policy',
                'name-id7',
            ],
        );
        $this->assertEqual($result->exit_code, 0, 'Exit code');
    }

    public function test_dry_run_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        // self.assertIn(
        //     f"Downloading 2 original photos to {data_dir} ...",
        //     result.output,
        // )
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringNotContainsString(
            'ERROR',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_after_delete_dry_run_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $raise_response_error = function ($a0_, $a1_, $a2_) {
            throw new \Exception('Unexpected call to delete_photo');
        };

        // mock.patch.object(piexif, "insert") with side_effect InvalidImageDataError
        Patch::set('Icloudpd\ExifDatetime::piexif_insert', function (...$_args): never {
            throw new InvalidImageDataError();
        });
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn($logger, $path) => false);
        Patch::set('Icloudpd\Base::delete_photo', $raise_response_error);

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
                '--file-match-policy',
                'name-id7',
                '--delete-after-download',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            '[DRY RUN] Would delete IMG_7409_QVk2Yyt.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        // TDOO self.assertEqual(
        //     cass.all_played, False, "All mocks played")
        $this->assertEqual($result->exit_code, 0, 'Exit code');
    }

    public function test_download_raw_photos_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.DNG'], // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_two_sizes_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);
        $files_to_download = [
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
            ['2018/07/31', 'IMG_7409_QVk2Yyt-thumb.JPG'],
        ];

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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original,thumb photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_raw_alt_photos_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            [
                '2018/07/31',
                'IMG_7409_QVk2Yyt.CR2',
            ], // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original,alternative photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    /** raw+jpeg does not have adj and we do not need raw, just jpeg (orig) */
    public function test_download_raw_photos_policy_alt_with_adj_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            // '2018/07/31/IMG_7409_QVk2Yyt.CR2', # SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first adjusted photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_raw_photos_policy_orig_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            [
                '2018/07/31',
                'IMG_7409_QVk2Yyt.CR2',
            ], // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            // '2018/07/31/IMG_7409_QVk2Yyt.JPG'
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_raw_photos_policy_as_is_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            [
                '2018/07/31',
                'IMG_7409_QVk2Yyt.CR2',
            ], // SU1HXzc0MDkuSlBH -> SU1HXzc0MDkuRE5H -> SU1HXzc0MDkuQ1Iy
            // '2018/07/31/IMG_7409_QVk2Yyt.JPG'
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'IMG_7409_QVk2Yyt.MOV',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        $this->assertSame(0, $result->exit_code);
    }

    public function test_download_bad_filename_base64_encoding_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 1151066],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 656257],
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertInstanceOf(\ValueError::class, $result->exception);
        // ValueError("Invalid Input: 'aS9uIHY6YQBsKmk/ZFxwPGE+dCJofC5KUE'")
    }

    public function test_download_bad_filename_utf8_encoding_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 1151066],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 656257],
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertInstanceOf(\ValueError::class, $result->exception);
        // self.assertEqual(result.exception, ValueError("Invalid Input: b'i\\xb7\\x1dy\\xf8!'"))
    }

    public function test_download_filename_string_encoding_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/30', 'IMG_7408_QVI4T2l.JPG', 1151066],
            ['2018/07/30', 'IMG_7407_QVovd0F.JPG', 656257],
        ];

        $files_to_download = [
            // SU1HXzc0MDkuSlBH -> IMG_7409.JPG
            ['2018/07/31', 'IMG_7409_QVk2Yyt.JPG'],
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
                '--file-match-policy',
                'name-id7',
            ],
        );
        Helpers::print_result_exception($result);

        $this->assertEqual($result->exit_code, 0);
    }

    public function test_download_and_skip_old_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            // ("2018/07/30", "IMG_7408.JPG", 1151066),
            // ("2018/07/30", "IMG_7407.JPG", 656257),
        ];

        $files_to_download = [['2018/07/31', 'IMG_7409_QVk2Yyt.JPG']];

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
                '--file-match-policy',
                'name-id7',
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
            $file_path = $this->normpath($this->path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                'Downloading '.$this->path_join($data_dir, $file_path),
                $result->output,
            );
        }
        $this->assertStringNotContainsString(
            'IMG_7409.MOV',
            $result->output,
        );
        foreach (array_map(fn($f) => [$f[0], $f[1]], $files_to_create) as [$dir_name, $file_name]) {
            $file_path = $this->normpath($this->path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                $this->path_join($data_dir, $file_path).' already exists',
                $result->output,
            );
        }

        $this->assertStringContainsString(
            'Skipping IMG_7405_QVkrUjN.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7404_QVI5TWx.MOV, only downloading photos.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7407_QVovd0F.JPG, as it was created 2018-07-30 11:44:05.108000+00:00, before 2018-07-31 00:00:00+00:00.',
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping IMG_7408_QVI4T2l.JPG, as it was created 2018-07-30 11:44:10.176000+00:00, before 2018-07-31 00:00:00+00:00.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);

        // Check that file was downloaded
        // Check that mtime was updated to the photo creation date
        $photo_mtime = $this->mtime_utc(
            $this->path_join($data_dir, $this->normpath('2018/07/31/IMG_7409_QVk2Yyt.JPG')),
        );
        $this->assertEqual('2018-07-31 07:22:24', $photo_mtime);
    }

    public function test_download_and_skip_new_name_id7(): void
    {
        $base_dir = $this->path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            // ("2018/07/30", "IMG_7408.JPG", 1151066),
            // ("2018/07/30", "IMG_7407.JPG", 656257),
        ];

        $files_to_download = [
            // ("2018/07/31", "IMG_7409_QVk2Yyt.JPG")
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
                '--file-match-policy',
                'name-id7',
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
            $file_path = $this->normpath($this->path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                'Downloading '.$this->path_join($data_dir, $file_path),
                $result->output,
            );
        }
        foreach (array_map(fn($f) => [$f[0], $f[1]], $files_to_create) as [$dir_name, $file_name]) {
            $file_path = $this->normpath($this->path_join($dir_name, $file_name));
            $this->assertStringContainsString(
                $this->path_join($data_dir, $file_path).' already exists',
                $result->output,
            );
        }

        $this->assertStringContainsString(
            'Skipping IMG_7409_QVk2Yyt.JPG, as it was created 2018-07-31 07:22:24.816000+00:00, after 2018-07-31 00:00:00+00:00.',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
    }
}
