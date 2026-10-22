<?php

namespace PyicloudIpd;

use Foundation\Http\ConnectionError;
use Foundation\Http\NewConnectionError;
use Foundation\Http\Response;
use Foundation\Http\Timeout;
use Foundation\Http\TimeoutError;
use PyicloudIpd\AssetVersion\AssetVersion as AssetVersionClass;
use PyicloudIpd\Exceptions\PyiCloudConnectionErrorException;
use PyicloudIpd\Exceptions\PyiCloudNoStoredPasswordAvailableException;
use PyicloudIpd\Exceptions\PyiCloudServiceUnavailableException;
use PyicloudIpd\Services\Photos\PhotoAsset;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;
use WeakMap;

/** Module `pyicloud_ipd/utils.py`. */
final class Utils
{
    public const KEYRING_SYSTEM = 'pyicloud://icloud-password';

    // def get_password(username:str, interactive:bool=sys.stdout.isatty()) -> str:
    //     try:
    //         return get_password_from_keyring(username)
    //     except PyiCloudNoStoredPasswordAvailableException:
    //         if not interactive:
    //             raise

    //         return getpass.getpass(
    //             'Enter iCloud password for {username}: '.format(
    //                 username=username,
    //             )
    //         )

    public static function password_exists_in_keyring(string $username): bool
    {
        try {
            return self::get_password_from_keyring($username) !== null;
        } catch (PyiCloudNoStoredPasswordAvailableException) {
            return false;
        }
    }

    public static function get_password_from_keyring(string $username): ?string
    {
        $result = Keyring::get_password(self::KEYRING_SYSTEM, $username);
        // if result is None:
        //     raise PyiCloudNoStoredPasswordAvailableException(
        //         "No pyicloud password for {username} could be found "
        //         "in the system keychain.  Use the `--store-in-keyring` "
        //         "command-line option for storing a password for this "
        //         "username.".format(
        //             username=username,
        //         )
        //     )

        return $result;
    }

    public static function store_password_in_keyring(string $username, string $password): void
    {
        // if get_password_from_keyring(username) is not None:
        //     # Apple can save only into empty keyring
        //     return delete_password_in_keyring(username)
        Keyring::set_password(
            self::KEYRING_SYSTEM,
            $username,
            $password,
        );
    }

    public static function delete_password_in_keyring(string $username): void
    {
        Keyring::delete_password(
            self::KEYRING_SYSTEM,
            $username,
        );
    }

    public static function underscore_to_camelcase(string $word, bool $initial_capital = false): string
    {
        $words = array_map(static fn (string $x) => ucfirst(strtolower($x)) ?: '_', explode('_', $word));
        if (!$initial_capital) {
            $words[0] = strtolower($words[0]);
        }

        return implode('', $words);
    }

    // def filename_with_size(filename: str, size: str, original: Optional[Dict[str, Any]]) -> str:
    //     """Returns the filename with size, e.g. IMG1234.jpg, IMG1234-small.jpg"""
    //     if size == 'original' or size == 'alternative':
    //         # TODO what if alternative ext matches original?
    //         return filename
    //     # for adjustments we add size only if extension matches original (alternative
    //     if size == "adjusted" and original != None and original["filename"] != filename:
    //         return filename
    //     return (f"-{size}.").join(filename.rsplit(".", 1))

    public static function size_to_suffix(AssetVersionSize|LivePhotoVersionSize $size): string
    {
        // str(size) of the enums is their name
        return strtolower("-$size->name");
    }

