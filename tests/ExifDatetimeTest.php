<?php

namespace Tests;

use Foundation\Logging;
use Foundation\Logging\Logger;
use Foundation\Logging\StreamHandler;
use Foundation\Patch;
use Icloudpd\ExifDatetime;
use Icloudpd\ExifDatetime\InvalidImageDataError;
use Image\ExifTool\ExifTool;

/**
 * Not in upstream (its tests patch `piexif.load` and `piexif.insert` and run the whole CLI): the port of
 * `icloudpd/exif_datetime.py` on image-exiftool, checked against piexif of the upstream virtualenv.
 */
class ExifDatetimeTest extends TestCase
{
    private const DATE = '2018:07:31 07:22:24';

    /** an 8x8 JPEG from GD, without any EXIF */
    private const NO_EXIF_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=';

    /** @var list<string> */
    private array $tmp_dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp_dirs as $dir) {
            foreach (glob("$dir/*") as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tmp_dirs = [];
        parent::tearDown();
    }

    private function tmp_dir(): string
    {
        $dir = sys_get_temp_dir().'/icloudpd-exif-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->tmp_dirs[] = $dir;

        return $dir;
    }

    /** a copy of a JPEG fixture in a temporary directory: [path to modify, path of the untouched original] */
    private function fixture(string $name): array
    {
        $dir = $this->tmp_dir();
        if ($name === 'no_exif.jpg') {
            file_put_contents("$dir/original.jpg", base64_decode(self::NO_EXIF_JPEG));
        } else {
            copy(__DIR__.'/data/exif/'.$name, "$dir/original.jpg");
        }
        copy("$dir/original.jpg", "$dir/photo.jpg");

        return ["$dir/photo.jpg", "$dir/original.jpg"];
    }

    /** @return array{Logger, resource} */
    private function logger(): array
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new Logger('exif-datetime-test', Logging::DEBUG);
        $logger->addHandler(new StreamHandler($stream));

        return [$logger, $stream];
    }

    private function logged($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    /** the three tags as ExifTool reads them */
    private function tags(string $path): array
    {
        $info = (new ExifTool())->ImageInfo($path, 'IFD1:ModifyDate', 'ExifIFD:DateTimeOriginal', 'ExifIFD:CreateDate', ['PrintConv' => 0]);

        // the tag of the IFD1 comes with a suffix when IFD0 has a ModifyDate too
        return [$info['ModifyDate (1)'] ?? $info['ModifyDate'] ?? null, $info['DateTimeOriginal'] ?? null, $info['CreateDate'] ?? null];
    }

    /**
     * What `piexif.load` of the upstream virtualenv says about a file (tags 306 in "1st", 36867 and 36868 in "Exif",
     * and whether the rest of the IFDs and the thumbnail are the ones of `$original`), or null without the virtualenv.
     */
    private function piexif(string $path, ?string $original = null): ?array
    {
        $python = getenv('HOME').'/icloud_photos_downloader/.venv/bin/python';
        if (!is_executable($python)) {
            return null;
        }
        $script = <<<'PYTHON'
import json, sys
import piexif
d = piexif.load(sys.argv[1])
s = lambda v: v.decode() if isinstance(v, bytes) else v
out = {"mod_1st": s(d["1st"].get(306)), "date_time_original": s(d["Exif"].get(36867)), "create_date": s(d["Exif"].get(36868))}
if len(sys.argv) > 2:
    o = piexif.load(sys.argv[2])
    # the offsets of the sub-IFDs and of the thumbnail move when an IFD grows, the rest stays
    moved = {34665, 34853, 40965, 513, 514}
    out["0th_unchanged"] = {k: v for k, v in o["0th"].items() if k not in moved} == {k: v for k, v in d["0th"].items() if k not in moved}
    out["thumbnail_unchanged"] = o["thumbnail"] == d["thumbnail"]
print(json.dumps(out))
PYTHON;
        $process = proc_open(array_merge([$python, '-I', '-c', $script, $path], $original !== null ? [$original] : []), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);
        $this->assertNotSame('', $stdout, $stderr);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function jpegs(): array
    {
        return [
            // EXIF with a date and a thumbnail (IFD1)
            'olympus_c8080wz.jpg' => ['olympus_c8080wz.jpg', '2006:10:22 15:44:29'],
            // EXIF and a thumbnail, no date
            'no_date_with_thumbnail.jpg' => ['no_date_with_thumbnail.jpg', null],
            'no_exif.jpg' => ['no_exif.jpg', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jpegs')]
    public function test_get_photo_exif(string $name, ?string $expected): void
    {
        [$logger, $stream] = $this->logger();
        [$path] = $this->fixture($name);

        $this->assertSame($expected, ExifDatetime::get_photo_exif($logger, $path));
        $this->assertSame('', $this->logged($stream));
        $python = $this->piexif($path);
        if ($python !== null) {
            $this->assertSame($expected, $python['date_time_original']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jpegs')]
    public function test_set_photo_exif(string $name, ?string $_date_before = null): void
    {
        [$logger, $stream] = $this->logger();
        [$path, $original] = $this->fixture($name);

        ExifDatetime::set_photo_exif($logger, $path, self::DATE);

        $this->assertSame('', $this->logged($stream));
        $this->assertSame(self::DATE, ExifDatetime::get_photo_exif($logger, $path));
        $this->assertSame([self::DATE, self::DATE, self::DATE], $this->tags($path));
        // piexif reads the same string in the three tags (306 of the 1st IFD, 36867 and 36868 of the Exif IFD)
        $python = $this->piexif($path, $original);
        if ($python === null) {
            $this->markTestIncomplete('the upstream virtualenv (~/icloud_photos_downloader/.venv) is not there: piexif not compared');
        }
        $this->assertSame(
            ['mod_1st' => self::DATE, 'date_time_original' => self::DATE, 'create_date' => self::DATE],
            array_slice($python, 0, 3)
        );
        if ($name !== 'no_exif.jpg') {
            // what was in the file is still there (ExifTool keeps the thumbnail as it is, piexif strips its JFIF header)
            $this->assertTrue($python['0th_unchanged']);
            $this->assertTrue($python['thumbnail_unchanged']);
        }
    }

    public function test_set_photo_exif_twice_keeps_the_last_date(): void
    {
        [$logger] = $this->logger();
        [$path] = $this->fixture('olympus_c8080wz.jpg');

        ExifDatetime::set_photo_exif($logger, $path, self::DATE);
        ExifDatetime::set_photo_exif($logger, $path, '2020:02:29 18:35:49');

        $this->assertSame('2020:02:29 18:35:49', ExifDatetime::get_photo_exif($logger, $path));
        $this->assertSame(['2020:02:29 18:35:49', '2020:02:29 18:35:49', '2020:02:29 18:35:49'], $this->tags($path));
    }

    /** a file that is not an image: nothing is thrown, `null` and a debug message (piexif: InvalidImageDataError) */
    public function test_get_photo_exif_not_an_image(): void
    {
        [$logger, $stream] = $this->logger();
        $dir = $this->tmp_dir();
        foreach (['text.jpg' => 'hello', 'empty.jpg' => '', 'png.jpg' => "\x89PNG\r\n\x1a\n".str_repeat("\0", 32)] as $name => $content) {
            file_put_contents("$dir/$name", $content);

            $this->assertNull(ExifDatetime::get_photo_exif($logger, "$dir/$name"));
        }
        // ponytail: an unreadable file is "not an image" too (piexif lets the OSError out)
        $this->assertNull(ExifDatetime::get_photo_exif($logger, "$dir/missing.jpg"));

        $this->assertSame(
            "Error fetching EXIF data for $dir/text.jpg\nError fetching EXIF data for $dir/empty.jpg\n"
            ."Error fetching EXIF data for $dir/png.jpg\nError fetching EXIF data for $dir/missing.jpg\n",
            $this->logged($stream)
        );
    }

    public function test_set_photo_exif_not_an_image(): void
    {
        [$logger, $stream] = $this->logger();
        $path = $this->tmp_dir().'/text.jpg';
        file_put_contents($path, 'hello');

        ExifDatetime::set_photo_exif($logger, $path, self::DATE);

        $this->assertSame('hello', file_get_contents($path));
        $this->assertSame("Error setting EXIF data for $path\n", $this->logged($stream));
    }

    /** a truncated JPEG: ExifTool cannot write it, the file is left alone and the error is logged */
    public function test_set_photo_exif_truncated_jpeg(): void
    {
        [$logger, $stream] = $this->logger();
        $path = $this->tmp_dir().'/truncated.jpg';
        $content = substr(file_get_contents(__DIR__.'/data/exif/olympus_c8080wz.jpg'), 0, 300);
        file_put_contents($path, $content);

        ExifDatetime::set_photo_exif($logger, $path, self::DATE);

        $this->assertSame($content, file_get_contents($path));
        $this->assertSame("Error setting EXIF data for $path\n", $this->logged($stream));
    }

    /** `mock.patch.object(piexif, "load")` with `side_effect = InvalidImageDataError` */
    public function test_piexif_load_exceptions(): void
    {
        Patch::set('Icloudpd\ExifDatetime::piexif_load', function (...$_args): never {
            throw new InvalidImageDataError();
        });
        [$logger, $stream] = $this->logger();
        [$path] = $this->fixture('olympus_c8080wz.jpg');

        $this->assertNull(ExifDatetime::get_photo_exif($logger, $path));
        ExifDatetime::set_photo_exif($logger, $path, self::DATE);

        $this->assertSame("Error fetching EXIF data for $path\nError setting EXIF data for $path\n", $this->logged($stream));
        Patch::clear();
        $this->assertSame('2006:10:22 15:44:29', ExifDatetime::get_photo_exif($logger, $path));
    }

    /** `mock.patch.object(piexif, "insert")` with `side_effect = InvalidImageDataError` */
    public function test_piexif_insert_exceptions(): void
    {
        Patch::set('Icloudpd\ExifDatetime::piexif_insert', function (...$_args): never {
            throw new InvalidImageDataError();
        });
        [$logger, $stream] = $this->logger();
        [$path] = $this->fixture('olympus_c8080wz.jpg');

        ExifDatetime::set_photo_exif($logger, $path, self::DATE);

        $this->assertSame("Error setting EXIF data for $path\n", $this->logged($stream));
        $this->assertSame('2006:10:22 15:44:29', ExifDatetime::get_photo_exif($logger, $path));
    }

    /** `mock.patch("icloudpd.exif_datetime.get_photo_exif")` with `return_value = False` */
    public function test_get_photo_exif_patched(): void
    {
        Patch::set('Icloudpd\ExifDatetime::get_photo_exif', fn ($logger, $path) => false);
        [$logger] = $this->logger();

        $this->assertFalse((bool) ExifDatetime::get_photo_exif($logger, '/nowhere.jpg'));
    }
}
