<?php

namespace Tests;

use Foundation\Http\Request;
use Foundation\Http\Response;
use Foundation\Http\Session;
use Foundation\Logging;
use Foundation\Os;

/**
 * End-to-end run of `Icloudpd\Base` on a cassette, with the arguments of
 * `tests/test_download_photos.py::test_download_and_skip_existing_photos`.
 *
 * The expected output and the sequence of HTTP requests are what the Python tool prints and sends for the
 * same arguments on the same cassette (`TZ=Etc/UTC`): every line is logged twice there (the root handler of
 * `basicConfig` and the capture handler of the test helper), so the PHP output is compared once per line.
 */
class BaseSmokeTest extends TestCase
{
    private const HOST = 'https://p61-ckdatabasews.icloud.com:443/database/1/com.apple.photos.cloud/production/private';

    public function test_download_and_skip_existing_photos(): void
    {
        $root_path = Helpers::path_from_project_root(__FILE__);
        $base_dir = Os::path_join($root_path, 'fixtures', 'smoke_download_and_skip_existing_photos');
        $data_dir = Helpers::calc_data_dir($base_dir);
        $cookie_dir = Helpers::calc_cookie_dir($base_dir);

        Helpers::recreate_path($base_dir);
        Helpers::recreate_path($data_dir);
        Helpers::copytree(Os::path_join($root_path, 'cookie'), $cookie_dir);
        Helpers::create_files($data_dir, [
            ['2018/07/30', 'IMG_7408.JPG', 1151066],
            ['2018/07/30', 'IMG_7407.JPG', 656257],
        ]);

        // the root logger of a fresh Python process only lets WARNING through (the other tests may have changed it)
        $root_level = Logging::root()->level;
        Logging::root()->setLevel(Logging::WARNING);
        $requests = [];
        try {
            $result = Vcr::use_cassette(
                Os::path_join(Helpers::calc_vcr_dir($root_path), 'listing_photos.yml'),
                function () use (&$requests, $data_dir, $cookie_dir): Helpers\TestResult {
                    // what goes out, in order
                    $replayer = Session::$transport;
                    Session::$transport = function (Request $request) use (&$requests, $replayer): Response {
                        $requests[] = $request->method . ' ' . strtok($request->url, '?');
                        return $replayer($request);
                    };
                    return Helpers::run_main_env(
                        Helpers::DEFAULT_ENV,
                        [
                            '-d', $data_dir,
                            '--cookie-directory', $cookie_dir,
                            '--username', 'jdoe@gmail.com',
                            '--password', 'password1',
                            '--recent', '5',
                            '--skip-videos',
                            '--skip-live-photos',
                            '--set-exif-datetime',
                            '--no-progress-bar',
                            '--threads-num', '1',
                        ],
                    );
                },
            );

            $this->assertNull($result->exception);
            $this->assertSame(0, $result->exit_code, 'exit code');

            $lines = array_map(
                fn(string $line) => preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} /', '', $line),
                explode("\n", trim($result->raw_output)),
            );
            $this->assertSame([
                'INFO     Processing user: jdoe@gmail.com',
                'DEBUG    Authenticating...',
                'DEBUG    Looking up all photos...',
                "INFO     Downloading 5 original photos to {$data_dir} ...",
                "DEBUG    Downloading {$data_dir}/2018/07/31/IMG_7409.JPG...",
                "INFO     Downloaded {$data_dir}/2018/07/31/IMG_7409.JPG",
                "DEBUG    {$data_dir}/2018/07/30/IMG_7408.JPG already exists",
                "DEBUG    {$data_dir}/2018/07/30/IMG_7407.JPG already exists",
                'DEBUG    Skipping IMG_7405.MOV, only downloading photos. (Item type was: MOVIE)',
                'DEBUG    Skipping IMG_7404.MOV, only downloading photos. (Item type was: MOVIE)',
                'INFO     All photos have been downloaded',
            ], array_values(array_unique($lines)));

            $this->assertSame([
                'POST https://setup.icloud.com/setup/ws/1/validate',
                'POST ' . self::HOST . '/records/query',
                'POST ' . self::HOST . '/zones/list',
                'POST ' . self::HOST . '/records/query',
                'POST ' . self::HOST . '/internal/records/query/batch',
                'POST ' . self::HOST . '/records/query',
                // `${f}` of the recorded URL, as `requests` prepares it
                'GET https://cvws.icloud-content.com/B/AY6c-BsE0jjaXx9tmVGJM1D2VcEOAfUMnLhKAW8bwnMCwMMXLMKCslue/$%7Bf%7D',
            ], $requests, 'HTTP requests, in order');

            // the downloaded file got the creation date of the photo as its mtime
            $photo = Os::path_join($data_dir, '2018/07/31/IMG_7409.JPG');
            $this->assertFileExists($photo);
            $this->assertSame('2018-07-31 07:22:24', gmdate('Y-m-d H:i:s', filemtime($photo)));
            $this->assertFileDoesNotExist($photo . '.part');
        } finally {
            Logging::root()->setLevel($root_level);
            Helpers::rmtree($base_dir);
        }
    }
}
