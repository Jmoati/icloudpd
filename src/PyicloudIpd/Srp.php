<?php

namespace PyicloudIpd;

use GMP;

/**
 * The subset of the Python ``srp`` library (``srp/_pysrp.py``) that ``pyicloud_ipd.base`` uses: the SRP-6a user
 * side (``Srp\User``) with the RFC 5054 and Apple (no username in x) variants, on ext-gmp.
 *
 *   N    A large safe prime (N = 2q+1, where q is prime)
 *        All arithmetic is done modulo N.
 *   g    A generator modulo N
 *   k    Multiplier parameter (k = H(N, g) in SRP-6a, k = 3 for legacy SRP-6)
 *   s    User's salt
 *   I    Username
 *   p    Cleartext Password
 *   H()  One-way hash function
 *   ^    (Modular) Exponentiation
 *   u    Random scrambling parameter
 *   a,b  Secret ephemeral values
 *   A,B  Public ephemeral values
 *   x    Private key (derived from p and s)
 *   v    Password verifier
 *
 * ponytail: no Verifier (server side), no create_salted_verification_key, only the 2048 bit group (Apple's);
 * add the other groups of _pysrp.py when something needs them.
 */
final class Srp
{
    public const SHA1 = 0;
    public const SHA224 = 1;
    public const SHA256 = 2;
    public const SHA384 = 3;
    public const SHA512 = 4;

    public const NG_1024 = 0;
    public const NG_2048 = 1;
    public const NG_4096 = 2;
    public const NG_8192 = 3;
    public const NG_CUSTOM = 4;

    private const HASH_MAP = [
        self::SHA1 => 'sha1',
        self::SHA224 => 'sha224',
        self::SHA256 => 'sha256',
        self::SHA384 => 'sha384',
        self::SHA512 => 'sha512',
    ];

    // 2048 bit group
    private const N_2048 = 'AC6BDB41324A9A9BF166DE5E1389582FAF72B6651987EE07FC3192943DB56050A37329CBB4'
        .'A099ED8193E0757767A13DD52312AB4B03310DCD7F48A9DA04FD50E8083969EDB767B0CF60'
        .'95179A163AB3661A05FBD5FAAAE82918A9962F0B93B855F97993EC975EEAA80D740ADBF4FF'
        .'747359D041D5C33EA71D281E446B14773BCA97B43A23FB801676BD207A436C6481F1D2B907'
        .'8717461A5B9D32E688F87748544523B524B0D57D5EA77A2775D2ECFA032CFBDBF52FB37861'
        .'60279004E57AE6AF874E7303CE53299CCC041C7BC308D82A5698F3A8D0C38271AE35F8E9DB'
        .'FBB694B5C803D89F7AE435DE236D525F54759B65E372FCD68EF20FA7111F9E4AFF73';

    private static bool $_rfc5054_compat = false;
    private static bool $_no_username_in_x = false;

    public static function rfc5054_enable(bool $enable = true): void
    {
        self::$_rfc5054_compat = $enable;
    }

    public static function no_username_in_x(bool $enable = true): void
    {
        self::$_no_username_in_x = $enable;
    }

    public static function rfc5054_compat(): bool
    {
        return self::$_rfc5054_compat;
    }

    /** @return array{0: GMP, 1: GMP} */
    public static function get_ng(int $ng_type, ?string $n_hex, ?string $g_hex): array
    {
        if ($ng_type === self::NG_CUSTOM) {
            return [gmp_init($n_hex, 16), gmp_init($g_hex, 16)];
        }
        if ($ng_type !== self::NG_2048) {
            throw new \ValueError('ponytail: only the 2048 bit group is ported');
        }

        return [gmp_init(self::N_2048, 16), gmp_init('2', 16)];
    }

    /** The hash function name (``hashlib`` constructor) of a ``SHA*`` constant. */
    public static function hash_name(int $hash_alg): string
    {
        return self::HASH_MAP[$hash_alg];
    }

    public static function bytes_to_long(string $s): GMP
    {
        return $s === '' ? gmp_init(0) : gmp_import($s);
    }

    public static function long_to_bytes(GMP $n): string
    {
        return gmp_cmp($n, 0) === 0 ? '' : gmp_export($n);
    }

    public static function get_random(int $nbytes): GMP
    {
        return self::bytes_to_long(random_bytes($nbytes));
    }

    public static function get_random_of_length(int $nbytes): GMP
    {
        $offset = ($nbytes * 8) - 1;

        return self::get_random($nbytes) | (gmp_init(1) << $offset);
    }

    /**
     * ``H(hash_class, *args, width=None)``: the hash of the arguments (GMP integers are written as bytes, null is skipped).
     */
    public static function H(string $hash_class, GMP|string|null $arg1, GMP|string|null $arg2 = null, ?int $width = null): string
    {
        $h = hash_init($hash_class);

        foreach ([$arg1, $arg2] as $s) {
            if ($s !== null) {
                $data = $s instanceof GMP ? self::long_to_bytes($s) : $s;
                if ($width !== null && self::$_rfc5054_compat) {
                    hash_update($h, str_repeat("\0", max(0, $width - strlen($data))));
                }
                hash_update($h, $data);
            }
        }

        return hash_final($h, true);
    }

    public static function HNxorg(string $hash_class, GMP $N, GMP $g): string
    {
        $bin_N = self::long_to_bytes($N);
        $bin_g = self::long_to_bytes($g);

        $padding = self::$_rfc5054_compat ? strlen($bin_N) - strlen($bin_g) : 0;

        $hN = hash($hash_class, $bin_N, true);
        $hg = hash($hash_class, str_repeat("\0", $padding).$bin_g, true);

        return $hN ^ $hg;
    }

    /** @param string|object $password a string, or an object with ``encode()`` (the password is encoded when the challenge is processed) */
    public static function gen_x(string $hash_class, string $salt, string $username, string|object $password): GMP
    {
        $password = is_object($password) ? $password->encode() : $password;
        if (self::$_no_username_in_x) {
            $username = '';
        }

        return self::bytes_to_long(self::H($hash_class, $salt, self::H($hash_class, $username.':'.$password)));
    }

    public static function calculate_M(string $hash_class, GMP $N, GMP $g, string $I, string $s, GMP $A, GMP $B, string $K): string
    {
        $h = hash_init($hash_class);
        hash_update($h, self::HNxorg($hash_class, $N, $g));
        hash_update($h, hash($hash_class, $I, true));
        hash_update($h, $s);
        hash_update($h, self::long_to_bytes($A));
        hash_update($h, self::long_to_bytes($B));
        hash_update($h, $K);

        return hash_final($h, true);
    }

    public static function calculate_H_AMK(string $hash_class, GMP $A, string $M, string $K): string
    {
        $h = hash_init($hash_class);
        hash_update($h, self::long_to_bytes($A));
        hash_update($h, $M);
        hash_update($h, $K);

        return hash_final($h, true);
    }
}
