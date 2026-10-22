<?php

namespace Tests;

use PyicloudIpd\Srp;
use PyicloudIpd\Srp\User;

/**
 * `srp.User` against the Python `srp` library (`_pysrp.py`): with a fixed private value `a` (`bytes_a`), the
 * public value `A`, the proof `M1`, the session key `K` and the server proof `H_AMK` of `base.py`'s Apple
 * variant (RFC 5054, no username in x, SHA-256, 2048 bit group) for both protocols. The expected values were
 * printed by the Python library (upstream venv) for the same inputs.
 */
class SrpTest extends TestCase
{
    private const A_PRIVATE = 'AwoRGB8mLTQ7QklQV15lbHN6gYiPlp2kq7K5wMfO1dzj6vH4/wYNFBsiKTA3PkVMU1phaG92fYSLkpmgp661vMPK0djf5u30+wIJEBceJSwzOkFIT1ZdZGtyeYCHjpWco6qxuL/GzdTb4unw9/4FDBMaISgvNj1ES1JZYGdudXyDipGYn6attLvCydDX3uXs8/oBCA8WHSQrMjlAR05VXGNqcXh/ho2Um6KpsLe+xczT2uHo7/b9BAsSGSAnLjU8Q0pRWF9mbXR7gomQl56lrLO6wcjP1t3k6/L5AAcOFRwjKjE4P0ZNVFtiaXB3foWMk5qhqK+2vcTL0tng5+71/A==';
    private const B_PUBLIC = 'BRIfLDlGU2BteoeUoa67yNXi7/wJFiMwPUpXZHF+i5ilsr/M2ebzAA0aJzRBTltodYKPnKm2w9Dd6vcEER4rOEVSX2x5hpOgrbrH1OHu+wgVIi88SVZjcH2Kl6SxvsvY5fL/DBkmM0BNWmd0gY6bqLXCz9zp9gMQHSo3RFFea3iFkp+sucbT4O36BxQhLjtIVWJvfImWo7C9ytfk8f4LGCUyP0xZZnOAjZqntMHO2+j1Ag8cKTZDUF1qd4SRnqu4xdLf7PkGEyAtOkdUYW57iJWir7zJ1uPw/QoXJDE+S1hlcn+MmaazwM3a5/QBDhsoNUJPXGl2g5CdqrfE0d7r+A==';
    private const SALT = 'AQwXIi04Q05ZZG96hZCbpg==';

    public static function protocols(): array
    {
        return [
            's2k' => [
                's2k',
                'OP1phOePkrUDMZXfxP+tPi3wcgjsnn+Y2jmAWegDuhlnX5JsYHZOP0S2nCerSefdIqNyZfo7EdY/grM32Tke0S6KRtQmhXzRVjh4TMHhjC5NvhYnfypux51es+hw5pA1qvhgkz2+njynRGRue5//MWCG8/cXhKlFZ9bGVxs4+SG81k0PYL8xksjy64jhRhJ6bbvidnwPmt02u+g79mAcHdj85OiqUcjIDkDFLm2dISxwQRggBzsJHqAnP+CnsqTO57fw5hbynJ9HoSaslexngQnX3RQicuN/rhoWsRdKvD+uDVj1iIzq06Ym3QqTM+ohNAiHw1hGX3H6yqWZ6/ABxw==',
                'MvSkc4LDWZMvSW1abs+M8zjOeyTErgOyfrE2UF1eJ3s=',
                '2ECtsg1xzvI1lHFUa4hb7rLRqB3eIWp5qTivump9f+0=',
                'xMWTA9OEa4z/lWbv6A3bG5/sIwa0M8lriDP+Y5xtdlc=',
            ],
            's2k_fo' => [
                's2k_fo',
                'OP1phOePkrUDMZXfxP+tPi3wcgjsnn+Y2jmAWegDuhlnX5JsYHZOP0S2nCerSefdIqNyZfo7EdY/grM32Tke0S6KRtQmhXzRVjh4TMHhjC5NvhYnfypux51es+hw5pA1qvhgkz2+njynRGRue5//MWCG8/cXhKlFZ9bGVxs4+SG81k0PYL8xksjy64jhRhJ6bbvidnwPmt02u+g79mAcHdj85OiqUcjIDkDFLm2dISxwQRggBzsJHqAnP+CnsqTO57fw5hbynJ9HoSaslexngQnX3RQicuN/rhoWsRdKvD+uDVj1iIzq06Ym3QqTM+ohNAiHw1hGX3H6yqWZ6/ABxw==',
                'EjxIrHlahzyCJfkwLlnFQcQT2H2S/GOYAga1RYCJQ9Q=',
                'OcEgRUt9CunSq1PMg8pVbBu6ytxK2MAAYXkMMCOHD6s=',
                'lJaSCKO1Yx+s1tFi9TbyZDvVkC1ajl8yMXaQjtwEB+I=',
            ],
        ];
    }

    /** the `SrpPassword` of `PyiCloudService::_authenticate_srp`: the password is encoded when the challenge is processed */
    private function srp_password(string $pwd): object
    {
        return new class($pwd) {
            public string $protocol;
            public string $salt;
            public int $iterations;

            public function __construct(private string $pwd)
            {
            }

            public function set_encrypt_info(string $protocol, string $salt, int $iterations): void
            {
                $this->protocol = $protocol;
                $this->salt = $salt;
                $this->iterations = $iterations;
            }

            public function encode(): string
            {
                $password_digest = hash('sha256', $this->pwd, $this->protocol !== 's2k_fo');
                return hash_pbkdf2('sha256', $password_digest, $this->salt, $this->iterations, 32, true);
            }
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protocols')]
    public function test_srp_matches_python(string $protocol, string $A, string $M1, string $K, string $H_AMK): void
    {
        Srp::rfc5054_enable();
        Srp::no_username_in_x();
        $password = $this->srp_password('password1');
        $usr = new User('jdoe@gmail.com', $password, hash_alg: Srp::SHA256, ng_type: Srp::NG_2048, bytes_a: base64_decode(self::A_PRIVATE));
        [$uname, $a_public] = $usr->start_authentication();
        $this->assertSame('jdoe@gmail.com', $uname);
        $this->assertSame($A, base64_encode($a_public));

        $password->set_encrypt_info($protocol, base64_decode(self::SALT), 20003);
        $m1 = $usr->process_challenge(base64_decode(self::SALT), base64_decode(self::B_PUBLIC));
        $this->assertSame($M1, base64_encode($m1));
        $this->assertSame($K, base64_encode($usr->K));
        $this->assertSame($H_AMK, base64_encode($usr->H_AMK));

        $usr->verify_session(base64_decode($H_AMK));
        $this->assertTrue($usr->authenticated());
        $this->assertSame($usr->K, $usr->get_session_key());
    }

    public function test_random_private_value_is_256_bytes_with_top_bit(): void
    {
        $usr = new User('u', 'p', hash_alg: Srp::SHA256, ng_type: Srp::NG_2048);
        $a = $usr->get_ephemeral_secret();
        $this->assertSame(256, strlen($a));
        $this->assertGreaterThanOrEqual(0x80, ord($a[0]));
    }
}