    /**
     * The versions to download for the requested sizes, and the filenames that must be disambiguated.
     *
     * `Dict[VersionSize, X]` are `WeakMap`s keyed by the enum cases (see `PyicloudIpd\AssetVersion`).
     *
     * @param WeakMap<AssetVersionSize|LivePhotoVersionSize, AssetVersionClass> $_versions
     * @param list<AssetVersionSize|LivePhotoVersionSize> $_sizes
     * @return array{0: WeakMap<AssetVersionSize|LivePhotoVersionSize, AssetVersionClass>, 1: WeakMap<AssetVersionSize|LivePhotoVersionSize, string>}
     * @throws \OutOfBoundsException (Python: KeyError) when the original version is needed and missing
     */
    public static function disambiguate_filenames(
        WeakMap $_versions,
        array $_sizes,
        PhotoAsset $photo_asset,
        callable $lp_filename_generator,
    ): array {
        $_results = new WeakMap();
        $_filename_overrides = new WeakMap();
        // `d[key]`: a missing key is a KeyError
        $get = static function (WeakMap $map, AssetVersionSize|LivePhotoVersionSize $key): AssetVersionClass {
            if (!isset($map[$key])) {
                throw new \OutOfBoundsException(get_class($key).'.'.$key->name);
            }

            return $map[$key];
        };
        $original = static fn (): AssetVersionClass => $get($_versions, AssetVersionSize::ORIGINAL);

        // add those that were requested
        foreach ($_sizes as $_size) {
            $_version = $_versions[$_size] ?? null;
            if ($_version) {
                $_results[$_size] = clone $_version;
            }
        }

        // adjusted
        if (in_array(AssetVersionSize::ADJUSTED, $_sizes, true)) {
            if (!in_array(AssetVersionSize::ORIGINAL, $_sizes, true)) {
                if (!isset($_results[AssetVersionSize::ADJUSTED])) {
                    // clone
                    $_results[AssetVersionSize::ADJUSTED] = clone $original();
                }
            } else {
                if (isset($_results[AssetVersionSize::ADJUSTED])) {
                    $original_filename = $photo_asset->calculate_version_filename(
                        $get($_results, AssetVersionSize::ORIGINAL),
                        AssetVersionSize::ORIGINAL,
                        $lp_filename_generator,
                    );
                    $adjusted_filename = $photo_asset->calculate_version_filename(
                        $get($_results, AssetVersionSize::ADJUSTED),
                        AssetVersionSize::ADJUSTED,
                        $lp_filename_generator,
                    );
                    if ($original_filename === $adjusted_filename) {
                        // Store filename override for adjusted version
                        $_filename_overrides[AssetVersionSize::ADJUSTED] = AssetVersion::add_suffix_to_filename(
                            '-adjusted',
                            $adjusted_filename,
                        );
                    }
                }
            }
        }

        // alternative
        if (in_array(AssetVersionSize::ALTERNATIVE, $_sizes, true)) {
            if (!isset($_results[AssetVersionSize::ALTERNATIVE])) {
                // Only clone from original when alternative is missing AND original is not requested
                if (!in_array(AssetVersionSize::ORIGINAL, $_sizes, true)) {
                    $_results[AssetVersionSize::ALTERNATIVE] = clone $original();
                }
            } else {
                // Check for filename conflicts and add disambiguating suffix if needed
                $alternative_filename = $photo_asset->calculate_version_filename(
                    $get($_results, AssetVersionSize::ALTERNATIVE),
                    AssetVersionSize::ALTERNATIVE,
                    $lp_filename_generator,
                );
                $alt_adjusted_filename = null;
                $alt_original_filename = null;

                if (isset($_results[AssetVersionSize::ADJUSTED])) {
                    $alt_adjusted_filename = $photo_asset->calculate_version_filename(
                        $get($_results, AssetVersionSize::ADJUSTED),
                        AssetVersionSize::ADJUSTED,
                        $lp_filename_generator,
                    );
                }
                if (isset($_results[AssetVersionSize::ORIGINAL])) {
                    $alt_original_filename = $photo_asset->calculate_version_filename(
                        $get($_results, AssetVersionSize::ORIGINAL),
                        AssetVersionSize::ORIGINAL,
                        $lp_filename_generator,
                    );
                }

                if (($alt_adjusted_filename && $alternative_filename === $alt_adjusted_filename)
                    || ($alt_original_filename && $alternative_filename === $alt_original_filename)) {
                    // Store filename override for alternative version
                    $_filename_overrides[AssetVersionSize::ALTERNATIVE] = AssetVersion::add_suffix_to_filename(
                        '-alternative',
                        $alternative_filename,
                    );
                }
            }
        }

        foreach ($_sizes as $_size) {
            if (!in_array($_size, [
                AssetVersionSize::ORIGINAL,
                AssetVersionSize::ADJUSTED,
                AssetVersionSize::ALTERNATIVE,
            ], true)
                && !isset($_results[$_size])
                && !in_array(AssetVersionSize::ORIGINAL, $_sizes, true)) {
                // ensure original is downloaded - mimic existing behavior
                $_results[AssetVersionSize::ORIGINAL] = clone $original();
                // else:
                //     _n, _e = os.path.splitext(_results[_size]["filename"])
                //     _results[_size]["filename"] = f"{_n}-{_size}{_e}"
            }
        }

        return [$_results, $_filename_overrides];
    }

    public static function throw_on_503(Response $response): Response
    {
        if ($response->status_code === 503) {
            throw new PyiCloudServiceUnavailableException(
                'Apple iCloud is temporary refusing to serve icloudpd',
            );
        } else {
            return $response;
        }
    }

    /**
     * Decorator to catch connection errors and raise PyiCloudConnectionErrorException.
     *
     * @param callable $func
     */
    public static function handle_connection_error(callable $func): \Closure
    {
        return function (mixed ...$args) use ($func): mixed {
            try {
                return $func(...$args);
            } catch (ConnectionError|TimeoutError|Timeout|NewConnectionError $error) {
                throw new PyiCloudConnectionErrorException(
                    'Cannot connect to Apple iCloud service',
                    0,
                    $error,
                );
            }
        };
    }
}
