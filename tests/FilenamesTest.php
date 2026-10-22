<?php

namespace Tests;

use Icloudpd\Base;
use PyicloudIpd\AssetVersion\AssetVersion;
use PyicloudIpd\Services\Photos\PhotoAsset;
use PyicloudIpd\Utils;
use PyicloudIpd\VersionSize\AssetVersionSize;
use PyicloudIpd\VersionSize\LivePhotoVersionSize;

class FilenamesTest extends TestCase
{
    /** Create a mock PhotoAsset for testing. */
    private static function create_mock_photo_asset(string $base_filename = 'IMG_1'): PhotoAsset
    {
        return new class($base_filename) extends PhotoAsset {
            public function __construct(private string $base_filename)
            {
            }

            /** Mock implementation of calculate_version_filename. */
            public function calculate_version_filename(
                AssetVersion $version,
                AssetVersionSize|LivePhotoVersionSize $version_size,
                callable $lp_filename_generator,
                ?string $filename_override = null,
            ): string {
                if ($filename_override !== null) {
                    return $filename_override;
                }

                // Simple mock logic - use type to determine extension
                if ($version->type == 'com.apple.quicktime-movie') {
                    return "{$this->base_filename}.MOV";
                } elseif ($version->type == 'public.heic') {
                    return "{$this->base_filename}.HEIC";
                } elseif ($version->type == 'public.jpeg') {
                    return "{$this->base_filename}.JPG";
                } elseif ($version->type == 'com.adobe.raw-image') {
                    return "{$this->base_filename}.DNG";
                } elseif ($version->type == 'com.canon.cr2-raw-image') {
                    return "{$this->base_filename}.CR2";
                } else {
                    return "{$this->base_filename}.JPG"; // default
                }
            }
        };
    }

    /**
     * `Dict[VersionSize, AssetVersion]` is a WeakMap keyed by the enum cases
     *
     * @param list<array{AssetVersionSize|LivePhotoVersionSize, AssetVersion}> $pairs
     */
    private static function versions(array $pairs): \WeakMap
    {
        $map = new \WeakMap();
        foreach ($pairs as [$size, $version]) {
            $map[$size] = $version;
        }
        return $map;
    }

    /**
     * A WeakMap as an array ("Class::CASE" => value), what `assertEqual` of two dicts compares
     *
     * @return array<string, mixed>
     */
    private static function entries(\WeakMap $map): array
    {
        $entries = [];
        foreach ($map as $size => $value) {
            $entries[$size::class . '::' . $size->name] = $value;
        }
        ksort($entries);
        return $entries;
    }

    public function test_disambiguate_filenames_all_diff(): void
    {
        /** want all and they are all different (probably unreal case) */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.adobe.raw-image', 'blah')],
                [AssetVersionSize::ALTERNATIVE, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        $_expect = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.adobe.raw-image', 'blah')],
                [AssetVersionSize::ALTERNATIVE, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ORIGINAL, AssetVersionSize::ALTERNATIVE, AssetVersionSize::ADJUSTED],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );
        $this->assertEquals(self::entries($_expect), self::entries($_result));
        $this->assertSame([], self::entries($_overrides));
    }

    public function test_disambiguate_filenames_keep_orgraw_alt_adj(): void
    {
        /** keep originals as raw, keep alt as well, but edit alt; edits are the same file type - alternative will be renamed */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.adobe.raw-image', 'blah')],
                [AssetVersionSize::ALTERNATIVE, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ORIGINAL, AssetVersionSize::ALTERNATIVE, AssetVersionSize::ADJUSTED],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );

        // Check that alternative version gets renamed due to filename conflict
        $this->assertSame(3, count($_result));
        $this->assertTrue(isset($_result[AssetVersionSize::ORIGINAL]));
        $this->assertTrue(isset($_result[AssetVersionSize::ALTERNATIVE]));
        $this->assertTrue(isset($_result[AssetVersionSize::ADJUSTED]));

