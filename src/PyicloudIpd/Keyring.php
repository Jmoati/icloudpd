<?php

namespace PyicloudIpd;

use PyicloudIpd\Keyring\NoKeyringError;
use PyicloudIpd\Keyring\PasswordDeleteError;

/**
 * The ``keyring`` library: ``get_password``, ``set_password`` and ``delete_password`` on the system keyring, with
 * the entries the Python ``keyring`` creates (service name and user name), so both tools share them.
 *
 * ponytail: macOS (``security``) and Linux (``secret-tool``, the Secret Service) only, through their command
 * line: on macOS the password is an argument of ``security add-generic-password`` while it is stored (visible
 * in the process list for an instant). Anything else is "keyring not available" (``NoKeyringError``).
 */
final class Keyring
{
    public static function get_password(string $service_name, string $username): ?string
    {
        [$status, $out] = self::run(
            self::macos()
                ? ['security', 'find-generic-password', '-s', $service_name, '-a', $username, '-w']
                : ['secret-tool', 'lookup', 'service', $service_name, 'username', $username],
        );
        if ($status !== 0) {
            return null;
        }

        return self::macos() ? preg_replace('/\n$/', '', $out) : $out;
    }

    public static function set_password(string $service_name, string $username, string $password): void
    {
        [$status, , $err] = self::macos()
            ? self::run(['security', 'add-generic-password', '-s', $service_name, '-a', $username, '-w', $password, '-U'])
            : self::run(['secret-tool', 'store', '--label', "Password for '$username' on '$service_name'", 'service', $service_name, 'username', $username], $password);
        if ($status !== 0) {
            throw new NoKeyringError(trim($err) ?: 'Can\'t store password on keychain');
        }
    }

    public static function delete_password(string $service_name, string $username): void
    {
        [$status] = self::run(
            self::macos()
                ? ['security', 'delete-generic-password', '-s', $service_name, '-a', $username]
                : ['secret-tool', 'clear', 'service', $service_name, 'username', $username],
        );
        if ($status !== 0) {
            throw new PasswordDeleteError('Password not found');
        }
    }

    private static function macos(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private static function run(array $command, ?string $stdin = null): array
    {
        $binary = $command[0];
        if (!(PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') || !self::which($binary)) {
            throw new NoKeyringError('keyring not available');
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new NoKeyringError('keyring not available');
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [$status, $out, $err];
    }

    private static function which(string $binary): bool
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
            if ($dir !== '' && is_executable("$dir/$binary")) {
                return true;
            }
        }

        return false;
    }
}
