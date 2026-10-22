<?php

namespace Tests;

use Foundation\Argparse;
use Foundation\Logging;
use Foundation\Logging\Formatter;
use Foundation\Logging\StreamHandler;
use Foundation\Os;
use Foundation\Patch;
use Foundation\Py;
use Icloudpd\Cli;
use Icloudpd\StringHelpers;
use Tests\Helpers\TestResult;

/**
 * `tests/helpers/__init__.py`: static functions with the same names.
 *
 * ponytail: PHP cannot redirect STDERR in process: `TestResult::$stderr_bytes` only has what goes through
 * `Foundation\Argparse::stderr()` (the usage, errors and warnings of argparse). stdout is `ob_start()`
 * (`Foundation\Py::stdout()` is php://output).
 */
final class Helpers
{
    public const DEFAULT_ENV = ['CLIENT_ID' => 'DE309E26-942E-11E8-92F5-14109FE0B321'];

    public static function print_result_exception(TestResult $result): TestResult
    {
        $ex = $result->exception;
        if ($ex) {
            // traceback.print_exception
            fwrite(STDERR, (string) $ex . "\n");
        }
        return $result;
    }

    /** `os.path.relpath(os.path.dirname(file_name), "./")` */
    public static function path_from_project_root(string $file_name): string
    {
        $parent = explode('/', trim(Os::path_normpath(dirname($file_name)), '/'));
        $start = explode('/', trim(Os::path_normpath(Os::getcwd()), '/'));
        $common = 0;
        while ($common < count($parent) && $common < count($start) && $parent[$common] === $start[$common]) {
            $common++;
        }
        $rel = array_merge(array_fill(0, count($start) - $common, '..'), array_slice($parent, $common));
        return $rel ? implode('/', $rel) : '.';
    }

    /** Removes if exists and creates dir */
    public static function recreate_path(string $path_name): void
    {
        if (file_exists($path_name)) {
            self::rmtree($path_name);
        }
        mkdir($path_name, 0777, true);
    }

