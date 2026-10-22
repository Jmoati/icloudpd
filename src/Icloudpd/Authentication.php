<?php

namespace Icloudpd;

use Foundation\Logging\Logger;
use Foundation\Patch;
use Foundation\Py;
use Foundation\StringUtils;
use Icloudpd\Authentication\SystemExit;
use Icloudpd\MfaProvider\MFAProvider;
use Icloudpd\Status\StatusExchange;
use PyicloudIpd\Base\PyiCloudService;
use PyicloudIpd\Exceptions\PyiCloudFailedMFAException;

/**
 * Handles username/password authentication and two-step authentication
 */
final class Authentication
{
    /**
     * Thin wrapper over Python's `input()`: prompt on stdout, one line from stdin without its
     * newline (patchable so that tests can supply the lines typed by the user).
     */
    public static function input(string $prompt = ""): string
    {
        if ($p = Patch::get(__METHOD__)) return $p($prompt);
        echo $prompt;
        $line = fgets(STDIN);
        if ($line === false) {
            throw new \RuntimeException("EOF when reading a line"); // EOFError
        }
        return preg_replace('/\r?\n\z/', "", $line);
    }

    /**
     * Prompt user for integer input within a range, similar to click.IntRange
     */
    public static function prompt_int_range(string $message, string $default, int $min_val, int $max_val): int
    {
        while (true) {
            try {
                $response = StringUtils::strip(self::input("{$message} [{$default}]: "));
                $response = $response !== "" ? $response : $default;
                // ponytail: int() also accepts underscores and non-ASCII digits, not here
                if (!preg_match('/^[+-]?[0-9]+$/', $response)) {
                    throw new \ValueError("invalid literal for int() with base 10: '{$response}'");
                }
                $value = (int) $response;
                if ($min_val <= $value && $value <= $max_val) {
                    return $value;
                } else {
                    echo "Invalid input: {$value} is not in the range {$min_val}-{$max_val}\n";
                }
            } catch (\ValueError) {
                echo "Invalid input: '{$response}' is not a valid integer\n";
            }
        }
    }

    /**
     * Prompt user for string input
     */
    public static function prompt_string(string $message): string
    {
        return self::input("{$message}: ");
    }

    // Pure validation functions for 2FA input

    /**
     * Check if input is empty string
     */
    public static function is_empty_string(string $input): bool
    {
        return $input == "";
    }

    /**
     * Check if input is a valid device index
     */
    public static function is_valid_device_index(string $input, int $device_count, string $alphabet): bool
    {
        $is_single_char = mb_strlen($input) == 1;
        $is_in_alphabet = str_contains($alphabet, $input);
        $is_valid_index = $is_in_alphabet ? strpos($alphabet, $input) <= $device_count - 1 : false;

        return $is_single_char && $is_in_alphabet && $is_valid_index;
    }

    /**
     * Check if input is a valid six-digit code
     */
    public static function is_valid_six_digit_code(string $input): bool
    {
        // ponytail: ASCII digits only (str.isdigit() also accepts other Unicode digits)
        return mb_strlen($input) == 6 && ctype_digit($input);
    }

    /**
     * Print message to stdout, similar to click.echo
     */
    public static function echo(string $message): void
    {
        echo $message . "\n";
    }

    /**
     * Authenticate with iCloud username and password
     *
     * @param array<string, array{0: callable(string): ?string, 1: callable(string, string): void}> $password_providers
     * @param (callable(array): void)|null $response_observer
     */
    public static function authenticator(
        Logger $logger,
        string $domain,
        array $password_providers,
        MFAProvider $mfa_provider,
        StatusExchange $status_exchange,
        string $username,
        callable $notificator,
        ?callable $response_observer = null,
        ?string $cookie_directory = null,
        ?string $client_id = null,
    ): PyiCloudService {
        $logger->debug("Authenticating...");
        $valid_password = [];

        $password_provider = function (string $username) use (&$valid_password, $password_providers): ?string {
            foreach ($password_providers as $_ => $_pair) {
                [$reader, $_] = $_pair;
                $password = $reader($username);
                if (Py::truthy($password)) {
                    $valid_password[] = $password;
                    return $password;
                }
            }
            return null;
        };

        $icloud = new PyiCloudService(
            $domain,
            $username,
            fn() => $password_provider($username),
            $response_observer,
            cookie_directory: $cookie_directory,
            client_id: $client_id,
        );

        if ($valid_password) {
            // save valid password to all providers
            foreach ($password_providers as $_ => $_pair) {
                [$_, $writer] = $_pair;
                $writer($username, $valid_password[0]);
            }
        }

        if ($icloud->requires_2fa()) {
            $logger->info("Two-factor authentication is required (2fa)");
            $notificator();
            if ($mfa_provider === MFAProvider::WEBUI) {
                self::request_2fa_web($icloud, $logger, $status_exchange);
            } else {
                self::request_2fa($icloud, $logger);
            }
        } elseif ($icloud->requires_2sa()) {
            $logger->info("Two-step authentication is required (2sa)");
            $notificator();
            self::request_2sa($icloud, $logger);
        }

        return $icloud;
    }

