<?php

namespace Tests;

use Foundation\Plist;
use Foundation\Plist\InvalidFileException;
use Foundation\Plist\UID;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plists produced by Python's `plistlib.dumps(obj, fmt=plistlib.FMT_BINARY)` (3.13), base64-encoded,
 * with the object they come from: `Plist::loads` must give it back.
 */
class PlistTest extends TestCase
{
    /** dates, bigints and UIDs get a comparable form, everything else is compared strictly */
    private static function normalize(mixed $v): mixed
    {
        if ($v instanceof \DateTimeImmutable) {
            return 'date:'.$v->format('Y-m-d H:i:s.u e');
        }
        if ($v instanceof \GMP) {
            return 'gmp:'.gmp_strval($v);
        }
        if ($v instanceof UID) {
            return ['uid', self::normalize($v->data)];
        }
        if (is_array($v)) {
            return array_map(self::normalize(...), $v);
        }

        return $v;
    }

    #[DataProvider('binary_plists')]
    public function test_binary_plist(string $b64, mixed $expected): void
    {
        $this->assertSame(self::normalize($expected), self::normalize(Plist::loads(base64_decode($b64))));
    }

    #[DataProvider('xml_plists')]
    public function test_xml_plist(string $b64, mixed $expected): void
    {
        $this->assertSame(self::normalize($expected), self::normalize(Plist::loads(base64_decode($b64))));
    }

    public function test_nan(): void
    {
        // plistlib.dumps(float('nan'), fmt=plistlib.FMT_BINARY)
        $this->assertNan(Plist::loads(base64_decode('YnBsaXN0MDAjf/gAAAAAAAAIAAAAAAAAAQEAAAAAAAAAAQAAAAAAAAAAAAAAAAAAABE=')));
    }

    public function test_float32(): void
    {
        // plistlib cannot write 0x22, hand-made: 1.5 as a 32-bit real
        $body = "bplist00\x22\x3f\xc0\x00\x00";
        $table = strlen($body);
        $this->assertSame(1.5, Plist::loads($body."\x08".str_repeat("\0", 6)."\x01\x01".pack('J3', 1, 0, $table)));
    }

    public function test_empty_data_token(): void
    {
        // token 0x0f is an empty bytes in plistlib
        $body = "bplist00\x0f";
        $this->assertSame('', Plist::loads($body."\x08".str_repeat("\0", 6)."\x01\x01".pack('J3', 1, 0, strlen($body))));
    }

    public function test_invalid_files(): void
    {
        foreach (['', 'garbage', 'bplist00', "bplist00\x00".str_repeat("\0", 31), '<?xml version="1.0"?><plist><dict>'] as $data) {
            try {
                Plist::loads($data);
                $this->fail('InvalidFileException expected for '.json_encode($data));
            } catch (InvalidFileException $e) {
                $this->assertInstanceOf(\ValueError::class, $e);
            }
        }
    }

