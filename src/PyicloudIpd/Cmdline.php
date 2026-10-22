<?php

namespace PyicloudIpd;

use Foundation\Argparse\ArgumentParser;
use Foundation\Foundation;
use Foundation\Predicates;
use Foundation\Py;
use Foundation\StringUtils;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudFailedLoginException;

/**
 * A Command Line Wrapper to allow easy use of pyicloud for
 * command line scripts, and related.
 *
 * Module `pyicloud_ipd/cmdline.py`, the `icloud` script (`bin/icloud`).
 */
final class Cmdline
{
    /**
     * `input(prompt)`: the prompt on stdout, one line from stdin without its newline.
     */
    private static function input(string $prompt = ''): string
    {
        echo $prompt;
        $line = fgets(STDIN);
        if ($line === false) {
            throw new \RuntimeException('EOF when reading a line'); // EOFError
        }

        return preg_replace('/\r?\n\z/', '', $line);
    }

    /**
     * `getpass.getpass(prompt)`: the prompt and the line are on the controlling terminal (`/dev/tty`), without echo.
     *
     * ponytail: no termios in PHP, `stty -echo` on the terminal; without a terminal the line is read from stdin
     * as is (Python does the same after warning that it cannot control echo).
     */
    private static function getpass(string $prompt): string
    {
        $tty = @fopen('/dev/tty', 'r+');
        if ($tty === false) {
            fwrite(STDERR, "Warning: Can not control echo on the terminal.\n");
            fwrite(STDERR, $prompt);
            $input = STDIN;
        } else {
            fwrite($tty, $prompt);
            shell_exec('stty -echo < /dev/tty 2>/dev/null');
            $input = $tty;
        }
        try {
            $line = fgets($input);
        } finally {
            if ($tty !== false) {
                shell_exec('stty echo < /dev/tty 2>/dev/null');
                fwrite($tty, "\n");
                fclose($tty);
            }
        }
        if ($line === false) {
            throw new \RuntimeException('EOF when reading a line'); // EOFError
        }

        return preg_replace('/\r?\n\z/', '', $line);
    }

    /**
     * Main commandline entrypoint (the `sys.exit` code is returned).
     *
     * @param ?list<string> $args
     */
    public static function main(?array $args = null): int
    {
        if ($args === null) {
            $args = array_slice($_SERVER['argv'] ?? [], 1);
        }

        $parser = new ArgumentParser(description: 'Find My iPhone CommandLine Tool');

        $parser->add_argument(
            '--username',
            action: 'store',
            dest: 'username',
            default: '',
            help: 'Apple ID to Use',
        );
        $parser->add_argument(
            '--password',
            action: 'store',
            dest: 'password',
            default: '',
            help: 'Apple ID Password to Use; if unspecified, password will be '
                .'fetched from the system keyring.',
        );
        $parser->add_argument(
            '-n',
            '--non-interactive',
            action: 'store_false',
            dest: 'interactive',
            default: true,
            help: 'Disable interactive prompts.',
        );
        $parser->add_argument(
            '--delete-from-keyring',
            action: 'store_true',
            dest: 'delete_from_keyring',
            default: false,
            help: 'Delete stored password in system keyring for this username.',
        );

        $parser->add_argument(
            '--domain',
            action: 'store',
            dest: 'domain',
            default: 'com',
            help: 'Root domain for requests to iCloud. com or cn',
        );

        $parser->add_argument(
            '--version',
            action: 'store_true',
            dest: 'version',
            help: 'Show the version, commit hash and timestamp',
        );

        $command_line = $parser->parse_args($args);

        if ($command_line->version) {
            echo Foundation::version_info_formatted()."\n";

            return 0;
        }

        $username = StringUtils::strip($command_line->username) ?: null;
        $password = StringUtils::strip($command_line->password) ?: null;
        $domain = $command_line->domain;

        if ($username !== null && $command_line->delete_from_keyring) {
            Utils::delete_password_in_keyring($username);
            echo "Password delete from keyring\n";
        }

        $failure_count = 0;
        while (true) {
            // Which password we use is determined by your username, so we
            // do need to check for this first and separately.
            if ($username === null) {
                $parser->error('No username supplied');
            }

            $got_from_keyring = false;

            if ($password === null) {
                $password = Utils::get_password_from_keyring($username);
                $got_from_keyring = $password !== null;
            }

            if ($password === null) {
                $password = StringUtils::strip(self::getpass("Enter iCloud password for $username: ")) ?: null;
            }

            if ($password === null) {
                $parser->error('No password supplied');
            }

            try {
                $password_provider = static fn (?string $pwd = null): ?string => $pwd ?? $password;
                $api = new PyiCloudService(
                    $domain,
                    $username,
                    $password_provider,
                    static fn ($_) => null,
                );

                if (!$got_from_keyring && $command_line->interactive) {
                    $user_response = StringUtils::strip_and_lower(self::input('Save password in keyring? [y/N] '));
                    $is_affirmative = Predicates::in_pred(['y', 'yes']);

                    if ($is_affirmative($user_response)) {
                        Utils::store_password_in_keyring($username, $password);
                    }
                }

                if ($api->requires_2fa()) {
                    echo "\nTwo-step authentication required. \nPlease enter validation code\n";

                    $code = self::input('(string) --> ');
                    if (!$api->validate_2fa_code($code)) {
                        echo "Failed to verify verification code\n";

                        return 1;
                    }

                    echo "\n";
                } elseif ($api->requires_2sa()) {
                    echo "\nTwo-step authentication required. \nYour trusted devices are:\n";

                    $devices = $api->trusted_devices();
                    foreach ($devices as $i => $device) {
                        echo sprintf(
                            "    %d: %s\n",
                            $i,
                            $device['deviceName'] ?? 'SMS to '.Py::str($device['phoneNumber'] ?? null),
                        );
                    }

                    echo "\nWhich device would you like to use?\n";
                    $device_index = self::input('(number) --> ');
                    if (!preg_match('/^\s*[-+]?\d+\s*$/', $device_index)) {
                        throw new \ValueError("invalid literal for int() with base 10: ".Py::repr($device_index));
                    }
                    $device = $devices[(int) $device_index] ?? throw new \OutOfBoundsException('list index out of range');
                    if (!$api->send_verification_code($device)) {
                        echo "Failed to send verification code\n";

                        return 1;
                    }

                    echo "\nPlease enter validation code\n";
                    $code = self::input('(string) --> ');
                    if (!$api->validate_verification_code($device, $code)) {
                        echo "Failed to verify verification code\n";

                        return 1;
                    }

                    echo "\n";
                }
                break;
            } catch (PyiCloudFailedLoginException $err) {
                // If they have a stored password; we just used it and
                // it did not work; let's delete it if there is one.
                if (Utils::password_exists_in_keyring($username)) {
                    Utils::delete_password_in_keyring($username);
                }

                $message = "Bad username or password for $username";
                $password = null;

                ++$failure_count;
                if ($failure_count >= 3) {
                    throw new \RuntimeException($message, 0, $err);
                }

                fwrite(STDERR, "$message\n");
            }
        }

        return 0;
    }
}
