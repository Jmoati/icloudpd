<?php

namespace Tests;

use Icloudpd\StringHelpers;

/** `TruncateMiddleTestCase` and `ParseTimestampeOrTimeDeltaTestCase` of `tests/test_string_helpers.py` */
class StringHelpersTest extends TestCase
{
    // class TruncateMiddleTestCase

    public function test_truncate_middle(): void
    {
        $this->assertSame('test_filename.jpg', StringHelpers::truncate_middle('test_filename.jpg', 50));
        $this->assertSame('test_filename.jpg', StringHelpers::truncate_middle('test_filename.jpg', 17));
        $this->assertSame('test_f...me.jpg', StringHelpers::truncate_middle('test_filename.jpg', 16));
        $this->assertSame('tes...jpg', StringHelpers::truncate_middle('test_filename.jpg', 10));
        $this->assertSame('t...g', StringHelpers::truncate_middle('test_filename.jpg', 5));
        $this->assertSame('...g', StringHelpers::truncate_middle('test_filename.jpg', 4));
        $this->assertSame('...', StringHelpers::truncate_middle('test_filename.jpg', 3));
        $this->assertSame('..', StringHelpers::truncate_middle('test_filename.jpg', 2));
        $this->assertSame('.', StringHelpers::truncate_middle('test_filename.jpg', 1));
        $this->assertSame('', StringHelpers::truncate_middle('test_filename.jpg', 0));
        $this->expectException(\ValueError::class);
        StringHelpers::truncate_middle('test_filename.jpg', -1);
    }

    // class ParseTimestampeOrTimeDeltaTestCase

    public function test_totality(): void
    {
        $characters = array_merge(range('a', 'z'), range('A', 'Z'), range('0', '9'));
        for ($_case = 0; $_case < 500; $_case++) {
            $target_length = random_int(0, 100);
            $target_string = '';
            for ($i = 0; $i < $target_length; $i++) {
                $target_string .= $characters[array_rand($characters)];
            }
            StringHelpers::parse_timestamp_or_timedelta($target_string);
            //  not throwing is okay
        }
        $this->addToAssertionCount(1);
    }

    public function test_naive(): void
    {
        // a naive datetime is a datetime in the local time zone (see PORTING.md)
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 03:04:05.000600'),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02T03:04:05.000600'),
        );
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 03:04:05.006000'),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02T03:04:05.006'),
        );
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 00:00:00'),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02'),
        );
    }

    public function test_naive_311plus(): void
    {
        //  short
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 03:04:05.000600'),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02T03:04:05.0006'),
        );
    }

    public function test_aware(): void
    {
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 03:04:05.000600+08:00'),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02T03:04:05.000600+08:00'),
        );
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 03:04:05.006000+08:00'),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02T03:04:05.006+08:00'),
        );
    }

    public function test_aware_311plus(): void
    {
        //  short
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 03:04:05.000600', new \DateTimeZone('UTC')),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02T03:04:05.0006Z'),
        );
        $this->assertEquals(
            new \DateTimeImmutable('2025-01-02 03:04:05.000600+08:00'),
            StringHelpers::parse_timestamp_or_timedelta('2025-01-02T03:04:05.0006+08:00'),
        );
    }

    public function test_timestamp_invalid(): void
    {
        $this->assertNull(StringHelpers::parse_timestamp_or_timedelta('2025-01'));
        $this->assertNull(StringHelpers::parse_timestamp_or_timedelta(''));
    }

    public function test_delta_totality(): void
    {
        $digits = range('0', '9');
        $characters = ['d', 'D'];
        for ($_case = 0; $_case < 500; $_case++) {
            $target_length = random_int(1, 5);
            $target_string = '';
            for ($i = 0; $i < $target_length; $i++) {
                $target_string .= $digits[array_rand($digits)];
            }
            $target_string .= $characters[array_rand($characters)];
            $result = StringHelpers::parse_timestamp_or_timedelta($target_string);
            $this->assertInstanceOf(\DateInterval::class, $result);
        }
    }

    public function test_delta(): void
    {
        $this->assertEquals(new \DateInterval('P1D'), StringHelpers::parse_timestamp_or_timedelta('1d'));
        $this->assertEquals(new \DateInterval('P0D'), StringHelpers::parse_timestamp_or_timedelta('0d'));
    }

    public function test_delta_invalid(): void
    {
        $this->assertNull(StringHelpers::parse_timestamp_or_timedelta('-1d'));
        $this->assertNull(StringHelpers::parse_timestamp_or_timedelta(''));
    }
}
