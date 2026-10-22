<?php

namespace Tests;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Foundation\Os;
use Foundation\Patch;

class KeepIcloudModeTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    public function test_wide_range_keep_icloud_recent_days(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        // 90 days in the future
        $mock_now = new DateTimeImmutable('2018-07-31', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now->add(new DateInterval('P90D')));
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
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
                '--keep-icloud-recent-days',
                '100',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping deletion of IMG_7409.JPG as it is within the keep_icloud_recent_days period (89 days old)',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_narrow_range_keep_icloud_recent_days(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        $mock_now = new DateTimeImmutable('2018-08-01', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now);
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
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
                '--keep-icloud-recent-days',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping deletion of IMG_7409.JPG as it is within the keep_icloud_recent_days period (0 days old)',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_keep_icloud_recent_days_delete_all(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        $days_old = 10;
        $mock_now = new DateTimeImmutable('2018-07-31', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now->add(new DateInterval("P{$days_old}D")));
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
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
                '--keep-icloud-recent-days',
                '0',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7409.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_keep_icloud_recent_days_1_keeps_today(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_download = [['2018/07/31', 'IMG_7409.JPG']];

        $mock_now = new DateTimeImmutable('2018-07-31 23:59:59', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now);
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
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
                '--keep-icloud-recent-days',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Skipping deletion of IMG_7409.JPG as it is within the keep_icloud_recent_days period (0 days old)',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_keep_icloud_recent_days_delete_existing(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/31', 'IMG_7409.JPG', 1884695],
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ];

        $days_old = 10;
        $mock_now = new DateTimeImmutable('2018-07-31', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now->add(new DateInterval("P{$days_old}D")));
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
            $files_to_create,
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '3',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
                '--keep-icloud-recent-days',
                '0',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 3 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7409.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7408.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7407.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_keep_icloud_recent_days_keeps_some(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/31', 'IMG_7409.JPG', 1884695],  // 0 days old, should be kept
            ['2018/07/30', 'IMG_7408.JPG', 1151066],  // 1 days old, should be deleted
            ['2018/07/30', 'IMG_7407.JPG', 656257],  // 1 days old, should be deleted
        ];

        $days_old = 1;
        $mock_now = new DateTimeImmutable('2018-07-31', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now->add(new DateInterval("P{$days_old}D")));
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
            $files_to_create,
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '3',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
                '--keep-icloud-recent-days',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 3 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'Deleted IMG_7409.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7408.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7407.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_keep_icloud_recent_days_delete_existing_dry_run(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/31', 'IMG_7409.JPG', 1884695],
        ];

        $days_old = 10;
        $mock_now = new DateTimeImmutable('2018-07-31', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now->add(new DateInterval("P{$days_old}D")));
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
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
                '--keep-icloud-recent-days',
                '0',
                '--dry-run',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading the first original photo to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringContainsString(
            '[DRY RUN] Would delete IMG_7409.JPG in iCloud library PrimarySync',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }

    public function test_keep_icloud_recent_days_with_skip_videos(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        $files_to_create = [
            ['2018/07/31', 'IMG_7409.JPG', 1884695],  // 0 days old, should be kept
            ['2018/07/30', 'IMG_7408.JPG', 1151066],  // 1 days old, should be deleted
            ['2018/07/30', 'IMG_7407.JPG', 656257],  // 1 days old, should be deleted
        ];

        $days_old = 1;
        $mock_now = new DateTimeImmutable('2018-07-31', new DateTimeZone('UTC'));
        Patch::set('Foundation\\Clock::now', fn(...$args) => $mock_now->add(new DateInterval("P{$days_old}D")));
        [$data_dir, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
            $this->root_path,
            $base_dir,
            'listing_photos_keep_icloud_recent_days.yml',
            $files_to_create,
            [],
            [
                '--username',
                'jdoe@gmail.com',
                '--password',
                'password1',
                '--recent',
                '4',
                '--skip-videos',
                '--skip-live-photos',
                '--no-progress-bar',
                '--threads-num',
                '1',
                '--keep-icloud-recent-days',
                '1',
            ],
        );

        $this->assertStringContainsString('Looking up all photos...', $result->output);
        $this->assertStringContainsString(
            "Downloading 4 original photos to {$data_dir} ...",
            $result->output,
        );
        $this->assertStringNotContainsString(
            'Deleted IMG_7409.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7408.JPG in iCloud',
            $result->output,
        );
        $this->assertStringContainsString(
            'Deleted IMG_7407.JPG in iCloud',
            $result->output,
        );
        $this->assertStringNotContainsString(
            'Deleted IMG_7405.MOV in iCloud',
            $result->output,
        );
        $this->assertStringContainsString('All photos have been downloaded', $result->output);
        $this->assertSame(0, $result->exit_code);
    }
}
