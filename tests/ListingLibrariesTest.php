<?php

namespace Tests;

use Foundation\Os;

class ListingLibrariesTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    public function test_listing_library(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);
        [, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
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
                '--list-libraries',
                '--no-progress-bar',
            ],
        );
        $this->assertSame(0, $result->exit_code, 'exit code');
        $albums = Helpers::splitlines($result->output);

        $this->assertContains('PrimarySync', $albums);
        $this->assertContains('SharedSync-00000000-1111-2222-3333-444444444444', $albums);
    }

    public function test_listing_library_error(): void
    {
        $base_dir = Os::path_join($this->fixtures_path, __FUNCTION__);

        [, $result] = Helpers::run_icloudpd_test(
            fn($a, $b, $msg = '') => $this->assertSame($b, $a, $msg),
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
                '--library',
                'doesnotexist',
                '--no-progress-bar',
            ],
        );
        $this->assertSame(1, $result->exit_code, 'exit code');
        $this->assertStringContainsString(
            'Unknown library: doesnotexist',
            $result->output,
        );
    }
}