    /**
     * Request two-step authentication. Prompts for SMS or device
     */
    public static function request_2sa(PyiCloudService $icloud, Logger $logger): void
    {
        $devices = $icloud->trusted_devices();
        $devices_count = count($devices);
        $device_index = 0;
        if ($devices_count > 0) {
            foreach ($devices as $i => $device) {
                $number = $device["phoneNumber"];
                $alt_name = "SMS to {$number}";
                $name = $device["deviceName"] ?? $alt_name;
                self::echo("  {$i}: {$name}");
            }

            $device_index = self::prompt_int_range("Please choose an option:", "0", 0, $devices_count - 1);
        }

        $device = $devices[$device_index];
        if (!$icloud->send_verification_code($device)) {
            $logger->error("Failed to send two-step authentication code");
            throw new SystemExit(code: 1);
        }

        $code = self::prompt_string("Please enter two-step authentication code");
        if (!$icloud->validate_verification_code($device, $code)) {
            $logger->error("Failed to verify two-step authentication code");
            throw new SystemExit(code: 1);
        }
        $logger->info(
            "Great, you're all set up. The script can now be run without "
            . "user interaction until 2SA expires.\n"
            . "You can set up email notifications for when "
            . "the two-step authentication expires.\n"
            . "(Use --help to view information about SMTP options.)"
        );
    }

    /**
     * Request two-factor authentication.
     */
    public static function request_2fa(PyiCloudService $icloud, Logger $logger): void
    {
        $devices = $icloud->get_trusted_phone_numbers();

        // Trigger push notification to trusted devices before prompting for code.
        // Apple's auth flow (2026+) requires a PUT to /verify/trusteddevice/securitycode
        // to initiate code delivery. Failure is non-fatal — the user can still enter
        // a code if it arrives via another path.
        if (!$icloud->trigger_push_notification()) {
            $logger->debug("Failed to trigger 2FA push notification, continuing anyway");
        } else {
            $logger->debug("2FA push notification triggered");
        }

        $devices_count = count($devices);
        $device_index_alphabet = "abcdefghijklmnopqrstuvwxyz";
        if ($devices_count > 0) {
            if ($devices_count > strlen($device_index_alphabet)) {
                throw new PyiCloudFailedMFAException("Too many trusted devices for authentication");
            }

            foreach ($devices as $i => $device) {
                self::echo("  {$device_index_alphabet[$i]}: {$device->obfuscated_number}");
            }

            $index_str = $devices_count > 1 ? "..{$device_index_alphabet[$devices_count - 1]}" : "";
            $index_or_code = "";
            while (true) {
                $index_or_code = StringUtils::strip_and_lower(
                    self::prompt_string(
                        "Please enter two-factor authentication code or device index ({$device_index_alphabet[0]}{$index_str}) to send SMS with a code"
                    )
                );

                // Use pure validation functions
                if (self::is_empty_string($index_or_code)) {
                    self::echo("Empty string. Try again");
                    continue;
                }

                if (self::is_valid_device_index($index_or_code, $devices_count, $device_index_alphabet)) {
                    break;
                }

                if (self::is_valid_six_digit_code($index_or_code)) {
                    break;
                }

                // Handle invalid input cases
                if (mb_strlen($index_or_code) == 1) {
                    self::echo("Invalid index, should be ({$device_index_alphabet[0]}{$index_str}). Try again");
                    continue;
                } elseif (mb_strlen($index_or_code) == 6) {
                    self::echo("Invalid code, should be six digits. Try again");
                    continue;
                }

                self::echo(
                    "Should be index ({$device_index_alphabet[0]}{$index_str}) or six-digit code. Try again"
                );
            }

            if (str_contains($device_index_alphabet, $index_or_code)) {
                // need to send code
                $device_index = strpos($device_index_alphabet, $index_or_code);
                $device = $devices[$device_index];
                if (!$icloud->send_2fa_code_sms($device->id)) {
                    throw new PyiCloudFailedMFAException("Failed to send two-factor authentication code");
                }
                while (true) {
                    $code = StringUtils::strip(
                        self::prompt_string(
                            "Please enter two-factor authentication code that you received over SMS"
                        )
                    );
                    if (mb_strlen($code) == 6 && ctype_digit($code)) {
                        break;
                    }
                    self::echo("Invalid code, should be six digits. Try again");
                }

                if (!$icloud->validate_2fa_code_sms($device->id, $code)) {
                    throw new PyiCloudFailedMFAException("Failed to verify two-factor authentication code");
                }
            } else {
                if (!$icloud->validate_2fa_code($index_or_code)) {
                    throw new PyiCloudFailedMFAException("Failed to verify two-factor authentication code");
                }
            }
        } else {
            while (true) {
                $code = StringUtils::strip(self::prompt_string("Please enter two-factor authentication code"));
                if (mb_strlen($code) == 6 && ctype_digit($code)) {
                    break;
                }
                self::echo("Invalid code, should be six digits. Try again");
            }
            if (!$icloud->validate_2fa_code($code)) {
                throw new PyiCloudFailedMFAException("Failed to verify two-factor authentication code");
            }
        }
        $logger->info(
            "Great, you're all set up. The script can now be run without "
            . "user interaction until 2FA expires.\n"
            . "You can set up email notifications for when "
            . "the two-factor authentication expires.\n"
            . "(Use --help to view information about SMTP options.)"
        );
    }

    /**
     * Request two-factor authentication through Webui.
     */
    public static function request_2fa_web(
        PyiCloudService $icloud,
        Logger $logger,
        StatusExchange $status_exchange,
    ): void {
        // ponytail: the WebUI (Flask server in a thread feeding StatusExchange) is not ported,
        // nothing could ever supply the code. Port the status polling loop together with a server.
        throw new \RuntimeException("WebUI is not available in the PHP port");
    }
}
