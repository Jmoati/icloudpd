<?php

namespace Tests;

use Foundation\Os;

/**
 * Test for issue #1220: --only-print-filenames downloads live photo video files during deduplication
 *
 * https://github.com/icloud-photos-downloader/icloud_photos_downloader/issues/1220
 *
 * Bug Description:
 * - When using --only-print-filenames, no files should be downloaded
 * - However, during live photo deduplication, the MOV file gets downloaded
 * - This happens because the deduplication logic changes the filename and resets file_exists to False
 * - The download then proceeds despite only_print_filenames being True
 */
class Issue1220OnlyPrintFilenamesDedupBugTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    /**
     * Test that --only-print-filenames works correctly with deduplication.
     *
     * This test reproduces issue #1220 and verifies the fix.
     */
    public function test_only_print_filenames_should_not_download_during_deduplication(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        // Create files that will trigger deduplication scenarios
        // We create files with different sizes than what the mock will return
        $files_to_create = [
            [
                Os::path_join('2018', '07', '31'),
                'IMG_7409.MOV',
                100,
            ],  // Small size to trigger deduplication
        ];

        // With --only-print-filenames, NO files should be downloaded
        $files_to_download = [];

        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'download_live_photos.yml',  // Use a VCR cassette that has live photos
            $files_to_create,
            $files_to_download,
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '3',
                '--only-print-filenames',  // This should prevent ALL downloads
                '--file-match-policy',
                'name-size-dedup-with-suffix',  // Enable deduplication
                '--no-progress-bar',
                '--threads-num',
                '1',
            ],
        );

        // The command should succeed
        $this->assertSame(0, $result->exit_code);

        // Filenames should be printed to stdout
        $filenames = Helpers::splitlines($result->output);
        $this->assertGreaterThan(0, count($filenames), 'Some filenames should be printed');

        // Check that the directory contains only the files we created (no downloads)
        $actual_files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($data_dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() || $file->isLink()) {
                $actual_files[] = substr($file->getPathname(), strlen($data_dir) + 1);
            }
        }

        // Only the file we created should exist (no downloads should have happened)
        $expected_files = [Os::path_join('2018', '07', '31', 'IMG_7409.MOV')];

        // After the fix, this should pass
        sort($actual_files);
        sort($expected_files);
        $this->assertSame(
            $expected_files,
            $actual_files,
            'Only pre-created files should exist. No files should be downloaded '
            .'when --only-print-filenames is used, even during deduplication.',
        );
    }
}
