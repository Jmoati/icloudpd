<?php

namespace Tests;

use Foundation\Os;

class DownloadLivePhotosIdTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    public function test_skip_existing_downloads_for_live_photos_name_id7(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [
            ['2020/11/04', 'IMG_0516_QVcwekp.HEIC'],
            ['2020/11/04', 'IMG_0514_QVZtSTE.HEIC'],
            ['2020/11/04', 'IMG_0514_QVZtSTE_HEVC.MOV'],
            ['2020/11/04', 'IMG_0512_QWRFR00.HEIC'],
            ['2020/11/04', 'IMG_0512_QWRFR00_HEVC.MOV'],
        ];

        [, $result] = Helpers::run_icloudpd_test(
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
                '--file-match-policy',
                'name-id7',
            ],
        );

        $this->assertStringContainsString('All photos and videos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_skip_existing_live_photodownloads_name_id7(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2020/11/04', 'IMG_0516_QVcwekp.HEIC', 1651485],
            ['2020/11/04', 'IMG_0514_QVZtSTE_HEVC.MOV', 3951774],
        ];

        $files_to_download = [
            ['2020/11/04', 'IMG_0514_QVZtSTE.HEIC'],
            ['2020/11/04', 'IMG_0512_QWRFR00.HEIC'],
            ['2020/11/04', 'IMG_0512_QWRFR00_HEVC.MOV'],
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
                '--file-match-policy',
                'name-id7',
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

    public function test_skip_existing_live_photo_print_filenames_name_id7(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2020/11/04', 'IMG_0516_QVcwekp.HEIC', 1651485],
            ['2020/11/04', 'IMG_0514_QVZtSTE_HEVC.MOV', 3951774],
        ];

        // files_to_download = [
        //     ("2020/11/04","IMG_0514_QVZtSTE.HEIC"),
        //     ("2020/11/04","IMG_0512_QWRFR00.HEIC"),
        //     ("2020/11/04","IMG_0512_QWRFR00_HEVC.MOV")
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
                '--file-match-policy',
                'name-id7',
                '--only-print-filenames',
            ],
        );

        $filenames = Helpers::splitlines($result->output);

        // print(filenames)

        $this->assertSame(3, count($filenames));

        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2020/11/04/IMG_0514_QVZtSTE.HEIC')),
            $filenames[0],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2020/11/04/IMG_0512_QWRFR00.HEIC')),
            $filenames[1],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2020/11/04/IMG_0512_QWRFR00_HEVC.MOV')),
            $filenames[2],
        );

        // Double check that a mocked file does not get listed again. It's already there!
        $this->assertNotContains('2020/11/04/IMG_0514_QVZtSTE_HEVC.MOV', $filenames);

        $this->assertSame(0, $result->exit_code);
    }
}
