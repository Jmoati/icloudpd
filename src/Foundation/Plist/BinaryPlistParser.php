<?php

namespace Foundation\Plist;

/**
 * Read a binary plist file, following the description of the binary
 * format.  Raise InvalidFileException in case of error, otherwise return the
 * root object.
 *
 * see also: http://opensource.apple.com/source/CF/CF-744.18/CFBinaryPList.c
 *
 * ponytail: a plist that references one of its own containers (a cycle, which Python builds as a
 * self-referencing list) is rejected with InvalidFileException, PHP arrays cannot hold themselves.
 */
final class BinaryPlistParser
{
    private string $_fp = '';
    private int $_pos = 0;
    private int $_ref_size = 0;
    /** @var list<int> */
    private array $_object_offsets = [];
    /** @var array<int, mixed> */
    private array $_objects = [];
    /** @var array<int, true> */
    private array $_reading = [];

    public function parse(string $fp): mixed
    {
        try {
            // The basic file format:
            // HEADER
            // object...
            // refid->offset...
            // TRAILER
            $this->_fp = $fp;
            if (strlen($fp) < 32) {
                throw new InvalidFileException();
            }
            $trailer = substr($fp, -32);
            // '>6xBBQQQ'
            $t = unpack('Coffset_size/Cref_size/Jnum_objects/Jtop_object/Joffset_table_offset', $trailer, 6);
            $offset_size = $t['offset_size'];
            $this->_ref_size = $t['ref_size'];
            if ($t['num_objects'] < 0 || $t['top_object'] < 0 || $t['offset_table_offset'] < 0) {
                throw new InvalidFileException();
            }
            $this->_pos = $t['offset_table_offset'];
            $this->_object_offsets = $this->_read_ints($t['num_objects'], $offset_size);
            $this->_objects = [];
            $this->_reading = [];

            return $this->_read_object($t['top_object']);
        } catch (InvalidFileException $e) {
            throw $e;
        } catch (\ValueError|\ArithmeticError|\Exception) {
            throw new InvalidFileException();
        }
    }

    private function _read_raw(int $size): string
    {
        if ($size < 0 || $this->_pos + $size > strlen($this->_fp)) {
            throw new InvalidFileException();
        }
        $data = substr($this->_fp, $this->_pos, $size);
        $this->_pos += $size;

        return $data;
    }

    /** return the size of the next object. */
    private function _get_size(int $tokenL): int
    {
        if ($tokenL === 0xF) {
            $m = ord($this->_read_raw(1)) & 0x3;
            $s = 1 << $m;
            $f = ['C', 'n', 'N', 'J'][$m];

            return unpack($f, $this->_read_raw($s))[1];
        }

        return $tokenL;
    }

    private function _read(int $size): string
    {
        return $this->_read_raw($size);
    }

    /** @return list<int> */
    private function _read_ints(int $n, int $size): array
    {
        if ($size < 1 || $size > 8 || $n > intdiv(strlen($this->_fp), $size)) {
            throw new InvalidFileException();
        }
        $data = $this->_read($size * $n);
        $ints = [];
        for ($i = 0; $i < $size * $n; $i += $size) {
            $v = 0;
            for ($j = 0; $j < $size; $j++) {
                $v = ($v << 8) | ord($data[$i + $j]);
            }
            if ($v < 0) {
                // 8 bytes above 2**63 - 1: never a valid offset or reference
                throw new InvalidFileException();
            }
            $ints[] = $v;
        }

        return $ints;
    }

    /** @return list<int> */
    private function _read_refs(int $n): array
    {
        return $this->_read_ints($n, $this->_ref_size);
    }