    public function test_truncated_binary_plist(): void
    {
        $data = base64_decode(self::binary_plists()['nested'][0]);
        foreach ([20, 40, 100, strlen($data) - 40] as $n) {
            // drop bytes in the middle: the trailer stays valid but the offsets lie
            try {
                Plist::loads(substr($data, 0, $n).substr($data, -32));
                $this->fail("InvalidFileException expected for $n bytes");
            } catch (InvalidFileException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_self_referencing_array_is_rejected(): void
    {
        // array whose only item is itself (Python builds a self-containing list, a PHP array cannot)
        $body = "bplist00\xA1\x00";
        $data = $body."\x08".str_repeat("\0", 6)."\x01\x01".pack('J3', 1, 0, strlen($body));
        $this->expectException(InvalidFileException::class);
        Plist::loads($data);
    }

    public static function binary_plists(): array
    {
        return [
            'null_bool' => ['YnBsaXN0MDCjAQIDAAkICAwNDgAAAAAAAAEBAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAP',
                [null, true, false]],
            'ints' => ['YnBsaXN0MDCvEA8BAgMEBQYHCAkKCwwNDg8QABABEP8RAQAR//8SAAEAABL/////EwAAAAEAAAAAE3//////////FAAAAAAAAAAAgAAAAAAAAAAUAAAAAAAAAAD//////////xP//////////xP//////////hP/////////gBOAAAAAAAAAAAgaHB4gIyYrMDlCU2Rtdn8AAAAAAAABAQAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAiA==',
                [0, 1, 255, 256, 65535, 65536, 4294967295, 4294967296, 9223372036854775807, gmp_init('9223372036854775808'), gmp_init('18446744073709551615'), -1, -2, -128, PHP_INT_MIN]],
            'floats' => ['YnBsaXN0MDCpAQECAwQFBgcIIwAAAAAAAAAAIz/4AAAAAAAAI8BeuGuWmKTuI0B0b0fo/R+kI3435DyIAHWcIwAAAAAAAAABI3/wAAAAAAAAI//wAAAAAAAACBIbJC02P0hRAAAAAAAAAQEAAAAAAAAACQAAAAAAAAAAAAAAAAAAAFo=',
                [0.0, 0.0, 1.5, -122.88156666666666, 326.9550561797753, 1e+300, 5e-324, INF, -INF]],
            'ascii' => ['YnBsaXN0MDCnAQIDBAUGB1BRYVtoZWxsbyB3b3JsZF54eHh4eHh4eHh4eHh4eF8QD3h4eHh4eHh4eHh4eHh4eF8RASx5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXl5eXlfEQEsenp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6enp6AAgAEAARABMAHwAuAEABcAAAAAAAAAIBAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAKg',
                ["", "a", "hello world", "xxxxxxxxxxxxxx", "xxxxxxxxxxxxxxx", "yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy", "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz"]],
            'utf16' => ['YnBsaXN0MDCkAQIDBGcAaADpAGwAbABvACAgrGjYPd4AACAAZQBtAG8AagBpY2XlZyyKnm8QUABjAGEAZgDpAGMAYQBmAOkAYwBhAGYA6QBjAGEAZgDpAGMAYQBmAOkAYwBhAGYA6QBjAGEAZgDpAGMAYQBmAOkAYwBhAGYA6QBjAGEAZgDpAGMAYQBmAOkAYwBhAGYA6QBjAGEAZgDpAGMAYQBmAOkAYwBhAGYA6QBjAGEAZgDpAGMAYQBmAOkAYwBhAGYA6QBjAGEAZgDpAGMAYQBmAOkIDRwtNAAAAAAAAAEBAAAAAAAAAAUAAAAAAAAAAAAAAAAAAADX',
                ["h\u{E9}llo \u{20AC}", "\u{1F600} emoji", "\u{65E5}\u{672C}\u{8A9E}", "caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}caf\u{E9}"]],
            'data' => ['YnBsaXN0MDCkAQIDBEBDAAH/TxECAAABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhscHR4fICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj9AQUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVpbXF1eX2BhYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5ent8fX5/gIGCg4SFhoeIiYqLjI2Oj5CRkpOUlZaXmJmam5ydnp+goaKjpKWmp6ipqqusra6vsLGys7S1tre4ubq7vL2+v8DBwsPExcbHyMnKy8zNzs/Q0dLT1NXW19jZ2tvc3d7f4OHi4+Tl5ufo6err7O3u7/Dx8vP09fb3+Pn6+/z9/v8AAQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyAhIiMkJSYnKCkqKywtLi8wMTIzNDU2Nzg5Ojs8PT4/QEFCQ0RFRkdISUpLTE1OT1BRUlNUVVZXWFlaW1xdXl9gYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXp7fH1+f4CBgoOEhYaHiImKi4yNjo+QkZKTlJWWl5iZmpucnZ6foKGio6SlpqeoqaqrrK2ur7CxsrO0tba3uLm6u7y9vr/AwcLDxMXGx8jJysvMzc7P0NHS09TV1tfY2drb3N3e3+Dh4uPk5ebn6Onq6+zt7u/w8fLz9PX29/j5+vv8/f7/TxEBLHFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcQAIAA0ADgASAhYAAAAAAAACAQAAAAAAAAAFAAAAAAAAAAAAAAAAAAADRg==',
                [base64_decode(''), base64_decode('AAH/'), base64_decode('AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0+P0BBQkNERUZHSElKS0xNTk9QUVJTVFVWV1hZWltcXV5fYGFiY2RlZmdoaWprbG1ub3BxcnN0dXZ3eHl6e3x9fn+AgYKDhIWGh4iJiouMjY6PkJGSk5SVlpeYmZqbnJ2en6ChoqOkpaanqKmqq6ytrq+wsbKztLW2t7i5uru8vb6/wMHCw8TFxsfIycrLzM3Oz9DR0tPU1dbX2Nna29zd3t/g4eLj5OXm5+jp6uvs7e7v8PHy8/T19vf4+fr7/P3+/wABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhscHR4fICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj9AQUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVpbXF1eX2BhYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5ent8fX5/gIGCg4SFhoeIiYqLjI2Oj5CRkpOUlZaXmJmam5ydnp+goaKjpKWmp6ipqqusra6vsLGys7S1tre4ubq7vL2+v8DBwsPExcbHyMnKy8zNzs/Q0dLT1NXW19jZ2tvc3d7f4OHi4+Tl5ufo6err7O3u7/Dx8vP09fb3+Pn6+/z9/v8='), base64_decode('cXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFxcXFx')]],
            'dates' => ['YnBsaXN0MDCnAQIDBAUGBzMAAAAAAAAAADNBwgVzQoAAADNBwIeYxRaHKzPBfihQAAABDDPBzSfkQAAAADNCTWLSPH+AADPB56QC1/AAAAgQGSIrND1GAAAAAAAAAQEAAAAAAAAACAAAAAAAAAAAAAAAAAAAAE8=',
                [new \DateTimeImmutable('2001-01-01 00:00:00.000000 UTC'), new \DateTimeImmutable('2020-02-29 18:35:49.000000 UTC'), new \DateTimeImmutable('2018-07-30 11:44:10.176000 UTC'), new \DateTimeImmutable('1999-12-31 23:59:59.999999 UTC'), new \DateTimeImmutable('1970-01-01 00:00:00.000000 UTC'), new \DateTimeImmutable('9999-12-31 23:59:59.000000 UTC'), new \DateTimeImmutable('1900-06-15 12:00:00.500000 UTC')]],
            'uid' => ['YnBsaXN0MDCmAQIDBAUGgACA/4EBAIMAAQAAhwAAAAEAAAAAh///////////CA8RExYbJAAAAAAAAAEBAAAAAAAAAAcAAAAAAAAAAAAAAAAAAAAt',
                [new UID(0), new UID(255), new UID(256), new UID(65536), new UID(4294967296), new UID(gmp_init('18446744073709551615'))]],
            'nested' => ['YnBsaXN0MDDXAQIDBAUGBwgJCgsMGRpTYWx0ZABjAGEAZgDpWmVtcHR5X2xpc3RTbGF0VGxpc3RTbG9uWXRpbWVzdGFtcCMAAAAAAAAAAFJva6AjQEkMDjMQP1qiDQ4QAaIPEBACohESEAPRExRUZGVlcKIVFlF40RcYUWsAI8BeuGuWmKTuM0HCBXNCgAAACBcbJC8zODxGT1JTXF9hZGZpa25zdnh7fX6HAAAAAAAAAQEAAAAAAAAAGwAAAAAAAAAAAAAAAAAAAJA=',
                ["alt" => 0.0, "caf\u{E9}" => "ok", "empty_list" => [], "lat" => 50.09418333333333, "list" => [1, [2, [3, ["deep" => ["x", ["k" => null]]]]]], "lon" => -122.88156666666666, "timestamp" => new \DateTimeImmutable('2020-02-29 18:35:49.000000 UTC')]],
            'big_array' => ['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',
                [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92, 93, 94, 95, 96, 97, 98, 99, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133, 134, 135, 136, 137, 138, 139, 140, 141, 142, 143, 144, 145, 146, 147, 148, 149, 150, 151, 152, 153, 154, 155, 156, 157, 158, 159, 160, 161, 162, 163, 164, 165, 166, 167, 168, 169, 170, 171, 172, 173, 174, 175, 176, 177, 178, 179, 180, 181, 182, 183, 184, 185, 186, 187, 188, 189, 190, 191, 192, 193, 194, 195, 196, 197, 198, 199, 200, 201, 202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212, 213, 214, 215, 216, 217, 218, 219, 220, 221, 222, 223, 224, 225, 226, 227, 228, 229, 230, 231, 232, 233, 234, 235, 236, 237, 238, 239, 240, 241, 242, 243, 244, 245, 246, 247, 248, 249, 250, 251, 252, 253, 254, 255, 256, 257, 258, 259, 260, 261, 262, 263, 264, 265, 266, 267, 268, 269, 270, 271, 272, 273, 274, 275, 276, 277, 278, 279, 280, 281, 282, 283, 284, 285, 286, 287, 288, 289, 290, 291, 292, 293, 294, 295, 296, 297, 298, 299]],
            'big_dict' => ['YnBsaXN0MDDfEQEsAAEAAgADAAQABQAGAAcACAAJAAoACwAMAA0ADgAPABAAEQASABMAFAAVABYAFwAYABkAGgAbABwAHQAeAB8AIAAhACIAIwAkACUAJgAnACgAKQAqACsALAAtAC4ALwAwADEAMgAzADQANQA2ADcAOAA5ADoAOwA8AD0APgA/AEAAQQBCAEMARABFAEYARwBIAEkASgBLAEwATQBOAE8AUABRAFIAUwBUAFUAVgBXAFgAWQBaAFsAXABdAF4AXwBgAGEAYgBjAGQAZQBmAGcAaABpAGoAawBsAG0AbgBvAHAAcQByAHMAdAB1AHYAdwB4AHkAegB7AHwAfQB+AH8AgACBAIIAgwCEAIUAhgCHAIgAiQCKAIsAjACNAI4AjwCQAJEAkgCTAJQAlQCWAJcAmACZAJoAmwCcAJ0AngCfAKAAoQCiAKMApAClAKYApwCoAKkAqgCrAKwArQCuAK8AsACxALIAswC0ALUAtgC3ALgAuQC6ALsAvAC9AL4AvwDAAMEAwgDDAMQAxQDGAMcAyADJAMoAywDMAM0AzgDPANAA0QDSANMA1ADVANYA1wDYANkA2gDbANwA3QDeAN8A4ADhAOIA4wDkAOUA5gDnAOgA6QDqAOsA7ADtAO4A7wDwAPEA8gDzAPQA9QD2APcA+AD5APoA+wD8AP0A/gD/AQABAQECAQMBBAEFAQYBBwEIAQkBCgELAQwBDQEOAQ8BEAERARIBEwEUARUBFgEXARgBGQEaARsBHAEdAR4BHwEgASEBIgEjASQBJQEmAScBKAEpASoBKwEsAS0BLgEvATABMQEyATMBNAE1ATYBNwE4ATkBOgE7ATwBPQE+AT8BQAFBAUIBQwFEAUUBRgFHAUgBSQFKAUsBTAFNAU4BTwFQAVEBUgFTAVQBVQFWAVcBWAFZAVoBWwFcAV0BXgFfAWABYQFiAWMBZAFlAWYBZwFoAWkBagFrAWwBbQFuAW8BcAFxAXIBcwF0AXUBdgF3AXgBeQF6AXsBfAF9AX4BfwGAAYEBggGDAYQBhQGGAYcBiAGJAYoBiwGMAY0BjgGPAZABkQGSAZMBlAGVAZYBlwGYAZkBmgGbAZwBnQGeAZ8BoAGhAaIBowGkAaUBpgGnAagBqQGqAasBrAGtAa4BrwGwAbEBsgGzAbQBtQG2AbcBuAG5AboBuwG8Ab0BvgG/AcABwQHCAcMBxAHFAcYBxwHIAckBygHLAcwBzQHOAc8B0AHRAdIB0wHUAdUB1gHXAdgB2QHaAdsB3AHdAd4B3wHgAeEB4gHjAeQB5QHmAecB6AHpAeoB6wHsAe0B7gHvAfAB8QHyAfMB9AH1AfYB9wH4AfkB+gH7AfwB/QH+Af8CAAIBAgICAwIEAgUCBgIHAggCCQIKAgsCDAINAg4CDwIQAhECEgITAhQCFQIWAhcCGAIZAhoCGwIcAh0CHgIfAiACIQIiAiMCJAIlAiYCJwIoAikCKgIrAiwCLQIuAi8CMAIxAjICMwI0AjUCNgI3AjgCOQI6AjsCPAI9Aj4CPwJAAkECQgJDAkQCRQJGAkcCSAJJAkoCSwJMAk0CTgJPAlACUQJSAlMCVAJVAlYCVwJYVGtleTBUa2V5MVVrZXkxMFZrZXkxMDBWa2V5MTAxVmtleTEwMlZrZXkxMDNWa2V5MTA0VmtleTEwNVZrZXkxMDZWa2V5MTA3VmtleTEwOFZrZXkxMDlVa2V5MTFWa2V5MTEwVmtleTExMVZrZXkxMTJWa2V5MTEzVmtleTExNFZrZXkxMTVWa2V5MTE2VmtleTExN1ZrZXkxMThWa2V5MTE5VWtleTEyVmtleTEyMFZrZXkxMjFWa2V5MTIyVmtleTEyM1ZrZXkxMjRWa2V5MTI1VmtleTEyNlZrZXkxMjdWa2V5MTI4VmtleTEyOVVrZXkxM1ZrZXkxMzBWa2V5MTMxVmtleTEzMlZrZXkxMzNWa2V5MTM0VmtleTEzNVZrZXkxMzZWa2V5MTM3VmtleTEzOFZrZXkxMzlVa2V5MTRWa2V5MTQwVmtleTE0MVZrZXkxNDJWa2V5MTQzVmtleTE0NFZrZXkxNDVWa2V5MTQ2VmtleTE0N1ZrZXkxNDhWa2V5MTQ5VWtleTE1VmtleTE1MFZrZXkxNTFWa2V5MTUyVmtleTE1M1ZrZXkxNTRWa2V5MTU1VmtleTE1NlZrZXkxNTdWa2V5MTU4VmtleTE1OVVrZXkxNlZrZXkxNjBWa2V5MTYxVmtleTE2MlZrZXkxNjNWa2V5MTY0VmtleTE2NVZrZXkxNjZWa2V5MTY3VmtleTE2OFZrZXkxNjlVa2V5MTdWa2V5MTcwVmtleTE3MVZrZXkxNzJWa2V5MTczVmtleTE3NFZrZXkxNzVWa2V5MTc2VmtleTE3N1ZrZXkxNzhWa2V5MTc5VWtleTE4VmtleTE4MFZrZXkxODFWa2V5MTgyVmtleTE4M1ZrZXkxODRWa2V5MTg1VmtleTE4NlZrZXkxODdWa2V5MTg4VmtleTE4OVVrZXkxOVZrZXkxOTBWa2V5MTkxVmtleTE5MlZrZXkxOTNWa2V5MTk0VmtleTE5NVZrZXkxOTZWa2V5MTk3VmtleTE5OFZrZXkxOTlUa2V5MlVrZXkyMFZrZXkyMDBWa2V5MjAxVmtleTIwMlZrZXkyMDNWa2V5MjA0VmtleTIwNVZrZXkyMDZWa2V5MjA3VmtleTIwOFZrZXkyMDlVa2V5MjFWa2V5MjEwVmtleTIxMVZrZXkyMTJWa2V5MjEzVmtleTIxNFZrZXkyMTVWa2V5MjE2VmtleTIxN1ZrZXkyMThWa2V5MjE5VWtleTIyVmtleTIyMFZrZXkyMjFWa2V5MjIyVmtleTIyM1ZrZXkyMjRWa2V5MjI1VmtleTIyNlZrZXkyMjdWa2V5MjI4VmtleTIyOVVrZXkyM1ZrZXkyMzBWa2V5MjMxVmtleTIzMlZrZXkyMzNWa2V5MjM0VmtleTIzNVZrZXkyMzZWa2V5MjM3VmtleTIzOFZrZXkyMzlVa2V5MjRWa2V5MjQwVmtleTI0MVZrZXkyNDJWa2V5MjQzVmtleTI0NFZrZXkyNDVWa2V5MjQ2VmtleTI0N1ZrZXkyNDhWa2V5MjQ5VWtleTI1VmtleTI1MFZrZXkyNTFWa2V5MjUyVmtleTI1M1ZrZXkyNTRWa2V5MjU1VmtleTI1NlZrZXkyNTdWa2V5MjU4VmtleTI1OVVrZXkyNlZrZXkyNjBWa2V5MjYxVmtleTI2MlZrZXkyNjNWa2V5MjY0VmtleTI2NVZrZXkyNjZWa2V5MjY3VmtleTI2OFZrZXkyNjlVa2V5MjdWa2V5MjcwVmtleTI3MVZrZXkyNzJWa2V5MjczVmtleTI3NFZrZXkyNzVWa2V5Mjc2VmtleTI3N1ZrZXkyNzhWa2V5Mjc5VWtleTI4VmtleTI4MFZrZXkyODFWa2V5MjgyVmtleTI4M1ZrZXkyODRWa2V5Mjg1VmtleTI4NlZrZXkyODdWa2V5Mjg4VmtleTI4OVVrZXkyOVZrZXkyOTBWa2V5MjkxVmtleTI5MlZrZXkyOTNWa2V5Mjk0VmtleTI5NVZrZXkyOTZWa2V5Mjk3VmtleTI5OFZrZXkyOTlUa2V5M1VrZXkzMFVrZXkzMVVrZXkzMlVrZXkzM1VrZXkzNFVrZXkzNVVrZXkzNlVrZXkzN1VrZXkzOFVrZXkzOVRrZXk0VWtleTQwVWtleTQxVWtleTQyVWtleTQzVWtleTQ0VWtleTQ1VWtleTQ2VWtleTQ3VWtleTQ4VWtleTQ5VGtleTVVa2V5NTBVa2V5NTFVa2V5NTJVa2V5NTNVa2V5NTRVa2V5NTVVa2V5NTZVa2V5NTdVa2V5NThVa2V5NTlUa2V5NlVrZXk2MFVrZXk2MVVrZXk2MlVrZXk2M1VrZXk2NFVrZXk2NVVrZXk2NlVrZXk2N1VrZXk2OFVrZXk2OVRrZXk3VWtleTcwVWtleTcxVWtleTcyVWtleTczVWtleTc0VWtleTc1VWtleTc2VWtleTc3VWtleTc4VWtleTc5VGtleThVa2V5ODBVa2V5ODFVa2V5ODJVa2V5ODNVa2V5ODRVa2V5ODVVa2V5ODZVa2V5ODdVa2V5ODhVa2V5ODlUa2V5OVVrZXk5MFVrZXk5MVVrZXk5MlVrZXk5M1VrZXk5NFVrZXk5NVVrZXk5NlVrZXk5N1VrZXk5OFVrZXk5ORAAEAEQZBEnEBEn2REopBEpcREqQBErEREr5BEsuREtkBEuaRB5ES9EETAhETEAETHhETLEETOpETSQETV5ETZkETdREJAROEAROTEROiQROxkRPBARPQkRPgQRPwERQAARQQEQqRFCBBFDCRFEEBFFGRFGJBFHMRFIQBFJURFKZBFLeRDEEUyQEU2pEU7EEU/hEVEAEVIhEVNEEVRpEVWQEVa5EOERV+QRWRERWkARW3ERXKQRXdkRXxARYEkRYYQRYsERAQARZAARZUERZoQRZ8kRaRARalkRa6QRbPERbkARb5ERASERcOQRcjkRc5ARdOkRdkQRd6EReQARemERe8QRfSkRAUQRfpARf/kRgWQRgtERhEARhbERhyQRiJkRihARi4kRAWkRjQQRjoERkAARkYERkwQRlIkRlhARl5kRmSQRmrEQBBEBkBGcQBGd0RGfZBGg+RGikBGkKRGlxBGnYRGpABGqoREBuRGsRBGt6RGvkBGxORGy5BG0kRG2QBG38RG5pBG7WREB5BG9EBG+yRHAhBHCQRHEABHFwRHHhBHJSRHLEBHM2RECERHOpBHQcRHSQBHUERHV5BHXuRHZkBHbaRHdRBHfIRECQBHhABHi4RHkxBHmqRHokBHqeRHsZBHuURHwQBHyMRECcRH0JBH2GRH4EBH6CRH8BBH+ARIAAQAAEgABAgESAAEEBBIAAQYJEQKkEgABCBASAAEKGRIAAQwkEgABDjESAAEQQBIAARJREgABFGQSAAEWeRIAARiQEgABGqkRAtkSAAEcxBIAAR7hEgABIQASAAEjIRIAASVEEgABJ2kSAAEpkBIAASu5EgABLeQSAAEwEREDEBIAATJAEgABNHESAAE2pBIAATjZEgABOxASAAE9SRIAAT+EEgABQcESAAFEABIAAUZBEQNJEgABSIQSAAFKyRIAAU0QEgABT1kSAAFRpBIAAVPxEgABVkASAAFYkRIAAVrkEgABXTkQCREDhBEDwREEABEEQREEhBEEyREFEBEFWREFpBEF8RAQEQZAEQaREQbkEQc5EQeQEQfpEQhEEQihEQkAEQlhEBkRCcQRCikRCpARCvkRC2QRC9ERDEARDLERDSQRDZkQJBEOEBEOiREPBBEPgREQABEQgRERBBERiRESEBESmRAxERMkEROxERRAERTRERVkERX5ERaQERcpERfEERhhEEARGQARGaERGkQRGukRG5ARHDkRHOQRHZERHkARHvEQUREfpBEgWREhEBEhyREihBEjQREkABEkwRElhBEmSQAIBLwEwQTGBMwE0wTaBOEE6ATvBPYE/QUEBQsFEgUYBR8FJgUtBTQFOwVCBUkFUAVXBV4FZAVrBXIFeQWABYcFjgWVBZwFowWqBbAFtwW+BcUFzAXTBdoF4QXoBe8F9gX8BgMGCgYRBhgGHwYmBi0GNAY7BkIGSAZPBlYGXQZkBmsGcgZ5BoAGhwaOBpQGmwaiBqkGsAa3Br4GxQbMBtMG2gbgBucG7gb1BvwHAwcKBxEHGAcfByYHLAczBzoHQQdIB08HVgddB2QHawdyB3gHfweGB40HlAebB6IHqQewB7cHvgfDB8kH0AfXB94H5QfsB/MH+ggBCAgIDwgVCBwIIwgqCDEIOAg/CEYITQhUCFsIYQhoCG8Idgh9CIQIiwiSCJkIoAinCK0ItAi7CMIIyQjQCNcI3gjlCOwI8wj5CQAJBwkOCRUJHAkjCSoJMQk4CT8JRQlMCVMJWglhCWgJbwl2CX0JhAmLCZEJmAmfCaYJrQm0CbsJwgnJCdAJ1wndCeQJ6wnyCfkKAAoHCg4KFQocCiMKKQowCjcKPgpFCkwKUwpaCmEKaApvCnUKfAqDCooKkQqYCp8KpgqtCrQKuwrACsYKzArSCtgK3grkCuoK8Ar2CvwLAQsHCw0LEwsZCx8LJQsrCzELNws9C0ILSAtOC1QLWgtgC2YLbAtyC3gLfguDC4kLjwuVC5sLoQunC60Lswu5C78LxAvKC9AL1gvcC+IL6AvuC/QL+gwADAUMCwwRDBcMHQwjDCkMLww1DDsMQQxGDEwMUgxYDF4MZAxqDHAMdgx8DIIMhAyGDIgMiwyODJEMlAyXDJoMnQygDKMMpgyoDKsMrgyxDLQMtwy6DL0MwAzDDMYMyAzLDM4M0QzUDNcM2gzdDOAM4wzmDOgM6wzuDPEM9Az3DPoM/Q0ADQMNBg0IDQsNDg0RDRQNFw0aDR0NIA0jDSYNKA0rDS4NMQ00DTcNOg09DUANQw1GDUkNTA1PDVINVQ1YDVsNXg1hDWQNZw1qDW0NcA1zDXYNeQ18DX8Ngg2FDYgNiw2ODZENlA2XDZoNnQ2gDaMNpg2pDawNrw2yDbUNuA27Db4NwQ3EDccNyg3MDc8N0g3VDdgN2w3eDeEN5A3nDeoN7Q3wDfMN9g35DfwN/w4CDgUOCA4LDg4OEQ4UDhcOGg4dDiAOIw4mDikOLA4vDjIONQ44DjsOPg5BDkQORw5KDk0OUA5TDlYOWQ5cDl8OYg5lDmgOaw5uDnEOdA53DnoOfQ6ADoMOhg6LDpAOlQ6aDp0Oog6nDqwOsQ62DrsOwA7FDsoOzw7SDtcO3A7hDuYO6w7wDvUO+g7/DwQPBw8MDxEPFg8bDyAPJQ8qDy8PNA85DzwPQQ9GD0sPUA9VD1oPXw9kD2kPbg9wD3MPdg95D3wPfw+CD4UPiA+LD44PkA+TD5YPmQ+cD58Pog+lD6gPqw+uD7APsw+2D7kPvA+/D8IPxQ/ID8sPzg/QD9MP1g/ZD9wP3w/iD+UP6A/rD+4P8A/zD/YP+Q/8D/8QAhAFEAgQCxAOEBAQExAWEBkQHBAfECIQJRAoECsQLhAwEDMQNhA5EDwQPxBCEEUQSBBLAAAAAAAAAgIAAAAAAAACWQAAAAAAAAAAAAAAAAAAEE4=',
                ["key0" => 0, "key1" => 1, "key10" => 100, "key100" => 10000, "key101" => 10201, "key102" => 10404, "key103" => 10609, "key104" => 10816, "key105" => 11025, "key106" => 11236, "key107" => 11449, "key108" => 11664, "key109" => 11881, "key11" => 121, "key110" => 12100, "key111" => 12321, "key112" => 12544, "key113" => 12769, "key114" => 12996, "key115" => 13225, "key116" => 13456, "key117" => 13689, "key118" => 13924, "key119" => 14161, "key12" => 144, "key120" => 14400, "key121" => 14641, "key122" => 14884, "key123" => 15129, "key124" => 15376, "key125" => 15625, "key126" => 15876, "key127" => 16129, "key128" => 16384, "key129" => 16641, "key13" => 169, "key130" => 16900, "key131" => 17161, "key132" => 17424, "key133" => 17689, "key134" => 17956, "key135" => 18225, "key136" => 18496, "key137" => 18769, "key138" => 19044, "key139" => 19321, "key14" => 196, "key140" => 19600, "key141" => 19881, "key142" => 20164, "key143" => 20449, "key144" => 20736, "key145" => 21025, "key146" => 21316, "key147" => 21609, "key148" => 21904, "key149" => 22201, "key15" => 225, "key150" => 22500, "key151" => 22801, "key152" => 23104, "key153" => 23409, "key154" => 23716, "key155" => 24025, "key156" => 24336, "key157" => 24649, "key158" => 24964, "key159" => 25281, "key16" => 256, "key160" => 25600, "key161" => 25921, "key162" => 26244, "key163" => 26569, "key164" => 26896, "key165" => 27225, "key166" => 27556, "key167" => 27889, "key168" => 28224, "key169" => 28561, "key17" => 289, "key170" => 28900, "key171" => 29241, "key172" => 29584, "key173" => 29929, "key174" => 30276, "key175" => 30625, "key176" => 30976, "key177" => 31329, "key178" => 31684, "key179" => 32041, "key18" => 324, "key180" => 32400, "key181" => 32761, "key182" => 33124, "key183" => 33489, "key184" => 33856, "key185" => 34225, "key186" => 34596, "key187" => 34969, "key188" => 35344, "key189" => 35721, "key19" => 361, "key190" => 36100, "key191" => 36481, "key192" => 36864, "key193" => 37249, "key194" => 37636, "key195" => 38025, "key196" => 38416, "key197" => 38809, "key198" => 39204, "key199" => 39601, "key2" => 4, "key20" => 400, "key200" => 40000, "key201" => 40401, "key202" => 40804, "key203" => 41209, "key204" => 41616, "key205" => 42025, "key206" => 42436, "key207" => 42849, "key208" => 43264, "key209" => 43681, "key21" => 441, "key210" => 44100, "key211" => 44521, "key212" => 44944, "key213" => 45369, "key214" => 45796, "key215" => 46225, "key216" => 46656, "key217" => 47089, "key218" => 47524, "key219" => 47961, "key22" => 484, "key220" => 48400, "key221" => 48841, "key222" => 49284, "key223" => 49729, "key224" => 50176, "key225" => 50625, "key226" => 51076, "key227" => 51529, "key228" => 51984, "key229" => 52441, "key23" => 529, "key230" => 52900, "key231" => 53361, "key232" => 53824, "key233" => 54289, "key234" => 54756, "key235" => 55225, "key236" => 55696, "key237" => 56169, "key238" => 56644, "key239" => 57121, "key24" => 576, "key240" => 57600, "key241" => 58081, "key242" => 58564, "key243" => 59049, "key244" => 59536, "key245" => 60025, "key246" => 60516, "key247" => 61009, "key248" => 61504, "key249" => 62001, "key25" => 625, "key250" => 62500, "key251" => 63001, "key252" => 63504, "key253" => 64009, "key254" => 64516, "key255" => 65025, "key256" => 65536, "key257" => 66049, "key258" => 66564, "key259" => 67081, "key26" => 676, "key260" => 67600, "key261" => 68121, "key262" => 68644, "key263" => 69169, "key264" => 69696, "key265" => 70225, "key266" => 70756, "key267" => 71289, "key268" => 71824, "key269" => 72361, "key27" => 729, "key270" => 72900, "key271" => 73441, "key272" => 73984, "key273" => 74529, "key274" => 75076, "key275" => 75625, "key276" => 76176, "key277" => 76729, "key278" => 77284, "key279" => 77841, "key28" => 784, "key280" => 78400, "key281" => 78961, "key282" => 79524, "key283" => 80089, "key284" => 80656, "key285" => 81225, "key286" => 81796, "key287" => 82369, "key288" => 82944, "key289" => 83521, "key29" => 841, "key290" => 84100, "key291" => 84681, "key292" => 85264, "key293" => 85849, "key294" => 86436, "key295" => 87025, "key296" => 87616, "key297" => 88209, "key298" => 88804, "key299" => 89401, "key3" => 9, "key30" => 900, "key31" => 961, "key32" => 1024, "key33" => 1089, "key34" => 1156, "key35" => 1225, "key36" => 1296, "key37" => 1369, "key38" => 1444, "key39" => 1521, "key4" => 16, "key40" => 1600, "key41" => 1681, "key42" => 1764, "key43" => 1849, "key44" => 1936, "key45" => 2025, "key46" => 2116, "key47" => 2209, "key48" => 2304, "key49" => 2401, "key5" => 25, "key50" => 2500, "key51" => 2601, "key52" => 2704, "key53" => 2809, "key54" => 2916, "key55" => 3025, "key56" => 3136, "key57" => 3249, "key58" => 3364, "key59" => 3481, "key6" => 36, "key60" => 3600, "key61" => 3721, "key62" => 3844, "key63" => 3969, "key64" => 4096, "key65" => 4225, "key66" => 4356, "key67" => 4489, "key68" => 4624, "key69" => 4761, "key7" => 49, "key70" => 4900, "key71" => 5041, "key72" => 5184, "key73" => 5329, "key74" => 5476, "key75" => 5625, "key76" => 5776, "key77" => 5929, "key78" => 6084, "key79" => 6241, "key8" => 64, "key80" => 6400, "key81" => 6561, "key82" => 6724, "key83" => 6889, "key84" => 7056, "key85" => 7225, "key86" => 7396, "key87" => 7569, "key88" => 7744, "key89" => 7921, "key9" => 81, "key90" => 8100, "key91" => 8281, "key92" => 8464, "key93" => 8649, "key94" => 8836, "key95" => 9025, "key96" => 9216, "key97" => 9409, "key98" => 9604, "key99" => 9801]],
            'dedup' => ['YnBsaXN0MDCmAQEBAgIDVHNhbWUQAaICAggPFBYAAAAAAAABAQAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAGQ==',
                ["same", "same", "same", 1, 1, [1, 1]]],
            'keywords' => ['YnBsaXN0MDCjAQIDXHNvbWUga2V5d29yZGQAYwBhAGYA6WoAdgBhAGMAYQBuAGMAZQBzACAmAAgMGSIAAAAAAAABAQAAAAAAAAAEAAAAAAAAAAAAAAAAAAAANw==',
                ["some keyword", "caf\u{E9}", "vacances \u{2600}"]],
            'empty_dict_list' => ['YnBsaXN0MDCiAQKgUXgICwwAAAAAAAABAQAAAAAAAAADAAAAAAAAAAAAAAAAAAAADg==',
                [[], "x"]],
            'root_scalar' => ['YnBsaXN0MDBdanVzdCBhIHN0cmluZwgAAAAAAAABAQAAAAAAAAABAAAAAAAAAAAAAAAAAAAAFg==',
                "just a string"],
            'offsets_and_refs_of_1_bytes' => ['YnBsaXN0MDCiAQJRYRABCAsNAAAAAAAAAQEAAAAAAAAAAwAAAAAAAAAAAAAAAAAAAA8=',
                ["a", 1]],
            'offsets_and_refs_of_2_bytes' => ['YnBsaXN0MDCiAAEAAlFhEAEACAANAA8AAAAAAAACAgAAAAAAAAADAAAAAAAAAAAAAAAAAAAAEQ==',
                ["a", 1]],
            'offsets_and_refs_of_3_bytes' => ['YnBsaXN0MDCiAAABAAACUWEQAQAACAAADwAAEQAAAAAAAAMDAAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAT',
                ["a", 1]],
            'offsets_and_refs_of_4_bytes' => ['YnBsaXN0MDCiAAAAAQAAAAJRYRABAAAACAAAABEAAAATAAAAAAAABAQAAAAAAAAAAwAAAAAAAAAAAAAAAAAAABU=',
                ["a", 1]],
            'offsets_and_refs_of_5_bytes' => ['YnBsaXN0MDCiAAAAAAEAAAAAAlFhEAEAAAAACAAAAAATAAAAABUAAAAAAAAFBQAAAAAAAAADAAAAAAAAAAAAAAAAAAAAFw==',
                ["a", 1]],
            'offsets_and_refs_of_6_bytes' => ['YnBsaXN0MDCiAAAAAAABAAAAAAACUWEQAQAAAAAACAAAAAAAFQAAAAAAFwAAAAAAAAYGAAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAZ',
                ["a", 1]],
            'offsets_and_refs_of_7_bytes' => ['YnBsaXN0MDCiAAAAAAAAAQAAAAAAAAJRYRABAAAAAAAACAAAAAAAABcAAAAAAAAZAAAAAAAABwcAAAAAAAAAAwAAAAAAAAAAAAAAAAAAABs=',
                ["a", 1]],
            'offsets_and_refs_of_8_bytes' => ['YnBsaXN0MDCiAAAAAAAAAAEAAAAAAAAAAlFhEAEAAAAAAAAACAAAAAAAAAAZAAAAAAAAABsAAAAAAAAICAAAAAAAAAADAAAAAAAAAAAAAAAAAAAAHQ==',
                ["a", 1]],
        ];
    }

    public static function xml_plists(): array
    {
        return [
            'xml_location' => ['PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPCFET0NUWVBFIHBsaXN0IFBVQkxJQyAiLS8vQXBwbGUvL0RURCBQTElTVCAxLjAvL0VOIiAiaHR0cDovL3d3dy5hcHBsZS5jb20vRFREcy9Qcm9wZXJ0eUxpc3QtMS4wLmR0ZCI+CjxwbGlzdCB2ZXJzaW9uPSIxLjAiPgo8ZGljdD4KCTxrZXk+YWx0PC9rZXk+Cgk8cmVhbD4wLjA8L3JlYWw+Cgk8a2V5PmxhdDwva2V5PgoJPHJlYWw+NTAuMDk0MTgzMzMzMzMzMzM8L3JlYWw+Cgk8a2V5Pmxvbjwva2V5PgoJPHJlYWw+LTEyMi44ODE1NjY2NjY2NjY2NjwvcmVhbD4KCTxrZXk+dGltZXN0YW1wPC9rZXk+Cgk8ZGF0ZT4yMDIwLTAyLTI5VDE4OjM1OjQ5WjwvZGF0ZT4KCTxrZXk+dmVydEFjYzwva2V5PgoJPHJlYWw+MC4wPC9yZWFsPgo8L2RpY3Q+CjwvcGxpc3Q+Cg==',
                ["alt" => 0.0, "lat" => 50.09418333333333, "lon" => -122.88156666666666, "timestamp" => new \DateTimeImmutable('2020-02-29 18:35:49.000000 UTC'), "vertAcc" => 0.0]],
            'xml_all_types' => ['PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPCFET0NUWVBFIHBsaXN0IFBVQkxJQyAiLS8vQXBwbGUvL0RURCBQTElTVCAxLjAvL0VOIiAiaHR0cDovL3d3dy5hcHBsZS5jb20vRFREcy9Qcm9wZXJ0eUxpc3QtMS4wLmR0ZCI+CjxwbGlzdCB2ZXJzaW9uPSIxLjAiPgo8ZGljdD4KCTxrZXk+YTwva2V5PgoJPGFycmF5PgoJCTxpbnRlZ2VyPjE8L2ludGVnZXI+CgkJPHJlYWw+Mi41PC9yZWFsPgoJCTx0cnVlLz4KCQk8ZmFsc2UvPgoJCTxzdHJpbmc+dGV4dDwvc3RyaW5nPgoJCTxkYXRhPgoJCVlubDBaWE09CgkJPC9kYXRhPgoJCTxkYXRlPjIwMjEtMDMtMDRUMDU6MDY6MDdaPC9kYXRlPgoJPC9hcnJheT4KCTxrZXk+YmlnPC9rZXk+Cgk8aW50ZWdlcj45MjIzMzcyMDM2ODU0Nzc1ODA4PC9pbnRlZ2VyPgoJPGtleT5lPC9rZXk+Cgk8ZGljdC8+Cgk8a2V5Pmg8L2tleT4KCTxpbnRlZ2VyPjB4MTE8L2ludGVnZXI+Cgk8a2V5Pm48L2tleT4KCTxpbnRlZ2VyPi00MjwvaW50ZWdlcj4KCTxrZXk+dTwva2V5PgoJPHN0cmluZz5jYWbDqSDigqwgJmFtcDsmbHQ7Jmd0Ozwvc3RyaW5nPgo8L2RpY3Q+CjwvcGxpc3Q+Cg==',
                ["a" => [1, 2.5, true, false, "text", base64_decode('Ynl0ZXM='), new \DateTimeImmutable('2021-03-04 05:06:07.000000 UTC')], "big" => gmp_init('9223372036854775808'), "e" => [], "h" => 17, "n" => -42, "u" => "caf\u{E9} \u{20AC} &<>"]],
        ];
    }
}
