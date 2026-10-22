<?php

namespace Tests;

use Foundation\Os;

class ListingAlbumsTest extends TestCase
{
    private string $root_path;
    private string $fixtures_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root_path = Helpers::path_from_project_root(__FILE__);
        $this->fixtures_path = Os::path_join($this->root_path, 'fixtures');
    }

    public function test_listing_albums(): void
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
                '--list-albums',
                '--no-progress-bar',
            ],
        );
        $albums = Helpers::splitlines($result->output);

        $this->assertContains('WhatsApp', $albums);
        $this->assertContains('Time-lapse', $albums);
        $this->assertContains('Recently Deleted', $albums);
        $this->assertContains('Favorites', $albums);

        $this->assertSame(0, $result->exit_code, 'exit code');
    }
}
