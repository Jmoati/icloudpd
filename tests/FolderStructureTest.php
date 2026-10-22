<?php

namespace Tests;

use Foundation\Os;

class FolderStructureTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    // This is basically a copy of the listing_recent_photos test #
    /** Tests if the default directory structure is constructed correctly */
    public function test_default_folder_structure(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [];
        $files_to_download = [];

        // Note - This test uses the same cassette as test_download_photos.py
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
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
                '--only-print-filenames',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $filenames = Helpers::splitlines($result->output);

        $this->assertSame(8, count($filenames));
        $this->assertSame(
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

    public function test_folder_structure_none(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [];
        $files_to_download = [];

        // Note - This test uses the same cassette as test_download_photos.py
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
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
                '--only-print-filenames',
                '--folder-structure=none',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertSame(0, $result->exit_code);

        $filenames = Helpers::splitlines($result->output);

        $this->assertSame(8, count($filenames));
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7409.JPG')),
            $filenames[0],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7409.MOV')),
            $filenames[1],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7408.JPG')),
            $filenames[2],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7408.MOV')),
            $filenames[3],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7407.JPG')),
            $filenames[4],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7407.MOV')),
            $filenames[5],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7405.MOV')),
            $filenames[6],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('IMG_7404.MOV')),
            $filenames[7],
        );
    }

    public function test_folder_structure_de_posix(): void
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
                '--folder-structure={:%Y/%B}',
                '--no-progress-bar',
                '--use-os-locale',
            ],
            [
                'LC_ALL' => 'de_DE.UTF-8',
            ],
        );
        $this->assertSame(0, $result->exit_code, 'exit code');

        $filenames = Helpers::splitlines($result->output);

        $this->assertSame(8, count($filenames));
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7409.JPG')),
            $filenames[0],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7409.MOV')),
            $filenames[1],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7408.JPG')),
            $filenames[2],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7408.MOV')),
            $filenames[3],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7407.JPG')),
            $filenames[4],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7407.MOV')),
            $filenames[5],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7405.MOV')),
            $filenames[6],
        );
        $this->assertSame(
            Os::path_join($data_dir, Os::path_normpath('2018/Juli/IMG_7404.MOV')),
            $filenames[7],
        );
    }

    public function test_folder_structure_bad_format(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [];
        $files_to_download = [];

        // Note - This test uses the same cassette as test_download_photos.py
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
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
                '--only-print-filenames',
                '--folder-structure={%Y}{%Y}',
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        $this->assertSame(2, $result->exit_code);

        $messages = Helpers::splitlines($result->output);

        // Check if the error message about folder structure format is present
        $found_error = (bool) array_filter(
            $messages,
            fn($msg) => str_contains($msg, 'Format') && str_contains($msg, 'specified in --folder-structure is incorrect'),
        );
        $this->assertTrue($found_error);
    }
}
