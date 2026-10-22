<?php

namespace Tests;

use Foundation\Os;
use Icloudpd\Base;

class DownloadLivePhotosTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    public function test_lp_filename_generator(): void
    {
        $this->assertSame(
            'IMG_1234_HEVC.MOV',
            Base::lp_filename_concatinator('IMG_1234.HEIC'),
            'happy path HEIC',
        );
        $this->assertSame('IMG_1234.MOV', Base::lp_filename_concatinator('IMG_1234.JPG'), 'happy path JPG');
        $this->assertSame('IMG_1234', Base::lp_filename_concatinator('IMG_1234'), 'no ext');
        $this->assertSame('IMG.1234_HEVC.MOV', Base::lp_filename_concatinator('IMG.1234.HEIC'), 'dots');

        $this->assertSame('IMG_1234.MOV', Base::lp_filename_original('IMG_1234.HEIC'), 'happy path HEIC');
        $this->assertSame('IMG_1234.MOV', Base::lp_filename_original('IMG_1234.JPG'), 'happy path JPG');
    }

    public function test_skip_existing_downloads_for_live_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2020/11/04', 'IMG_0516.HEIC'],
            ['2020/11/04', 'IMG_0514.HEIC'],
            ['2020/11/04', 'IMG_0514_HEVC.MOV'],
            ['2020/11/04', 'IMG_0512.HEIC'],
            ['2020/11/04', 'IMG_0512_HEVC.MOV'],
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'download_live_photos.yml',
            [],
            $files_to_download,
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

        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_skip_existing_live_photodownloads(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2020/11/04', 'IMG_0516.HEIC', 1651485],
            ['2020/11/04', 'IMG_0514_HEVC.MOV', 3951774],
        ];

        $files_to_download = [
            ['2020/11/04', 'IMG_0514.HEIC'],
            ['2020/11/04', 'IMG_0512.HEIC'],
            ['2020/11/04', 'IMG_0512_HEVC.MOV'],
        ];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'download_live_photos.yml',
            $files_to_create,
            $files_to_download,
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

        $this->assertStringContainsString('Looking up all photos and videos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 3 original photos and videos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_skip_existing_live_photo_print_filenames(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2020/11/04', 'IMG_0516.HEIC', 1651485],
            ['2020/11/04', 'IMG_0514_HEVC.MOV', 3951774],
        ];

        // files_to_download = [
        //     ("2020/11/04","IMG_0514.HEIC"),
        //     ("2020/11/04","IMG_0512.HEIC"),
        //     ("2020/11/04","IMG_0512_HEVC.MOV")
        // ]

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'download_live_photos.yml',
            $files_to_create,
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
                '--only-print-filenames',
            ],
        );

        $filenames = Helpers::splitlines($result->output);

        // print(filenames)

        $this->assertSame(3, count($filenames));

        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2020/11/04/IMG_0514.HEIC')),
            $filenames[0],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2020/11/04/IMG_0512.HEIC')),
            $filenames[1],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2020/11/04/IMG_0512_HEVC.MOV')),
            $filenames[2],
        );

        // Double check that a mocked file does not get listed again. It's already there!
        $this->assertNotContains('2020/11/04/IMG_0514_HEVC.MOV', $filenames);

        $this->assertSame(0, $result->exit_code);
    }
}
