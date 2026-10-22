<?php

namespace PyicloudIpd\Srp;

use GMP;
use PyicloudIpd\Srp;

/** The user (client) side of SRP-6a (``srp.User``). */
class User
{
    public string $I;
    public string|object $p;
    public GMP $a;
    public GMP $A;
    public ?GMP $v = null;
    public ?string $M = null;
    public ?string $K = null;
    public ?string $H_AMK = null;
    private bool $_authenticated = false;

    public string $hash_class;
    public GMP $N;
    public GMP $g;
    public GMP $k;

    // set by process_challenge()
    public string $s;
    public GMP $B;
    public GMP $u;
    public GMP $x;
    public GMP $S;

    public function __construct(
        string $username,
        string|object $password,
        int $hash_alg = Srp::SHA1,
        int $ng_type = Srp::NG_2048,
        ?string $n_hex = null,
        ?string $g_hex = null,
        ?string $bytes_a = null,
        ?string $bytes_A = null,
        ?string $k_hex = null,
    ) {
        if ($ng_type === Srp::NG_CUSTOM && ($n_hex === null || $g_hex === null)) {
            throw new \ValueError('Both n_hex and g_hex are required when ng_type = NG_CUSTOM');
        }
        if ($bytes_a && strlen($bytes_a) !== 256) {
            throw new \ValueError('256 bytes required for bytes_a');
        }
        [$N, $g] = Srp::get_ng($ng_type, $n_hex, $g_hex);
        $hash_class = Srp::hash_name($hash_alg);
        if ($k_hex === null) {
            $k = Srp::bytes_to_long(Srp::H($hash_class, $N, $g, width: strlen(Srp::long_to_bytes($N))));
        } else {
            $k = gmp_init($k_hex, 16);
        }

        $this->I = $username;
        $this->p = $password;
        if ($bytes_a) {
            $this->a = Srp::bytes_to_long($bytes_a);
        } else {
            $this->a = Srp::get_random_of_length(256);
        }
        if ($bytes_A) {
            $this->A = Srp::bytes_to_long($bytes_A);
        } else {
            $this->A = gmp_powm($g, $this->a, $N);
        }

        $this->hash_class = $hash_class;
        $this->N = $N;
        $this->g = $g;
        $this->k = $k;
    }

    public function authenticated(): bool
    {
        return $this->_authenticated;
    }

    public function get_username(): string
    {
        return $this->I;
    }

    public function get_ephemeral_secret(): string
    {
        return Srp::long_to_bytes($this->a);
    }

    public function get_session_key(): ?string
    {
        return $this->_authenticated ? $this->K : null;
    }

    /** @return array{0: string, 1: string} (username, A) */
    public function start_authentication(): array
    {
        return [$this->I, Srp::long_to_bytes($this->A)];
    }

    /** Returns M or null if SRP-6a safety check is violated */
    public function process_challenge(string $bytes_s, string $bytes_B): ?string
    {
        $this->s = $bytes_s;
        $this->B = Srp::bytes_to_long($bytes_B);

        $N = $this->N;
        $g = $this->g;
        $k = $this->k;

        $hash_class = $this->hash_class;

        // SRP-6a safety check
        if (gmp_cmp(gmp_mod($this->B, $N), 0) === 0) {
            return null;
        }

        $this->u = Srp::bytes_to_long(Srp::H($hash_class, $this->A, $this->B, width: strlen(Srp::long_to_bytes($N))));

        // SRP-6a safety check
        if (gmp_cmp($this->u, 0) === 0) {
            return null;
        }

        $this->x = Srp::gen_x($hash_class, $this->s, $this->I, $this->p);

        $this->v = gmp_powm($g, $this->x, $N);

        // Python's pow() reduces a negative base modulo N, so does this
        $this->S = gmp_powm(gmp_mod($this->B - $k * $this->v, $N), $this->a + $this->u * $this->x, $N);

        $this->K = hash($hash_class, Srp::long_to_bytes($this->S), true);
        $this->M = Srp::calculate_M($hash_class, $N, $g, $this->I, $this->s, $this->A, $this->B, $this->K);
        $this->H_AMK = Srp::calculate_H_AMK($hash_class, $this->A, $this->M, $this->K);

        return $this->M;
    }

    public function verify_session(string $host_HAMK): void
    {
        if ($this->H_AMK === $host_HAMK) {
            $this->_authenticated = true;
        }
    }
}