    /** `shutil.rmtree` */
    public static function rmtree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::rmtree("{$path}/{$name}");
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    /** `shutil.copytree(src, dst)`: `dst` must not exist */
    public static function copytree(string $src, string $dst): void
    {
        if (file_exists($dst)) {
            throw new \Foundation\FileExistsError('[Errno 17] File exists: ' . Py::repr($dst), 17);
        }
        mkdir($dst, 0777, true);
        foreach (scandir($src) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_dir("{$src}/{$name}")) {
                self::copytree("{$src}/{$name}", "{$dst}/{$name}");
            } else {
                copy("{$src}/{$name}", "{$dst}/{$name}");
            }
        }
    }

    /**
     * `glob.glob(os.path.join(data_dir, "**\/*.*"), recursive=True)`: the files and directories whose
     * name contains a dot, in the directory and below it, hidden ones left out
     *
     * @return list<string>
     */
    public static function glob_all_files(string $data_dir): array
    {
        $result = [];
        $walk = function (string $dir) use (&$walk, &$result): void {
            foreach (scandir($dir) as $name) {
                if ($name[0] === '.') {
                    continue;
                }
                $path = Os::path_join($dir, $name);
                if (str_contains($name, '.')) {
                    $result[] = $path;
                }
                if (is_dir($path)) {
                    $walk($path);
                }
            }
        };
        if (is_dir($data_dir)) {
            $walk($data_dir);
        }
        return $result;
    }

    /**
     * `str.splitlines()`
     *
     * @return list<string>
     */
    public static function splitlines(string $text): array
    {
        return StringHelpers::splitlines($text);
    }

    /** @param iterable<array{string, string, int}> $files_to_create */
    public static function create_files(string $data_dir, iterable $files_to_create): void
    {
        foreach ($files_to_create as [$dir_name, $file_name, $file_size]) {
            $normalized_dir_name = Os::path_normpath($dir_name);
            $dir = Os::path_join($data_dir, $normalized_dir_name);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $f = fopen(Os::path_join($dir, $file_name), 'a');
            ftruncate($f, $file_size);
            fclose($f);
        }
    }

    /**
     * @param list<array{string, string, int}> $files_to_create
     * @param list<array{string, string}> $files_to_download
     * @return list<array{string, string}>
     */
    public static function combine_file_lists(array $files_to_create, array $files_to_download): array
    {
        return array_merge(
            array_map(fn($f) => [$f[0], $f[1]], $files_to_create),
            $files_to_download,
        );
    }

    /**
     * @param callable(mixed, mixed, string): void $assert_equal
     * @param list<array{string, string}> $files_to_assert
     */
    public static function assert_files(callable $assert_equal, string $data_dir, array $files_to_assert): void
    {
        $files_in_result = self::glob_all_files($data_dir);

        $assert_equal(count($files_in_result), count($files_to_assert), 'File count does not match');

        foreach ($files_to_assert as [$dir_name, $file_name]) {
            $normalized_dir_name = Os::path_normpath($dir_name);
            $file_path = Os::path_join($normalized_dir_name, $file_name);
            $assert_equal(
                file_exists(Os::path_join($data_dir, $file_path)),
                true,
                "File {$file_path} expected, but does not exist",
            );
        }
    }

    /**
     * Clean legacy boolean argument values from CLI parameters.
     *
     * Removes "true"/"false" values after boolean flags for backward compatibility
     * with old Click-based tests.
     *
     * @param list<string> $params
     * @return list<string>
     */
    public static function clean_boolean_args(array $params): array
    {
        // Boolean flags that don't take values - handle "true"/"false" cleanup
        $boolean_flags = [
            '--auth-only',
            '--list-albums',
            '-l',
            '--list-libraries',
            '--skip-videos',
            '--skip-live-photos',
            '--xmp-sidecar',
            '--force-size',
            '--auto-delete',
            '--set-exif-datetime',
            '--smtp-no-tls',
            '--delete-after-download',
            '--dry-run',
            '--keep-unicode-in-filenames',
            '--skip-photos',
            '--use-os-locale',
            '--only-print-filenames',
            '--no-progress-bar',
        ];

        $cleaned_args = [];
        $i = 0;
        while ($i < count($params)) {
            $arg = $params[$i];

            if (in_array($arg, $boolean_flags, true)) {
                $cleaned_args[] = $arg;
                // Skip legacy "true"/"false" values for boolean flags
                if ($i + 1 < count($params) && in_array($params[$i + 1], ['true', 'false'], true)) {
                    $i += 1;
                }
            } else {
                $cleaned_args[] = $arg;
            }
            $i += 1;
        }

        return $cleaned_args;
    }

    /**
     * Run the new argparse-based CLI with environment variables
     *
     * @param array<string, string|null> $env
     * @param list<string> $params
     * @param string|resource|null $input what the user types
     */
    public static function run_main_env(array $env, array $params, mixed $input = null): TestResult
    {
        // Set environment variables
        $original_env = [];
        foreach ($env as $key => $value) {
            $original_env[$key] = getenv($key);
            if ($value === null) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }

        $exit_code = 0;
        $exception = null;

        // Capture stdout and stderr (see the class comment)
        ob_start();
        $stderr_capture = fopen('php://memory', 'w+');
        Argparse::$stderr = $stderr_capture;

        $original_argv = $_SERVER['argv'] ?? null;
        $input_method = 'Icloudpd\Authentication::input';
        $original_input = Patch::get($input_method);
        $icloudpd_logger = null;
        $capture_handler = null;

        try {
            // Add a handler to capture icloudpd logging output
            $icloudpd_logger = Logging::getLogger('icloudpd');
            $capture_handler = new StreamHandler(Py::stdout());
            $capture_handler->setFormatter(new Formatter('%(asctime)s %(levelname)-8s %(message)s', '%Y-%m-%d %H:%M:%S'));
            $icloudpd_logger->addHandler($capture_handler);

            // Handle input if provided
            if ($input !== null) {
                $input_text = is_string($input) ? $input : (string) stream_get_contents($input);

                // Mock stdin input: the lines the user types are echoed to stdout for test compatibility
                Patch::set($input_method, function (string $prompt = '') use (&$input_text): string {
                    echo $prompt;
                    if ($input_text === '') {
                        throw new \RuntimeException('EOF when reading a line'); // EOFError
                    }
                    $newline = strpos($input_text, "\n");
                    $line = $newline === false ? $input_text : substr($input_text, 0, $newline + 1);
                    $input_text = $newline === false ? '' : substr($input_text, $newline + 1);
                    if (trim($line) !== '') {
                        // Echo the input (without newline) to stdout for test compatibility
                        echo rtrim($line);
                    }
                    return preg_replace('/\n\z/', '', $line);
                });
            }

            // Use the main CLI function which handles --help, --version, etc.
            $cleaned_params = self::clean_boolean_args($params);
            $_SERVER['argv'] = array_merge(['icloudpd'], $cleaned_params);
            $exit_code = Cli::cli();
        } catch (\Throwable $e) {
            $exception = $e;
            $exit_code = 1;
        } finally {
            $_SERVER['argv'] = $original_argv;
            if ($original_input) {
                Patch::set($input_method, $original_input);
            } else {
                Patch::clear($input_method);
            }
            // Remove the capture handler
            if ($capture_handler !== null) {
                $icloudpd_logger->removeHandler($capture_handler);
            }
            $raw_output = (string) ob_get_clean();
            Argparse::$stderr = null;
            rewind($stderr_capture);
            $stderr = (string) stream_get_contents($stderr_capture);
            fclose($stderr_capture);

            // Restore original environment
            foreach ($original_env as $key => $original_value) {
                if ($original_value === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$original_value}");
                }
            }
        }

        // Clean the output to remove log prefixes for compatibility with old tests
        $cleaned_lines = [];
        foreach (StringHelpers::splitlines($raw_output) as $line) {
            // Match pattern: YYYY-MM-DD HH:MM:SS LEVEL<spaces>
            $cleaned_lines[] = preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \w+\s+/', '', $line);
        }

        // Create both original and cleaned output
        $cleaned_output = implode("\n", $cleaned_lines);

        // For compatibility with old tests, adjust exit codes for specific error conditions
        $adjusted_exit_code = $exit_code;
        if ($exit_code == 1 && str_contains($raw_output, 'Invalid email/password combination')) {
            // Authentication failure - old tests expect exit code 2
            $adjusted_exit_code = 2;
        }

        // For compatibility, provide the cleaned output as the primary output
        // but keep raw_output available if needed
        $result = new TestResult(
            exit_code: $adjusted_exit_code,
            output: $cleaned_output,
            exception: $exception,
            stderr: $stderr,
        );
        // Add raw output as an additional attribute
        $result->raw_output = $raw_output;
        return $result;
    }

    /** @param list<string> $params */
    public static function run_main(array $params): TestResult
    {
        return self::print_result_exception(self::run_main_env(self::DEFAULT_ENV, $params));
    }

    // `calc_data_dir`, `calc_cookie_dir` and `calc_vcr_dir` are `partial_1_1(flip(os.path.join), "data")`...

    public static function calc_data_dir(string $base_dir): string
    {
        return Os::path_join($base_dir, 'data');
    }

    public static function calc_cookie_dir(string $base_dir): string
    {
        return Os::path_join($base_dir, 'cookie');
    }

    public static function calc_vcr_dir(string $root_path): string
    {
        return Os::path_join($root_path, 'vcr_cassettes');
    }
}
