<?php

namespace Tests;

use Foundation\Os;

class DownloadVideosTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    public function test_download_and_skip_existing_videos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            // ("2018/07/30", "IMG_7408.JPG", 1151066),
            // ("2018/07/30", "IMG_7407.JPG", 656257),
        ];

        $files_to_download = [['2018/07/30', 'IMG_7405.MOV']];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_videos.yml',
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '4',
                '--skip-photos',
                // "--skip-live-photos",
                '--set-exif-datetime',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $this->assertStringContainsString('Looking up all videos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 4 original videos to {$data_dir} ...",
            $result->output,
        );
        // for dir_name, file_name in files_to_download:
        //     file_path = os.path.normpath(os.path.join(dir_name, file_name))
        //     self.assertIn(
        //         f"Downloading {os.path.join(data_dir, file_path)}",
        //         result.output,
        //     )
        // for dir_name, file_name in [
        //     (dir_name, file_name) for (dir_name, file_name, _) in files_to_create
        // ]:
        //     file_path = os.path.normpath(os.path.join(dir_name, file_name))
        //     self.assertIn(
        //         f"{os.path.join(data_dir, file_path)} already exists",
        //         result.output,
        //     )

        // self.assertIn(
        //     "Skipping IMG_7405.MOV, only downloading photos.",
        //     result.output,
        // )
        // self.assertIn(
        //     "Skipping IMG_7404.MOV, only downloading photos.",
        //     result.output,
        // )
        $this->assertStringContainsString('All videos have been downloaded', $result->output);

        // # Check that file was downloaded
        // # Check that mtime was updated to the photo creation date
        // photo_mtime = os.path.getmtime(
        //     os.path.join(data_dir, os.path.normpath("2018/07/31/IMG_7409.JPG"))
        // )
        // photo_modified_time = datetime.datetime.fromtimestamp(photo_mtime, datetime.timezone.utc)
        // self.assertEqual("2018-07-31 07:22:24", photo_modified_time.strftime("%Y-%m-%d %H:%M:%S"))
    }
}