    /** int.from_bytes(data, 'big', signed=...) */
    private static function _int_from_bytes(string $data, bool $signed): int|\GMP
    {
        $n = strlen($data);
        if ($n <= 8) {
            if ($n === 0) {
                return 0;
            }
            $v = 0;
            for ($i = 0; $i < $n; $i++) {
                $v = ($v << 8) | ord($data[$i]);
            }
            if ($signed && $n < 8 && (ord($data[0]) & 0x80)) {
                $v -= 1 << (8 * $n);
            }
            // 8 bytes: the 64-bit wrap-around is the two's complement reading
            if (!$signed && $n === 8 && $v < 0) {
                return gmp_add(gmp_pow(2, 63), $v & PHP_INT_MAX);
            }

            return $v;
        }
        $v = gmp_import($data, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        if ($signed && (ord($data[0]) & 0x80)) {
            $v = gmp_sub($v, gmp_pow(2, 8 * $n));
        }
        if (gmp_cmp($v, PHP_INT_MIN) >= 0 && gmp_cmp($v, PHP_INT_MAX) <= 0) {
            return gmp_intval($v);
        }

        return $v;
    }

    /**
     * read the object by reference.
     *
     * May recursively read sub-objects (content of an array/dict/set)
     */
    private function _read_object(int $ref): mixed
    {
        if (array_key_exists($ref, $this->_objects)) {
            return $this->_objects[$ref];
        }
        if (isset($this->_reading[$ref])) {
            throw new InvalidFileException();
        }
        if ($ref < 0 || $ref >= count($this->_object_offsets)) {
            throw new InvalidFileException();
        }

        $offset = $this->_object_offsets[$ref];
        if ($offset >= strlen($this->_fp)) {
            throw new InvalidFileException();
        }
        $this->_pos = $offset;
        $token = ord($this->_read_raw(1));
        $tokenH = $token & 0xF0;
        $tokenL = $token & 0x0F;

        if ($token === 0x00) {
            $result = null;
        } elseif ($token === 0x08) {
            $result = false;
        } elseif ($token === 0x09) {
            $result = true;
        }

        // The referenced source code also mentions URL (0x0c, 0x0d) and
        // UUID (0x0e), but neither can be generated using the Cocoa libraries.

        elseif ($token === 0x0F) {
            $result = '';
        } elseif ($tokenH === 0x10) {  // int
            $result = self::_int_from_bytes($this->_read_raw(1 << $tokenL), $tokenL >= 3);
        } elseif ($token === 0x22) {  // real
            $result = unpack('G', $this->_read_raw(4))[1];
        } elseif ($token === 0x23) {  // real
            $result = unpack('E', $this->_read_raw(8))[1];
        } elseif ($token === 0x33) {  // date
            $f = unpack('E', $this->_read_raw(8))[1];
            // timestamp 0 of binary plists corresponds to 1/1/2001
            // (year of Mac OS X 10.0), instead of 1/1/1970.
            $result = self::_epoch_plus_seconds($f);
        } elseif ($tokenH === 0x40) {  // data
            $s = $this->_get_size($tokenL);
            $result = $this->_read($s);
        } elseif ($tokenH === 0x50) {  // ascii string
            $s = $this->_get_size($tokenL);
            $data = $this->_read($s);
            if (preg_match('/[\x80-\xFF]/', $data)) {
                throw new InvalidFileException();
            }
            $result = $data;
        } elseif ($tokenH === 0x60) {  // unicode string
            $s = $this->_get_size($tokenL) * 2;
            $data = $this->_read($s);
            if (!mb_check_encoding($data, 'UTF-16BE')) {
                throw new InvalidFileException();
            }
            $result = mb_convert_encoding($data, 'UTF-8', 'UTF-16BE');
        } elseif ($tokenH === 0x80) {  // UID
            // used by Key-Archiver plist files
            $result = new UID(self::_int_from_bytes($this->_read_raw(1 + $tokenL), false));
        } elseif ($tokenH === 0xA0) {  // array
            $s = $this->_get_size($tokenL);
            $obj_refs = $this->_read_refs($s);
            $result = [];
            $this->_reading[$ref] = true;
            foreach ($obj_refs as $x) {
                $result[] = $this->_read_object($x);
            }
            unset($this->_reading[$ref]);
        }

        // tokenH == 0xB0 is documented as 'ordset', but is not actually
        // implemented in the Apple reference code.

        // tokenH == 0xC0 is documented as 'set', but sets cannot be used in
        // plists.

        elseif ($tokenH === 0xD0) {  // dict
            $s = $this->_get_size($tokenL);
            $key_refs = $this->_read_refs($s);
            $obj_refs = $this->_read_refs($s);
            $result = [];
            $this->_reading[$ref] = true;
            foreach ($key_refs as $i => $k) {
                $key = $this->_read_object($k);
                // ponytail: keys that are neither str nor int (valid hashable keys in Python) are rejected
                if (!is_string($key) && !is_int($key)) {
                    throw new InvalidFileException();
                }
                $result[$key] = $this->_read_object($obj_refs[$i]);
            }
            unset($this->_reading[$ref]);
        } else {
            throw new InvalidFileException();
        }

        $this->_objects[$ref] = $result;

        return $result;
    }

    /** datetime.datetime(2001, 1, 1) + datetime.timedelta(seconds=f), naive: kept as UTC wall-clock */
    private static function _epoch_plus_seconds(float $f): \DateTimeImmutable
    {
        if (is_nan($f) || is_infinite($f) || abs($f) > 3.2e11) {
            throw new InvalidFileException();
        }
        // timedelta rounds to the nearest microsecond, half to even
        $whole = (int) $f;
        $us = (int) round(($f - $whole) * 1e6, 0, PHP_ROUND_HALF_EVEN);
        $seconds = 978307200 + $whole + intdiv($us, 1000000);
        $us %= 1000000;
        if ($us < 0) {
            $seconds--;
            $us += 1000000;
        }
        // datetime is limited to the years 1 to 9999
        if ($seconds < -62135596800 || $seconds > 253402300799) {
            throw new InvalidFileException();
        }

        return (new \DateTimeImmutable('@'.$seconds))->setTimezone(new \DateTimeZone('UTC'))->modify("+$us usec");
    }
}