        // Alternative should have filename override to disambiguate
        $this->assertNotNull($_overrides[AssetVersionSize::ALTERNATIVE] ?? null);
    }

    public function test_disambiguate_filenames_keep_latest(): void
    {
        /** just get the latest version */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        $_expect = self::versions([
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.heic', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ADJUSTED],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );
        $this->assertEquals(self::entries($_expect), self::entries($_result));
        $this->assertSame([], self::entries($_overrides));
    }

    public function test_disambiguate_filenames_keep_org_adj_diff(): void
    {
        /** keep then as is */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        $_expect = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ORIGINAL, AssetVersionSize::ADJUSTED],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );
        $this->assertEquals(self::entries($_expect), self::entries($_result));
        $this->assertSame([], self::entries($_overrides));
    }

    public function test_disambiguate_filenames_keep_org_alt_diff(): void
    {
        /** keep then as is */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.adobe.raw-image', 'blah')],
                [AssetVersionSize::ALTERNATIVE, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        $_expect = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.adobe.raw-image', 'blah')],
                [AssetVersionSize::ALTERNATIVE, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ORIGINAL, AssetVersionSize::ALTERNATIVE],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );
        $this->assertEquals(self::entries($_expect), self::entries($_result));
        $this->assertSame([], self::entries($_overrides));
    }

    public function test_disambiguate_filenames_keep_all_when_org_adj_same(): void
    {
        /** tweak adj */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::ALTERNATIVE, new AssetVersion(1, 'http', 'com.canon.cr2-raw-image', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ORIGINAL, AssetVersionSize::ADJUSTED, AssetVersionSize::ALTERNATIVE],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );

        // Check that adjusted version gets renamed due to filename conflict with original
        $this->assertSame(3, count($_result));
        $this->assertTrue(isset($_result[AssetVersionSize::ORIGINAL]));
        $this->assertTrue(isset($_result[AssetVersionSize::ALTERNATIVE]));
        $this->assertTrue(isset($_result[AssetVersionSize::ADJUSTED]));

        // Adjusted should have filename override to disambiguate from original
        $this->assertNotNull($_overrides[AssetVersionSize::ADJUSTED] ?? null);
    }

    public function test_disambiguate_filenames_keep_org_alt_missing(): void
    {
        /** keep alt when it is missing */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::ADJUSTED, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        $_expect = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.heic', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ORIGINAL, AssetVersionSize::ALTERNATIVE],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );
        $this->assertEquals(self::entries($_expect), self::entries($_result));
        $this->assertSame([], self::entries($_overrides));
    }

    public function test_disambiguate_filenames_keep_alt_missing(): void
    {
        /** keep alt when it is missing */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ALTERNATIVE],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );

        // Should get original as alternative since alternative is missing
        $this->assertSame(1, count($_result));
        $this->assertTrue(isset($_result[AssetVersionSize::ALTERNATIVE]));
        $this->assertSame([], self::entries($_overrides));
    }

    public function test_disambiguate_filenames_keep_adj_alt_missing(): void
    {
        /** keep alt when it is missing */
        $mock_photo = self::create_mock_photo_asset();

        $_setup = self::versions([
                [AssetVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'public.heic', 'blah')],
                [AssetVersionSize::MEDIUM, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [AssetVersionSize::THUMB, new AssetVersion(1, 'http', 'public.jpeg', 'blah')],
                [LivePhotoVersionSize::ORIGINAL, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::MEDIUM, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
                [LivePhotoVersionSize::THUMB, new AssetVersion(1, 'http', 'com.apple.quicktime-movie', 'blah')],
            ]);
        [$_result, $_overrides] = Utils::disambiguate_filenames(
            $_setup,
            [AssetVersionSize::ADJUSTED, AssetVersionSize::ALTERNATIVE],
            $mock_photo,
            Base::lp_filename_concatinator(...),
        );

        // Should get original as both adjusted and alternative since both are missing
        $this->assertSame(2, count($_result));
        $this->assertTrue(isset($_result[AssetVersionSize::ADJUSTED]));
        $this->assertTrue(isset($_result[AssetVersionSize::ALTERNATIVE]));
        $this->assertSame([], self::entries($_overrides));
    }
}
