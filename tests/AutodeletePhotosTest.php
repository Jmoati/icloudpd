<?php

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use Foundation\Os;
use Foundation\Patch;
use Icloudpd\Constants;
use PyicloudIpd\Exceptions\PyiCloudAPIResponseException;

class AutodeletePhotosTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;
    private string $vcr_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
        $this->vcr_path = Os::path_join($this->root_path, 'vcr_cassettes');
    }

    /**
     * f"{datetime.datetime.fromtimestamp(ms / 1000.0, tz=pytz.utc).astimezone(get_localzone()):%Y/%m/%d}"
     */
    private static function local_date_path(float $ms): string
    {
        return (new DateTimeImmutable('@'.(int) floor($ms / 1000.0)))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y/m/d');
    }

    public function test_autodelete_invalid_creation_date(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files = ['2018/01/01/IMG_3589.JPG'];

        // Can't mock `astimezone` because it's a readonly property, so have to
        // create a new class that inherits from datetime.datetime
        // class NewDateTime(datetime.datetime):
        //     def astimezone(self, _tz: (Any | None) = None) -> NoReturn:
        //         raise ValueError("Invalid date")
        $new_date_time = new class ('2018-01-01 00:00:00', new DateTimeZone('UTC')) extends DateTimeImmutable {
            public function setTimezone(DateTimeZone $timezone): static
            {
                throw new \ValueError('Invalid date');
            }
        };
        // mock.patch.object(PhotoAsset, "created", new_callable=mock.PropertyMock)
        Patch::set('PyicloudIpd\Services\Photos\PhotoAsset::created', fn(...$args) => $new_date_time);

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos_part1.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--delete-after-download',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            //                   f"Downloading the first original photo or video to {data_dir} ...",
            'Downloading the first original photo or video',
            $result->output,
        );
        $this->assertStringContainsString(
            'Could not convert photo created date to local timezone (2018-01-01 00:00:00)',
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloaded '.Os::path_join($data_dir, Os::path_normpath('2018/01/01/IMG_3589.JPG')),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_3589.JPG',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);

        // check files
        foreach ($files as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos_part2.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '0',
                '--auto-delete',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading 0 original photos and videos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertStringContainsString(
            "Deleting any files found in 'Recently Deleted'...",
            $result->output,
        );

        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath('2018/01/01/IMG_3589.JPG')),
            $result->output,
        );

        foreach ($files as $file_name) {
            $this->assertFalse(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} not expected, but present",
            );
        }
    }

    public function test_download_autodelete_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files = [
            self::local_date_path(1686106167436.0).'/IMG_3589.JPG',
        ];

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos_part1.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--delete-after-download',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            //                f"Downloading the first original photo or video to {data_dir} ...",
            'Downloading the first original photo or video',
            $result->output,
        );
        $this->assertStringContainsString(
            'Downloading '.Os::path_join($data_dir, Os::path_normpath($files[0])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_3589.JPG',
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);

        // check files
        foreach ($files as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos_part2.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '0',
                '--auto-delete',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );

        $this->assertStringContainsString(
            'Looking up all photos and videos...',
            $result->output,
        );
        $this->assertStringContainsString(
            "Downloading 0 original photos and videos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertStringContainsString(
            "Deleting any files found in 'Recently Deleted'...",
            $result->output,
        );

        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files[0])),
            $result->output,
        );

        foreach ($files as $file_name) {
            $this->assertFalse(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} not expected, but present",
            );
        }
    }

    public function test_autodelete_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files_to_create = ['2018/07/30/IMG_7407.JPG', '2018/07/30/IMG_7407-original.JPG'];

        $files_to_delete = [
            self::local_date_path(1532940539000.0).'/IMG_7406.MOV',
            self::local_date_path(1532618424000.0).'/IMG_7383.PNG',
            self::local_date_path(1531371164630.0).'/IMG_7190.JPG',
            self::local_date_path(1531371164630.0).'/IMG_7190-medium.JPG',
        ];

        mkdir(Os::path_join($data_dir, self::local_date_path(1532940539000.0).'/'), 0777, true);
        mkdir(Os::path_join($data_dir, self::local_date_path(1532618424000.0).'/'), 0777, true);
        mkdir(Os::path_join($data_dir, self::local_date_path(1531371164630.0).'/'), 0777, true);

        // create some empty files
        foreach (array_merge($files_to_create, $files_to_delete) as $file_name) {
            fclose(fopen(Os::path_join($data_dir, $file_name), 'a'));
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'autodelete_photos.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '0',
                '--skip-videos',
                '--auto-delete',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );
        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 0 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertStringContainsString(
            "Deleting any files found in 'Recently Deleted'...",
            $result->output,
        );

        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[0])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[1])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[2])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[3])),
            $result->output,
        );

        $this->assertStringNotContainsString('IMG_7407.JPG', $result->output);
        $this->assertStringNotContainsString('IMG_7407-original.JPG', $result->output);

        $this->assertSame(0, $result->exit_code, 'Exit code');

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(count($files_to_create), count($files_in_result));

        // check files
        foreach ($files_to_create as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        foreach ($files_to_delete as $file_name) {
            $this->assertFalse(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} not expected, but present",
            );
        }
    }

    public function test_retry_delete_after_download_session_error(): void
    {
        if (Constants::$MAX_RETRIES === 0) {
            $this->markTestSkipped('Disabled when MAX_RETRIES set to 0');
        }

        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files = [
            self::local_date_path(1686106167436.0).'/IMG_3589.JPG',
        ];

        $already_raised_session_exception = false;
        $pa_delete_call_count = 0;
        $mock_raise_response_error = function ($a1_, $a2_, $a3_, $a4_) use (&$already_raised_session_exception, &$pa_delete_call_count) {
            $pa_delete_call_count++;
            if (!$already_raised_session_exception) {
                $already_raised_session_exception = true;
                throw new PyiCloudAPIResponseException('Invalid global session', '100');
            }
        };

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $authenticate_key = 'PyicloudIpd\Base\PyiCloudService::authenticate';
        $already_authenticated = new \WeakMap();
        $mocked_authenticate = function ($self) use (&$mocked_authenticate, $authenticate_key, $already_authenticated) {
            if (!isset($already_authenticated[$self])) {
                // orig_authenticate(self)
                Patch::clear($authenticate_key);
                try {
                    $self->authenticate();
                } finally {
                    Patch::set($authenticate_key, $mocked_authenticate);
                }
                $already_authenticated[$self] = true;
            }
        };

        $sleep_call_count = 0;
        Patch::set('Foundation\Time::sleep', function (...$args) use (&$sleep_call_count) {
            $sleep_call_count++;
        });
        Patch::set('Icloudpd\Base::delete_photo', $mock_raise_response_error);
        Patch::set($authenticate_key, $mocked_authenticate);

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--delete-after-download',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
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
            'Downloading '.Os::path_join($data_dir, Os::path_normpath($files[0])),
            $result->output,
        );

        // Error msg should be repeated always 1 time
        $this->assertSame(
            1,
            substr_count($result->output, 'Session error, re-authenticating...'),
            'retry count',
        );

        $this->assertSame(
            1 + min(1, Constants::$MAX_RETRIES),
            $pa_delete_call_count,
            'delete call count',
        );
        // Make sure we only call sleep 0 times (skip the first retry)
        $this->assertSame(
            0,
            $sleep_call_count,
            'sleep count',
        );
        $this->assertSame(0, $result->exit_code, 'Exit code');

        // check files
        foreach ($files as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(1, count($files_in_result));
    }

    public function test_retry_fail_delete_after_download_session_error(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files = [
            self::local_date_path(1686106167436.0).'/IMG_3589.JPG',
        ];

        $pa_delete_call_count = 0;
        $mock_raise_response_error = function ($a1_, $a3_, $a4_) use (&$pa_delete_call_count) {
            $pa_delete_call_count++;
            throw new PyiCloudAPIResponseException('Invalid global session', '100');
        };

        // Let the initial authenticate() call succeed,
        // but do nothing on the second try.
        $authenticate_key = 'PyicloudIpd\Base\PyiCloudService::authenticate';
        $already_authenticated = new \WeakMap();
        $mocked_authenticate = function ($self) use (&$mocked_authenticate, $authenticate_key, $already_authenticated) {
            if (!isset($already_authenticated[$self])) {
                // orig_authenticate(self)
                Patch::clear($authenticate_key);
                try {
                    $self->authenticate();
                } finally {
                    Patch::set($authenticate_key, $mocked_authenticate);
                }
                $already_authenticated[$self] = true;
            }
        };

        $sleep_call_count = 0;
        Patch::set('Foundation\Time::sleep', function (...$args) use (&$sleep_call_count) {
            $sleep_call_count++;
        });
        Patch::set('Icloudpd\Base::delete_photo', $mock_raise_response_error);
        Patch::set($authenticate_key, $mocked_authenticate);

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos_part1.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--delete-after-download',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
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
            'Downloading '.Os::path_join($data_dir, Os::path_normpath($files[0])),
            $result->output,
        );

        // Error msg should be repeated MAX_RETRIES times
        $this->assertSame(
            max(0, Constants::$MAX_RETRIES),
            substr_count($result->output, 'Session error, re-authenticating...'),
            'retry count',
        );

        $this->assertSame(
            Constants::$MAX_RETRIES + 1,
            $pa_delete_call_count,
            'delete call count',
        );
        // Make sure we only call sleep MAX_RETRIES-1 times (skip the first retry)
        $this->assertSame(
            max(0, Constants::$MAX_RETRIES - 1),
            $sleep_call_count,
            'sleep count',
        );
        $this->assertSame(1, $result->exit_code, 'Exit code');

        // check files
        foreach ($files as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(1, count($files_in_result));
    }

    public function test_retry_delete_after_download_internal_error(): void
    {
        if (Constants::$MAX_RETRIES === 0) {
            $this->markTestSkipped('Disabled when MAX_RETRIES set to 0');
        }

        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files = [
            self::local_date_path(1686106167436.0).'/IMG_3589.JPG',
        ];

        $already_raised_session_exception = false;
        $pa_delete_call_count = 0;
        $mock_raise_response_error = function ($a1_, $a2_, $a3_, $a4_) use (&$already_raised_session_exception, &$pa_delete_call_count) {
            $pa_delete_call_count++;
            if (!$already_raised_session_exception) {
                $already_raised_session_exception = true;
                throw new PyiCloudAPIResponseException('INTERNAL_ERROR', 'INTERNAL_ERROR');
            }
        };

        $sleep_call_count = 0;
        Patch::set('Foundation\Time::sleep', function (...$args) use (&$sleep_call_count) {
            $sleep_call_count++;
        });
        Patch::set('Icloudpd\Base::delete_photo', $mock_raise_response_error);

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos_part1.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--delete-after-download',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
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
            'Downloading '.Os::path_join($data_dir, Os::path_normpath($files[0])),
            $result->output,
        );

        // Error msg should be repeated MAX_RETRIES times
        $this->assertSame(
            min(1, Constants::$MAX_RETRIES),
            substr_count($result->output, 'Internal Error at Apple, retrying...'),
            'retry count',
        );

        $this->assertSame(
            1 + min(1, Constants::$MAX_RETRIES),
            $pa_delete_call_count,
            'delete count',
        );
        // Make sure we only call sleep 4 times (skip the first retry)
        $this->assertSame(
            min(1, Constants::$MAX_RETRIES),
            $sleep_call_count,
            'sleep count',
        );
        $this->assertSame(0, $result->exit_code, 'Exit code');

        // check files
        foreach ($files as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(1, count($files_in_result));
    }

    public function test_retry_fail_delete_after_download_internal_error(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files = [
            self::local_date_path(1686106167436.0).'/IMG_3589.JPG',
        ];

        $pa_delete_call_count = 0;
        $mock_raise_response_error = function ($a1_, $a2_, $a3_, $a4_) use (&$pa_delete_call_count) {
            $pa_delete_call_count++;
            throw new PyiCloudAPIResponseException('INTERNAL_ERROR', 'INTERNAL_ERROR');
        };

        $sleep_call_count = 0;
        Patch::set('Foundation\Time::sleep', function (...$args) use (&$sleep_call_count) {
            $sleep_call_count++;
        });
        Patch::set('Icloudpd\Base::delete_photo', $mock_raise_response_error);

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'download_autodelete_photos_part1.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--delete-after-download',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
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
            'Downloading '.Os::path_join($data_dir, Os::path_normpath($files[0])),
            $result->output,
        );

        // Error msg should be repeated 5 times
        $this->assertSame(
            Constants::$MAX_RETRIES,
            substr_count($result->output, 'Internal Error at Apple, retrying...'),
            'retry count',
        );

        $this->assertSame(Constants::$MAX_RETRIES + 1, $pa_delete_call_count, 'delete count');
        // Make sure we only call sleep N times (skip the first retry)
        $this->assertSame(Constants::$MAX_RETRIES, $sleep_call_count, 'sleep count');
        $this->assertSame(1, $result->exit_code, 'Exit code');

        // check files
        foreach ($files as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(1, count($files_in_result));
    }

    public function test_autodelete_photos_dry_run(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files_to_create = ['2018/07/30/IMG_7407.JPG', '2018/07/30/IMG_7407-original.JPG'];

        $files_to_delete = [
            self::local_date_path(1532940539000.0).'/IMG_7406.MOV',
            self::local_date_path(1532618424000.0).'/IMG_7383.PNG',
            self::local_date_path(1531371164630.0).'/IMG_7190.JPG',
            self::local_date_path(1531371164630.0).'/IMG_7190-medium.JPG',
        ];

        mkdir(Os::path_join($data_dir, self::local_date_path(1532940539000.0).'/'), 0777, true);
        mkdir(Os::path_join($data_dir, self::local_date_path(1532618424000.0).'/'), 0777, true);
        mkdir(Os::path_join($data_dir, self::local_date_path(1531371164630.0).'/'), 0777, true);

        // create some empty files
        foreach (array_merge($files_to_create, $files_to_delete) as $file_name) {
            fclose(fopen(Os::path_join($data_dir, $file_name), 'a'));
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'autodelete_photos.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--dry-run',
                '--recent',
                '0',
                '--skip-videos',
                '--auto-delete',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 0 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertStringContainsString(
            "Deleting any files found in 'Recently Deleted'...",
            $result->output,
        );

        $this->assertStringContainsString(
            '[DRY RUN] Would delete '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[0])),
            $result->output,
        );
        $this->assertStringContainsString(
            '[DRY RUN] Would delete '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[1])),
            $result->output,
        );
        $this->assertStringContainsString(
            '[DRY RUN] Would delete '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[2])),
            $result->output,
        );
        $this->assertStringContainsString(
            '[DRY RUN] Would delete '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[3])),
            $result->output,
        );

        $this->assertStringNotContainsString('IMG_7407.JPG', $result->output);
        $this->assertStringNotContainsString('IMG_7407-original.JPG', $result->output);

        $this->assertSame(0, $result->exit_code, 'Exit code');

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(
            count($files_to_create) + count($files_to_delete),
            count($files_in_result),
            'Files in the result',
        );

        // check files
        foreach ($files_to_create as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        foreach ($files_to_delete as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected to stay, but missing",
            );
        }
    }

    public function test_autodelete_photos_folder_none(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files_to_create = ['IMG_7407.JPG', 'IMG_7407-original.JPG'];

        $files_to_delete = [
            'IMG_7406.MOV',
            'IMG_7383.PNG',
            'IMG_7190.JPG',
            'IMG_7190-medium.JPG',
        ];

        // create some empty files
        foreach (array_merge($files_to_create, $files_to_delete) as $file_name) {
            fclose(fopen(Os::path_join($data_dir, $file_name), 'a'));
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'autodelete_photos.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '0',
                '--skip-videos',
                '--auto-delete',
                '--folder-structure',
                'none',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );
        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 0 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertStringContainsString(
            "Deleting any files found in 'Recently Deleted'...",
            $result->output,
        );

        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[0])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[1])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[2])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[3])),
            $result->output,
        );

        $this->assertStringNotContainsString('IMG_7407.JPG', $result->output);
        $this->assertStringNotContainsString('IMG_7407-original.JPG', $result->output);

        $this->assertSame(0, $result->exit_code, 'exit code');

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(count($files_to_create), count($files_in_result));

        // check files
        foreach ($files_to_create as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        foreach ($files_to_delete as $file_name) {
            $this->assertFalse(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} not expected, but present",
            );
        }
    }

    public function test_autodelete_photos_lp(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files_to_create = ['2018/07/30/IMG_7407.JPG', '2018/07/30/IMG_7407-original.JPG'];

        $files_to_delete = [
            '2018/07/30/IMG_7406.MOV',
            '2018/07/26/IMG_7383.PNG',
            '2018/07/12/IMG_7190.JPG',
            '2018/07/12/IMG_7190-medium.JPG',
            '2018/07/12/IMG_7190.MOV',  // Live Photo for JPG
        ];

        // create some empty files
        foreach (array_merge($files_to_create, $files_to_delete) as $file_name) {
            $path_name = dirname($file_name);
            if (!is_dir(Os::path_join($data_dir, $path_name))) {
                mkdir(Os::path_join($data_dir, $path_name), 0777, true);
            }
            fclose(fopen(Os::path_join($data_dir, $file_name), 'a'));
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'autodelete_photos.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '0',
                '--skip-videos',
                '--auto-delete',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );
        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 0 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertStringContainsString(
            "Deleting any files found in 'Recently Deleted'...",
            $result->output,
        );

        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[0])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[1])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[2])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[3])),
            $result->output,
        );

        $this->assertStringNotContainsString('IMG_7407.JPG', $result->output);
        $this->assertStringNotContainsString('IMG_7407-original.JPG', $result->output);

        $this->assertSame(0, $result->exit_code, 'exit code');

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(count($files_to_create), count($files_in_result));

        // check files
        foreach ($files_to_create as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        foreach ($files_to_delete as $file_name) {
            $this->assertFalse(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} not expected, but present",
            );
        }
    }

    public function test_autodelete_photos_lp_heic(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $cookie_dir = Os::path_join($base_dir, 'cookie');
        $data_dir = Os::path_join($base_dir, 'data');
        $cookie_master_path = Os::path_join($this->root_path, 'cookie');

        foreach ([$base_dir, $data_dir] as $dir) {
            Helpers::recreate_path($dir);
        }

        Helpers::copytree($cookie_master_path, $cookie_dir);

        $files_to_create = ['2018/07/30/IMG_7407.JPG', '2018/07/30/IMG_7407-original.JPG'];

        $files_to_delete = [
            '2018/07/30/IMG_7406.MOV',
            '2018/07/26/IMG_7383.PNG',
            '2018/07/12/IMG_7190.HEIC',  // SU1HXzcxOTAuSlBH -> SU1HXzcxOTAuSEVJQw==
            '2018/07/12/IMG_7190-medium.JPG',
            '2018/07/12/IMG_7190_HEVC.MOV',  // Live Photo for HEIC
        ];

        // create some empty files
        foreach (array_merge($files_to_create, $files_to_delete) as $file_name) {
            $path_name = dirname($file_name);
            if (!is_dir(Os::path_join($data_dir, $path_name))) {
                mkdir(Os::path_join($data_dir, $path_name), 0777, true);
            }
            fclose(fopen(Os::path_join($data_dir, $file_name), 'a'));
        }

        $result = Helpers::run_cassette(
            Os::path_join($this->vcr_path, 'autodelete_photos_heic.yml'),
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '0',
                '--skip-videos',
                '--auto-delete',
                '-d',
                $data_dir,
                '--cookie-directory',
                $cookie_dir,
            ],
        );
        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 0 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertStringContainsString(
            "Deleting any files found in 'Recently Deleted'...",
            $result->output,
        );

        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[0])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[1])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[2])),
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted '.Os::path_join($data_dir, Os::path_normpath($files_to_delete[3])),
            $result->output,
        );

        $this->assertStringNotContainsString('IMG_7407.JPG', $result->output);
        $this->assertStringNotContainsString('IMG_7407-original.JPG', $result->output);

        $this->assertSame(0, $result->exit_code, 'exit code');

        $files_in_result = Helpers::glob_all_files($data_dir);

        $this->assertSame(count($files_to_create), count($files_in_result));

        // check files
        foreach ($files_to_create as $file_name) {
            $this->assertTrue(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} expected, but missing",
            );
        }

        foreach ($files_to_delete as $file_name) {
            $this->assertFalse(
                file_exists(Os::path_join($data_dir, $file_name)),
                "{$file_name} not expected, but present",
            );
        }
    }
}
