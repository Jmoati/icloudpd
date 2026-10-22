<?php

namespace Tests;

use Foundation\Foundation;
use Foundation\Logging;
use Foundation\Logging\Logger;
use Foundation\Logging\StreamHandler;
use Icloudpd\XmpSidecar;
use Icloudpd\XmpSidecar\XMPMetadata;
use PHPUnit\Framework\Attributes\DataProvider;

class XmpSidecarTest extends TestCase
{
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
        $dir = sys_get_temp_dir().'/icloudpd-xmp-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->tmp_dirs[] = $dir;

        return $dir;
    }

    /** @return array{Logger, resource} a logger writing the messages only to a memory stream */
    private function logger(): array
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new Logger('xmp-sidecar-test', Logging::DEBUG);
        $logger->addHandler(new StreamHandler($stream));

        return [$logger, $stream];
    }

    private function logged($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    /** `assertCountEqual(metadata, XMPMetadata(...))`, field by field: dates by instant, the rest strictly */
    private function assertMetadata(XMPMetadata $expected, XMPMetadata $actual): void
    {
        foreach (get_object_vars($expected) as $name => $value) {
            if ($value instanceof \DateTimeInterface) {
                $this->assertEquals($value, $actual->$name, $name);
                $this->assertSame($value->format('P'), $actual->$name->format('P'), "$name offset");
            } else {
                $this->assertSame($value, $actual->$name, $name);
            }
        }
    }

    public function test_build_metadata(): void
    {
        $assetRecordStub = [
            'fields' => [
                'captionEnc' => ['value' => 'VGl0bGUgSGVyZQ==', 'type' => 'ENCRYPTED_BYTES'],
                'extendedDescEnc' => ['value' => 'Q2FwdGlvbiBIZXJl', 'type' => 'ENCRYPTED_BYTES'],
                'adjustmentSimpleDataEnc' => [
                    'type' => 'ENCRYPTED_BYTES',
                    'value' => 'PU67DoIwFP2XMzemBRKxo5Mummiig3G4QJEaCqS9sBD+XRDjdnLeI5xhKogJeoSjwMYfjH1VDB3LKBE/7m4LrqATGUcCrbemYWLbNtDpJFC23hHfjA9fSgkMKz42ZbsUZ72ti1PvMuOhESX7nYIAdd0/g61SG7lRqZyFkFfG0cUMdhWlQFcTLzOz01F+vmKepeLdB3bzlwD9eE4f',
                ],
                'assetSubtypeV2' => ['value' => 2, 'type' => 'INT64'],
                'keywordsEnc' => [
                    'value' => 'YnBsaXN0MDChAVxzb21lIGtleXdvcmQICgAAAAAAAAEBAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAX',
                    'type' => 'ENCRYPTED_BYTES',
                ],
                'locationEnc' => [
                    'value' => 'YnBsaXN0MDDYAQIDBAUGBwgJCQoLCQwNCVZjb3Vyc2VVc3BlZWRTYWx0U2xvbld2ZXJ0QWNjU2xhdFl0aW1lc3RhbXBXaG9yekFjYyMAAAAAAAAAACNAdG9H6P0fpCNAWL2oZnRhiiNAMtKmTC+DezMAAAAAAAAAAAgZICYqLjY6RExVXmdwAAAAAAAAAQEAAAAAAAAADgAAAAAAAAAAAAAAAAAAAHk=',
                    'type' => 'ENCRYPTED_BYTES',
                ],
                'assetDate' => ['value' => 1532951050176, 'type' => 'TIMESTAMP'],
                'isHidden' => ['value' => 0, 'type' => 'INT64'],
                'isDeleted' => ['value' => 0, 'type' => 'INT64'],
                'isFavorite' => ['value' => 0, 'type' => 'INT64'],
            ],
        ];

        // Test full metadata record
        $version_info = Foundation::version_info();
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertMetadata(
            new XMPMetadata(
                XMPToolkit: 'icloudpd '.$version_info->version.'+'.$version_info->commit_sha,
                Title: 'Title Here',
                Description: 'Caption Here',
                Orientation: 8,
                Make: null,
                DigitalSourceType: null,
                Keywords: ['some keyword'],
                GPSAltitude: 326.9550561797753,
                GPSLatitude: 18.82285,
                GPSLongitude: 98.96340333333333,
                GPSSpeed: 0.0,
                // naive datetime
                GPSTimeStamp: new \DateTimeImmutable('2001-01-01 00:00:00', new \DateTimeZone('UTC')),
                CreateDate: new \DateTimeImmutable('2018-07-30 11:44:10.176000+00:00'),
                Rating: null,
            ),
            $metadata
        );

        // Some files have different types of adjustmentSimpleDataEnc which are not supported

        // - a binary plist - starting with 'bplist00', example is taken from a video file
        $assetRecordStub['fields']['adjustmentSimpleDataEnc']['value'] =
            'YnBsaXN0MDDRAQJac2xvd01vdGlvbtIDBAUWV3JlZ2lvbnNUcmF0ZaEG0QcIWXRpbWVSYW5nZdIJCgsUVXN0YXJ0WGR1cmF0aW9u1AwNDg8QERITVWZsYWdzVXZhbHVlWXRpbWVzY2FsZVVlcG9jaBABER8cEQJYEADUDA0ODxAVEhMRBAQiPoAAAAgLFhsjKCotNzxCS1RaYGpwcnV4eoOGAAAAAAAAAQEAAAAAAAAAFwAAAAAAAAAAAAAAAAAAAIs=';
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertNull($metadata->Orientation);

        // - a CRDT (Conflict-free Replicated Data Types) - starting with 'crdt', example is taken from a photo with a drawing on top
        $assetRecordStub['fields']['adjustmentSimpleDataEnc']['value'] =
            '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';
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertNull($metadata->Orientation);

        // Test Screenshot Tagging
        $assetRecordStub['fields']['assetSubtypeV2']['value'] = 3;
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertSame('Screenshot', $metadata->Make);
        $this->assertSame('screenCapture', $metadata->DigitalSourceType);

        // Test Favorites
        $assetRecordStub['fields']['isFavorite']['value'] = 1;
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertSame(5, $metadata->Rating);

        // Test favorites not present
        unset($assetRecordStub['fields']['isFavorite']);
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertNull($metadata->Rating);

        // Test Deleted
        $assetRecordStub['fields']['isDeleted']['value'] = 1;
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertSame(-1, $metadata->Rating);

        // Test Hidden
        $assetRecordStub['fields']['isDeleted']['value'] = 0;
        $assetRecordStub['fields']['isHidden']['value'] = 1;
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertSame(-1, $metadata->Rating);

        // Test locationEnc in xml format, not binary format
        // Note, this value is taken from a real photo, see https://github.com/icloud-photos-downloader/icloud_photos_downloader/issues/1059
        $assetRecordStub['fields']['locationEnc']['value'] =
            'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9InllcyI/Pgo8IURPQ1RZUEUgcGxpc3QgUFVCTElDICItLy9BcHBsZS8vRFREIFBMSVNUIDEuMC8vRU4iICJodHRwOi8vd3d3LmFwcGxlLmNvbS9EVERzL1Byb3BlcnR5TGlzdC0xLjAuZHRkIj4KPHBsaXN0IHZlcnNpb249IjEuMCI+Cgk8ZGljdD4KCQk8a2V5PnZlcnRBY2M8L2tleT4KCQk8cmVhbD4wLjA8L3JlYWw+CgoJCTxrZXk+YWx0PC9rZXk+CgkJPHJlYWw+MC4wPC9yZWFsPgoKCQk8a2V5Pmxvbjwva2V5PgoJCTxyZWFsPi0xMjIuODgxNTY2NjY2NjY2NjY8L3JlYWw+CgoJCTxrZXk+bGF0PC9rZXk+CgkJPHJlYWw+NTAuMDk0MTgzMzMzMzMzMzM8L3JlYWw+CgoJCTxrZXk+dGltZXN0YW1wPC9rZXk+CgkJPGRhdGU+MjAyMC0wMi0yOVQxODozNTo0OVo8L2RhdGU+CgoJPC9kaWN0Pgo8L3BsaXN0Pg==';
        $metadata = XmpSidecar::build_metadata($assetRecordStub);
        $this->assertSame(0.0, $metadata->GPSAltitude);
        $this->assertSame(-122.88156666666666, $metadata->GPSLongitude);
        $this->assertSame(50.09418333333333, $metadata->GPSLatitude);
        $this->assertEquals(new \DateTimeImmutable('2020-02-29T18:35:49', new \DateTimeZone('UTC')), $metadata->GPSTimeStamp);
    }

    /**
     * The XML written by Python's `generate_xmp_file` (`ElementTree.tostring(..., encoding="utf-8",
     * xml_declaration=True)`) for the same asset record, byte for byte.
     */
    #[DataProvider('xmp_records')]
    public function test_generate_xmp_file_is_identical_to_python(array $asset_record, string $expected): void
    {
        [$logger, $stream] = $this->logger();
        $download_path = $this->tmp_dir().'/IMG_0001.JPG';

        XmpSidecar::generate_xmp_file($logger, $download_path, $asset_record, false);

        $this->assertSame($expected, file_get_contents($download_path.'.xmp'));
        $this->assertSame('', $this->logged($stream));
    }

    public function test_generate_xmp_file_dry_run(): void
    {
        [$logger, $stream] = $this->logger();
        $download_path = $this->tmp_dir().'/IMG_0001.JPG';

        XmpSidecar::generate_xmp_file($logger, $download_path, ['fields' => []], true);

        $this->assertFileDoesNotExist($download_path.'.xmp');
    }

    /**
     * What Python's `generate_xmp_file` logs, and whether it overwrites, when the sidecar already exists
     * (`Not overwriting XMP file ... created by ...`): captured with the upstream code.
     */
    #[DataProvider('existing_sidecars')]
    public function test_generate_xmp_file_existing_sidecar(string $content, bool $overwritten, string $log): void
    {
        [$logger, $stream] = $this->logger();
        $download_path = $this->tmp_dir().'/IMG_100%.JPG';
        file_put_contents($download_path.'.xmp', $content);

        XmpSidecar::generate_xmp_file($logger, $download_path, ['fields' => []], false);

        $this->assertSame($overwritten, file_get_contents($download_path.'.xmp') !== $content);
        $this->assertSame($log === '' ? '' : str_replace('<PATH>', $download_path.'.xmp', $log)."\n", $this->logged($stream));
    }

    public static function existing_sidecars(): array
    {
        return [
            'other_tool' => ['<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="XMP Core 5.5.0"></x:xmpmeta>', false,
                'Not overwriting XMP file <PATH> created by XMP Core 5.5.0'],
            'own_tool' => ['<x:xml_doc xmlns:x="adobe:ns:meta/" x:xmptk="icloudpd 0.0.0+old"></x:xml_doc>', true, ''],
            'icloudpd_prefix_only' => ['<x:xml_doc xmlns:x="adobe:ns:meta/" x:xmptk="icloudpdX"/>', true, ''],
            'no_xmptk' => ['<x:xmpmeta xmlns:x="adobe:ns:meta/"/>', false, 'Not overwriting XMP file <PATH> created by None'],
            'unprefixed_xmptk' => ['<xmpmeta xmptk="icloudpd 1"/>', false, 'Not overwriting XMP file <PATH> created by None'],
            'empty_xmptk' => ['<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk=""/>', false, 'Not overwriting XMP file <PATH> created by '],
            'other_prefix' => ['<a:m xmlns:a="adobe:ns:meta/" a:xmptk="icloudpd 9"/>', true, ''],
            // a % in the message must not be formatted (a logger call without arguments)
            'percent_path_other' => ['<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="100% sure %s"/>', false,
                'Not overwriting XMP file <PATH> created by 100% sure %s'],
            'zero_size' => ['', true, ''],
        ];
    }

    /** The wording and the column of the message come from ext/xml, not from expat: only the beginning is the same */
    #[DataProvider('unparsable_sidecars')]
    public function test_generate_xmp_file_parser_error(string $content): void
    {
        [$logger, $stream] = $this->logger();
        $download_path = $this->tmp_dir().'/IMG_0001.JPG';
        file_put_contents($download_path.'.xmp', $content);

        XmpSidecar::generate_xmp_file($logger, $download_path, ['fields' => []], false);

        $this->assertSame($content, file_get_contents($download_path.'.xmp'));
        $this->assertStringStartsWith(
            "Not overwriting XMP file $download_path.xmp due to parser error: ",
            $this->logged($stream)
        );
    }

    public static function unparsable_sidecars(): array
    {
        return [
            'garbage' => ['this is not xml'],
            'unclosed' => ['<a><b></a>'],
            'unbound_prefix' => ['<x:xml_doc x:xmptk="icloudpd 1"/>'],
        ];
    }

    public static function xmp_records(): array
    {
        return [
            'full_record' => [
                [
                    'fields' => [
                        'captionEnc' => ['value' => 'VGl0bGUgSGVyZQ==', 'type' => 'ENCRYPTED_BYTES'],
                        'extendedDescEnc' => ['value' => 'Q2FwdGlvbiBIZXJl', 'type' => 'ENCRYPTED_BYTES'],
                        'adjustmentSimpleDataEnc' => ['value' => 'PU67DoIwFP2XMzemBRKxo5Mummiig3G4QJEaCqS9sBD+XRDjdnLeI5xhKogJeoSjwMYfjH1VDB3LKBE/7m4LrqATGUcCrbemYWLbNtDpJFC23hHfjA9fSgkMKz42ZbsUZ72ti1PvMuOhESX7nYIAdd0/g61SG7lRqZyFkFfG0cUMdhWlQFcTLzOz01F+vmKepeLdB3bzlwD9eE4f', 'type' => 'ENCRYPTED_BYTES'],
                        'assetSubtypeV2' => ['value' => 2, 'type' => 'INT64'],
                        'keywordsEnc' => ['value' => 'YnBsaXN0MDChAVxzb21lIGtleXdvcmQICgAAAAAAAAEBAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAX', 'type' => 'ENCRYPTED_BYTES'],
                        'locationEnc' => ['value' => 'YnBsaXN0MDDYAQIDBAUGBwgJCQoLCQwNCVZjb3Vyc2VVc3BlZWRTYWx0U2xvbld2ZXJ0QWNjU2xhdFl0aW1lc3RhbXBXaG9yekFjYyMAAAAAAAAAACNAdG9H6P0fpCNAWL2oZnRhiiNAMtKmTC+DezMAAAAAAAAAAAgZICYqLjY6RExVXmdwAAAAAAAAAQEAAAAAAAAADgAAAAAAAAAAAAAAAAAAAHk=', 'type' => 'ENCRYPTED_BYTES'],
                        'assetDate' => ['value' => 1532951050176, 'type' => 'TIMESTAMP'],
                        'isHidden' => ['value' => 0, 'type' => 'INT64'],
                        'isDeleted' => ['value' => 0, 'type' => 'INT64'],
                        'isFavorite' => ['value' => 0, 'type' => 'INT64'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">" .
                "<dc:title>Title Here</dc:title>" .
                "<dc:description>Caption Here</dc:description>" .
                "<dc:subject>" .
                "<rdf:Seq>" .
                "<rdf:li>some keyword</rdf:li>" .
                "</rdf:Seq>" .
                "</dc:subject>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:exif=\"http://ns.adobe.com/exif/1.0/\">" .
                "<exif:GPSAltitude>326.9550561797753</exif:GPSAltitude>" .
                "<exif:GPSLatitude>18.82285</exif:GPSLatitude>" .
                "<exif:GPSLongitude>98.96340333333333</exif:GPSLongitude>" .
                "<exif:GPSTimeStamp>2001-01-01T00:00:00</exif:GPSTimeStamp>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:photoshop=\"http://ns.adobe.com/photoshop/1.0/\">" .
                "<photoshop:DateCreated>2018-07-30T11:44:10+0000</photoshop:DateCreated>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:tiff=\"http://ns.adobe.com/tiff/1.0/\">" .
                "<tiff:Orientation>8</tiff:Orientation>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">" .
                "<xmp:CreateDate>2018-07-30T11:44:10+0000</xmp:CreateDate>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'screenshot_favorite_timezone' => [
                [
                    'fields' => [
                        'captionEnc' => ['value' => 'Q2FwdHVyZSBk4oCZw6ljcmFu', 'type' => 'ENCRYPTED_BYTES'],
                        'assetSubtypeV2' => ['value' => 3, 'type' => 'INT64'],
                        'assetDate' => ['value' => 1700000000999, 'type' => 'TIMESTAMP'],
                        'timeZoneOffset' => ['value' => -18000, 'type' => 'INT64'],
                        'isFavorite' => ['value' => 1, 'type' => 'INT64'],
                        'adjustmentSimpleDataEnc' => ['value' => 'q1bKTS1JTEksSVSyUqhWyi/KTM0rSSzJzM8D8s1qawE=', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">" .
                "<dc:title>Capture d’écran</dc:title>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:Iptc4xmpExt=\"http://iptc.org/std/Iptc4xmpExt/2008-02-29/\">" .
                "<Iptc4xmpExt:DigitalSourceType>screenCapture</Iptc4xmpExt:DigitalSourceType>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:photoshop=\"http://ns.adobe.com/photoshop/1.0/\">" .
                "<photoshop:DateCreated>2023-11-14T17:13:20-0500</photoshop:DateCreated>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:tiff=\"http://ns.adobe.com/tiff/1.0/\">" .
                "<tiff:Orientation>6</tiff:Orientation>" .
                "<tiff:Make>Screenshot</tiff:Make>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">" .
                "<xmp:CreateDate>2023-11-14T17:13:20-0500</xmp:CreateDate>" .
                "<xmp:Rating>5</xmp:Rating>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'hidden_half_hour_timezone' => [
                [
                    'fields' => [
                        'assetDate' => ['value' => -1500, 'type' => 'TIMESTAMP'],
                        'timeZoneOffset' => ['value' => 19800, 'type' => 'INT64'],
                        'isHidden' => ['value' => 1, 'type' => 'INT64'],
                        'isFavorite' => ['value' => 1, 'type' => 'INT64'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:photoshop=\"http://ns.adobe.com/photoshop/1.0/\">" .
                "<photoshop:DateCreated>1970-01-01T05:29:58+0530</photoshop:DateCreated>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">" .
                "<xmp:CreateDate>1970-01-01T05:29:58+0530</xmp:CreateDate>" .
                "<xmp:Rating>-1</xmp:Rating>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'deleted_rejected' => [
                [
                    'fields' => [
                        'assetDate' => ['value' => 1000, 'type' => 'TIMESTAMP'],
                        'isDeleted' => ['value' => 1, 'type' => 'INT64'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:photoshop=\"http://ns.adobe.com/photoshop/1.0/\">" .
                "<photoshop:DateCreated>1970-01-01T00:00:01+0000</photoshop:DateCreated>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">" .
                "<xmp:CreateDate>1970-01-01T00:00:01+0000</xmp:CreateDate>" .
                "<xmp:Rating>-1</xmp:Rating>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'special_characters' => [
                [
                    'fields' => [
                        'captionEnc' => ['value' => 'QSAmIEIgPGM+ICJkIiAnZScgw6nigqzwn5iA', 'type' => 'ENCRYPTED_BYTES'],
                        'extendedDescEnc' => ['value' => 'bGluZSBvbmUKbGluZSB0d28JdGFiYmVkIF1dPiAmYW1wOw==', 'type' => 'ENCRYPTED_BYTES'],
                        'keywordsEnc' => ['value' => 'YnBsaXN0MDCmAQIDBAUGZABjAGEAZgDpU2EmYlU8dGFnPlBXcXVvdGUic2Nl5Wcsip4IDxgcIiMrAAAAAAAAAQEAAAAAAAAABwAAAAAAAAAAAAAAAAAAADI=', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">" .
                "<dc:title>A &amp; B &lt;c&gt; \"d\" 'e' é€😀</dc:title>" .
                "<dc:description>line one\nline two\ttabbed ]]&gt; &amp;amp;</dc:description>" .
                "<dc:subject>" .
                "<rdf:Seq>" .
                "<rdf:li>café</rdf:li>" .
                "<rdf:li>a&amp;b</rdf:li>" .
                "<rdf:li>&lt;tag&gt;</rdf:li>" .
                "<rdf:li />" .
                "<rdf:li>quote\"s</rdf:li>" .
                "<rdf:li>日本語</rdf:li>" .
                "</rdf:Seq>" .
                "</dc:subject>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'minimal_date_only' => [
                [
                    'fields' => [
                        'assetDate' => ['value' => 1532951050000, 'type' => 'TIMESTAMP'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:photoshop=\"http://ns.adobe.com/photoshop/1.0/\">" .
                "<photoshop:DateCreated>2018-07-30T11:44:10+0000</photoshop:DateCreated>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">" .
                "<xmp:CreateDate>2018-07-30T11:44:10+0000</xmp:CreateDate>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'empty_fields' => [
                [
                    'fields' => [],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\" />" .
                "</x:xml_doc>",
            ],
            'orientation_zero_and_empty_caption' => [
                [
                    'fields' => [
                        'captionEnc' => ['value' => '', 'type' => 'ENCRYPTED_BYTES'],
                        'extendedDescEnc' => ['value' => '', 'type' => 'ENCRYPTED_BYTES'],
                        'adjustmentSimpleDataEnc' => ['value' => 'q1bKTS1JTEksSVSyUqhWyi/KTM0rSSzJzM8D8g1qawE=', 'type' => 'ENCRYPTED_BYTES'],
                        'keywordsEnc' => ['value' => 'YnBsaXN0MDCgCAAAAAAAAAEBAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAJ', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\" />" .
                "</x:xml_doc>",
            ],
            'adjustment_without_orientation' => [
                [
                    'fields' => [
                        'adjustmentSimpleDataEnc' => ['value' => 'q1bKTS1JTEksSVSyUqiurQUA', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\" />" .
                "</x:xml_doc>",
            ],
            'bplist_adjustment_ignored' => [
                [
                    'fields' => [
                        'adjustmentSimpleDataEnc' => ['value' => 'YnBsaXN0MDDRAQJac2xvd01vdGlvbtIDBAUWV3JlZ2lvbnNUcmF0ZaEG0QcIWXRpbWVSYW5nZdIJCgsUVXN0YXJ0WGR1cmF0aW9u1AwNDg8QERITVWZsYWdzVXZhbHVlWXRpbWVzY2FsZVVlcG9jaBABER8cEQJYEADUDA0ODxAVEhMRBAQiPoAAAAgLFhsjKCotNzxCS1RaYGpwcnV4eoOGAAAAAAAAAQEAAAAAAAAAFwAAAAAAAAAAAAAAAAAAAIs=', 'type' => 'ENCRYPTED_BYTES'],
                        'assetDate' => ['value' => 1532951050176, 'type' => 'TIMESTAMP'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:photoshop=\"http://ns.adobe.com/photoshop/1.0/\">" .
                "<photoshop:DateCreated>2018-07-30T11:44:10+0000</photoshop:DateCreated>" .
                "</rdf:Description>" .
                "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">" .
                "<xmp:CreateDate>2018-07-30T11:44:10+0000</xmp:CreateDate>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'location_xml_plist' => [
                [
                    'fields' => [
                        'locationEnc' => ['value' => 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9InllcyI/Pgo8IURPQ1RZUEUgcGxpc3QgUFVCTElDICItLy9BcHBsZS8vRFREIFBMSVNUIDEuMC8vRU4iICJodHRwOi8vd3d3LmFwcGxlLmNvbS9EVERzL1Byb3BlcnR5TGlzdC0xLjAuZHRkIj4KPHBsaXN0IHZlcnNpb249IjEuMCI+Cgk8ZGljdD4KCQk8a2V5PnZlcnRBY2M8L2tleT4KCQk8cmVhbD4wLjA8L3JlYWw+CgoJCTxrZXk+YWx0PC9rZXk+CgkJPHJlYWw+MC4wPC9yZWFsPgoKCQk8a2V5Pmxvbjwva2V5PgoJCTxyZWFsPi0xMjIuODgxNTY2NjY2NjY2NjY8L3JlYWw+CgoJCTxrZXk+bGF0PC9rZXk+CgkJPHJlYWw+NTAuMDk0MTgzMzMzMzMzMzM8L3JlYWw+CgoJCTxrZXk+dGltZXN0YW1wPC9rZXk+CgkJPGRhdGU+MjAyMC0wMi0yOVQxODozNTo0OVo8L2RhdGU+CgoJPC9kaWN0Pgo8L3BsaXN0Pg==', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:exif=\"http://ns.adobe.com/exif/1.0/\">" .
                "<exif:GPSLatitude>50.09418333333333</exif:GPSLatitude>" .
                "<exif:GPSLongitude>-122.88156666666666</exif:GPSLongitude>" .
                "<exif:GPSTimeStamp>2020-02-29T18:35:49</exif:GPSTimeStamp>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'location_values' => [
                [
                    'fields' => [
                        'locationEnc' => ['value' => 'YnBsaXN0MDDVAQIDBAUGBwgJClNhbHRTbGF0U2xvblVzcGVlZFl0aW1lc3RhbXAQDCM+5Pi1iONo8SO+etfymryvSCNDQcN5N+CAADNBwd4MP8AAAAgTFxsfJS8xOkNMAAAAAAAAAQEAAAAAAAAACwAAAAAAAAAAAAAAAAAAAFU=', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">" .
                "<rdf:Description rdf:about=\"\" xmlns:exif=\"http://ns.adobe.com/exif/1.0/\">" .
                "<exif:GPSAltitude>12</exif:GPSAltitude>" .
                "<exif:GPSLatitude>1e-05</exif:GPSLatitude>" .
                "<exif:GPSLongitude>-1e-07</exif:GPSLongitude>" .
                "<exif:GPSSpeed>1e+16</exif:GPSSpeed>" .
                "<exif:GPSTimeStamp>2019-12-31T23:59:59</exif:GPSTimeStamp>" .
                "</rdf:Description>" .
                "</rdf:RDF>" .
                "</x:xml_doc>",
            ],
            'location_zeroes_and_text_timestamp' => [
                [
                    'fields' => [
                        'locationEnc' => ['value' => 'YnBsaXN0MDDVAQIDBAUGBwYGCFNhbHRTbGF0U2xvblVzcGVlZFl0aW1lc3RhbXAjAAAAAAAAAAAQAFpub3QgYSBkYXRlCBMXGx8lLzg6AAAAAAAAAQEAAAAAAAAACQAAAAAAAAAAAAAAAAAAAEU=', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\" />" .
                "</x:xml_doc>",
            ],
            'location_no_values' => [
                [
                    'fields' => [
                        'locationEnc' => ['value' => 'YnBsaXN0MDDRAQJXdmVydEFjYyNACAAAAAAAAAgLEwAAAAAAAAEBAAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAc', 'type' => 'ENCRYPTED_BYTES'],
                    ],
                ],
                "<?xml version='1.0' encoding='utf-8'?>\n<x:xml_doc xmlns:x=\"adobe:ns:meta/\" x:xmptk=\"icloudpd 0.0.1+abcdefgh\">" .
                "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\" />" .
                "</x:xml_doc>",
            ],
        ];
    }
}
