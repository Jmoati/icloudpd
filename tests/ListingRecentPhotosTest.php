<?php

namespace Tests;

use Foundation\Os;
use Foundation\Patch;

class ListingRecentPhotosTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    public function test_listing_recent_photos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
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
                '5',
                '--only-print-filenames',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertSame(0, $result->exit_code, 'exit code');

        $filenames = Helpers::splitlines($result->output);

        $this->assertSame(8, count($filenames));
        $this->assertStringContainsString(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.JPG')),
            $filenames[0],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/IMG_7409.MOV')),
            $filenames[1],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7408.JPG')),
            $filenames[2],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7408.MOV')),
            $filenames[3],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7407.JPG')),
            $filenames[4],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7407.MOV')),
            $filenames[5],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7405.MOV')),
            $filenames[6],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7404.MOV')),
            $filenames[7],
        );
    }

    public function test_listing_photos_does_not_create_folders(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
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
                '5',
                '--only-print-filenames',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );
        $this->assertSame(0, $result->exit_code, 'exit code');
        // Should only be created after download, not after just --print-filenames
        $this->assertFalse(file_exists(Os::path_join($data_dir, Os::path_normpath('2018/07/31'))));
    }

    public function test_listing_recent_photos_with_missing_filenameEnc(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_missing_filenameEnc.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '5',
                '--only-print-filenames',
                '--no-progress-bar',
            ],
        );
        $this->assertSame(0, $result->exit_code, 'exit code');
        $filenames = Helpers::splitlines($result->output);

        // self.assertEqual(len(filenames), 5)
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/AY6c_BsE0jja.JPG')),
            $filenames[0],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/31/AY6c_BsE0jja.MOV')),
            $filenames[1],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7408.JPG')),
            $filenames[2],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/IMG_7408.MOV')),
            $filenames[3],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/07/30/AZ_wAGT9P6jh.JPG')),
            $filenames[4],
        );
    }

    // This was used to solve the missing filenameEnc error. I found
    // another case where it might crash. (Maybe Apple changes the downloadURL key)
    public function test_listing_recent_photos_with_missing_downloadURL(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        $mock_open_calls = [];
        $mock_json_calls = [];
        // mock.patch("icloudpd.base.open", create=True)
        Patch::set('Icloudpd\Base::open', function (...$args) use (&$mock_open_calls) {
            $mock_open_calls[] = $args;

            return fopen('php://memory', 'w');
        });
        // mock.patch.object(json, "dump")
        Patch::set('Foundation\Py::json_dump', function (...$args) use (&$mock_json_calls) {
            $mock_json_calls[] = $args;
        });

        [, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_missing_downloadUrl.yml',
            [],
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '1',
                '--only-print-filenames',
                '--no-progress-bar',
            ],
        );
        $this->assertSame(0, $result->exit_code, 'exit code');
        $this->assertSame(
            "KeyError: 'downloadURL' attribute was not found in the photo fields.\n"
            ."icloudpd has saved the photo record to: ./icloudpd-photo-error.json\n"
            ."Please create a Gist with the contents of this file: https://gist.github.com\n"
            ."Then create an issue on GitHub: https://github.com/icloud-photos-downloader/icloud_photos_downloader/issues\n"
            ."Include a link to the Gist in your issue, so that we can see what went wrong.\n",
            $result->output,
        );
        // mock_open.assert_called_once_with(file="icloudpd-photo-error.json", mode="w", encoding="utf8")
        $this->assertSame([['icloudpd-photo-error.json', 'w', 'utf8']], $mock_open_calls);
        $this->assertNotEmpty($mock_json_calls);  // mock_json.assert_called()
        $this->assertSame(7, count($mock_json_calls));
        // Check a few keys in the dict
        $first_arg = $mock_json_calls[6][0];
        $this->assertSame(
            'AY6c+BsE0jjaXx9tmVGJM1D2VcEO',
            $first_arg['master_record']['recordName'],
        );
        $this->assertSame(
            581,
            $first_arg['master_record']['fields']['resVidSmallHeight']['value'],
        );
        $this->assertSame(
            'F2A23C38-0020-42FE-A273-2923ADE3CAED',
            $first_arg['asset_record']['recordName'],
        );
        $this->assertSame(
            1533021744816,
            $first_arg['asset_record']['fields']['assetDate']['value'],
        );
    }
}
