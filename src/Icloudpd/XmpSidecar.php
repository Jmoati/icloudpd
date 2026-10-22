<?php

namespace Icloudpd;

use Foundation\Foundation;
use Foundation\Logging\Logger;
use Foundation\OSError;
use Foundation\Os;
use Foundation\Plist;
use Foundation\Predicates;
use Foundation\Py;
use Foundation\StringUtils;
use Foundation\ElementTree;
use Foundation\ElementTree\Element;
use Foundation\ElementTree\ParseError;
use Icloudpd\XmpSidecar\XMPMetadata;

/**
 * Generate XMP sidecar file from photo asset record
 */
final class XmpSidecar
{
    public static function generate_xmp_file(Logger $logger, string $download_path, array $asset_record, bool $dry_run): void
    {
        $sidecar_path = $download_path.'.xmp';
        $can_write_file = true;
        if (Os::path_exists($sidecar_path) && Os::path_getsize($sidecar_path) != 0) {
            $can_write_file = false;
            try {
                $root = ElementTree::parse($sidecar_path);
                $xmptk_value = $root->attrib['{adobe:ns:meta/}xmptk'] ?? null;
                $starts_with_icloudpd = StringUtils::startswith('icloudpd');

                if ($xmptk_value === null || $xmptk_value === '' || !$starts_with_icloudpd($xmptk_value)) {
                    $logger->info('Not overwriting XMP file '.$sidecar_path.' created by '.Py::str($xmptk_value));
                } else {
                    $can_write_file = true;
                }
            } catch (ParseError $e) {
                $logger->info('Not overwriting XMP file '.$sidecar_path.' due to parser error: '.$e->getMessage());
            }
        }

        // decode asset record fields
        // for k in asset_record['fields']:
        //    if asset_record["fields"][k]['type'] == "ENCRYPTED_BYTES":
        //         try:
        //             asset_record["fields"][k]['decoded'] = plistlib.loads(base64.b64decode(asset_record['fields'][k]['value']), fmt=plistlib.FMT_BINARY)
        //         except plistlib.InvalidFileException:
        //             try:
        //                 asset_record["fields"][k]['decoded'] =  json.loads(zlib.decompress(base64.b64decode(asset_record['fields'][k]['value']),-zlib.MAX_WBITS))
        //             except Exception as e:
        //                 asset_record["fields"][k]['decoded'] = base64.b64decode(asset_record['fields'][k]['value']).decode("utf-8")
        // json.dump(asset_record["fields"],         open(download_path + ".ar.json", "w"),         indent=4,        default=str,        sort_keys=True)

        if ($can_write_file) {
            $xmp_metadata = self::build_metadata($asset_record);
            $xml_doc = self::generate_xml($xmp_metadata);
            if (!$dry_run) {
                // Write the XML to the file
                $f = @fopen($sidecar_path, 'wb');
                if ($f === false) {
                    throw new OSError("[Errno 2] Cannot open '{$sidecar_path}'");
                }
                try {
                    fwrite($f, ElementTree::tostring($xml_doc, 'utf-8', true));
                } finally {
                    fclose($f);
                }
            }
        }
    }

    /** `base64.b64decode(value).decode("utf-8")` */
    private static function b64decode_utf8(string $value): string
    {
        $decoded = base64_decode($value);
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            throw new \ValueError("'utf-8' codec can't decode bytes");
        }

        return $decoded;
    }

    /** `value == 1` for a JSON value */
    private static function is_one(mixed $value): bool
    {
        return $value === 1 || $value === 1.0 || $value === true;
    }

    /** Build XMP metadata from asset record */
    public static function build_metadata(array $asset_record): XMPMetadata
    {
        $title = null;
        if (array_key_exists('captionEnc', $asset_record['fields'])) {
            $title = self::b64decode_utf8($asset_record['fields']['captionEnc']['value']);
        }

        $description = null;
        if (array_key_exists('extendedDescEnc', $asset_record['fields'])) {
            $description = self::b64decode_utf8($asset_record['fields']['extendedDescEnc']['value']);
        }

        // adjustementSimpleDataEnc can be one of three formats:
        // - a binary plist - starting with 'bplist00' ( YnBsaXN0MD once encoded), seemingly used for some videos metadata (slow motion range etc)
        // - a CRDT (Conflict-free Replicated Data Types) - starting with 'crdt' (Y3JkdA once encoded) - used for drawings and annotations on photos and screenshots
        // - a zlib compressed JSON - used for simple photo metadata adjustments (orientation etc)
        // for exporting metadata, we only consider the JSON data, but it's the only one that doesn't have a predictable start pattern, so we check by excluding the other two
        $orientation = null;

        if (array_key_exists('adjustmentSimpleDataEnc', $asset_record['fields'])) {
            $data_value = $asset_record['fields']['adjustmentSimpleDataEnc']['value'];
            $is_not_crdt = Predicates::not_(StringUtils::startswith('Y3JkdA'));
            $is_not_bplist = Predicates::not_(StringUtils::startswith('YnBsaXN0MD'));

            if ($is_not_crdt($data_value) && $is_not_bplist($data_value)) {  // not "crdt" and not "bplist00"
                $inflated = @gzinflate(base64_decode($asset_record['fields']['adjustmentSimpleDataEnc']['value']));
                if ($inflated === false) {
                    throw new \ValueError('Error -3 while decompressing data');
                }
                $adjustments = json_decode($inflated, true, 512, JSON_THROW_ON_ERROR);
                if (array_key_exists('metadata', $adjustments) && array_key_exists('orientation', $adjustments['metadata'])) {
                    $orientation = $adjustments['metadata']['orientation'];
                }
            }
        }

        $make = $digital_source_type = null;
        if (
            array_key_exists('assetSubtypeV2', $asset_record['fields'])
            && (int) $asset_record['fields']['assetSubtypeV2']['value'] == 3
        ) {
            $make = 'Screenshot';
            $digital_source_type = 'screenCapture';
        }

        $keywords = null;
        if (array_key_exists('keywordsEnc', $asset_record['fields']) && count($asset_record['fields']['keywordsEnc']) > 0) {
            $keywords = Plist::loads(base64_decode($asset_record['fields']['keywordsEnc']['value']));
        }

        if (array_key_exists('locationEnc', $asset_record['fields'])) {
            $location = Plist::loads(base64_decode($asset_record['fields']['locationEnc']['value']));
            $gps_altitude = $location['alt'] ?? null;
            $gps_latitude = $location['lat'] ?? null;
            $gps_longitude = $location['lon'] ?? null;
            $gps_speed = $location['speed'] ?? null;
            $gps_timestamp = ($location['timestamp'] ?? null) instanceof \DateTimeInterface ? $location['timestamp'] : null;
        } else {
            [$gps_altitude, $gps_latitude, $gps_longitude, $gps_speed, $gps_timestamp] = [null, null, null, null, null];
        }

        $create_date = null;
        if (array_key_exists('assetDate', $asset_record['fields'])) {
            $timezone_offset = 0;
            if (array_key_exists('timeZoneOffset', $asset_record['fields'])) {
                $timezone_offset = $asset_record['fields']['timeZoneOffset']['value'];
            }
            $create_date = self::fromtimestamp_ms((int) $asset_record['fields']['assetDate']['value'], (int) $timezone_offset);
        }

        $rating = null;
        // Hidden or Deleted Photos should be marked as rejected (needs running as --album "Hidden" or --album "Recently Deleted")
        if (
            (array_key_exists('isHidden', $asset_record['fields']) && self::is_one($asset_record['fields']['isHidden']['value']))
            || (array_key_exists('isDeleted', $asset_record['fields']) && self::is_one($asset_record['fields']['isDeleted']['value']))
        ) {
            $rating = -1;  // -1 means rejected: https://www.iptc.org/std/photometadata/specification/IPTC-PhotoMetadata#image-rating
        }
        // only mark photo as favorite if not hidden or deleted
        elseif (
            array_key_exists('isFavorite', $asset_record['fields'])
            && self::is_one($asset_record['fields']['isFavorite']['value'])
        ) {
            $rating = 5;
        }

        $version_info = Foundation::version_info();

        return new XMPMetadata(
            XMPToolkit: 'icloudpd '.$version_info->version.'+'.$version_info->commit_sha,
            Title: $title,
            Description: $description,
            Orientation: $orientation,
            Make: $make,
            DigitalSourceType: $digital_source_type,
            Keywords: $keywords,
            GPSAltitude: $gps_altitude,
            GPSLatitude: $gps_latitude,
            GPSLongitude: $gps_longitude,
            GPSSpeed: $gps_speed,
            GPSTimeStamp: $gps_timestamp,
            CreateDate: $create_date,
            Rating: $rating,
        );
    }

    /**
     * `datetime.fromtimestamp(ms / 1000, tz=timezone(timedelta(seconds=timezone_offset)))`: the millisecond
     * count is exact, so the integer arithmetic gives what the float division rounds to
     */
    private static function fromtimestamp_ms(int $ms, int $timezone_offset): \DateTimeImmutable
    {
        if ($timezone_offset <= -86400 || $timezone_offset >= 86400) {
            throw new \ValueError('offset must be a timedelta strictly between -timedelta(hours=24) and timedelta(hours=24)');
        }
        $seconds = intdiv($ms, 1000) - ($ms % 1000 < 0 ? 1 : 0);
        $microseconds = ($ms - $seconds * 1000) * 1000;
        $sign = $timezone_offset < 0 ? '-' : '+';
        $a = abs($timezone_offset);
        $tz = new \DateTimeZone(sprintf('%s%02d:%02d:%02d', $sign, intdiv($a, 3600), intdiv($a % 3600, 60), $a % 60));

        return (new \DateTimeImmutable('@'.$seconds))->modify("+$microseconds usec")->setTimezone($tz);
    }

    public static function generate_xml(XMPMetadata $metadata): Element
    {
        // Create the root element
        $xml_doc = new Element('x:xml_doc', ['xmlns:x' => 'adobe:ns:meta/', 'x:xmptk' => $metadata->XMPToolkit]);

        // Create the RDF element
        $rdf = ElementTree::SubElement($xml_doc, 'rdf:RDF', ['xmlns:rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#']);

        // Create the Description elements
        $description_dc = new Element('rdf:Description', [
            'rdf:about' => '',
            'xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
        ]);
        $description_exif = new Element('rdf:Description', [
            'rdf:about' => '',
            'xmlns:exif' => 'http://ns.adobe.com/exif/1.0/',
        ]);
        $description_iptc4xmpext = new Element('rdf:Description', [
            'rdf:about' => '',
            'xmlns:Iptc4xmpExt' => 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/',
        ]);
        $description_photoshop = new Element('rdf:Description', [
            'rdf:about' => '',
            'xmlns:photoshop' => 'http://ns.adobe.com/photoshop/1.0/',
        ]);
        $description_tiff = new Element('rdf:Description', [
            'rdf:about' => '',
            'xmlns:tiff' => 'http://ns.adobe.com/tiff/1.0/',
        ]);
        $description_xmp = new Element('rdf:Description', [
            'rdf:about' => '',
            'xmlns:xmp' => 'http://ns.adobe.com/xap/1.0/',
        ]);
        if (Py::truthy($metadata->Title)) {
            ElementTree::SubElement($description_dc, 'dc:title')->text = $metadata->Title;
        }
        if (Py::truthy($metadata->Description)) {
            ElementTree::SubElement($description_dc, 'dc:description')->text = $metadata->Description;
        }

        if (Py::truthy($metadata->Orientation)) {
            ElementTree::SubElement($description_tiff, 'tiff:Orientation')->text = Py::str($metadata->Orientation);
        }
        if (Py::truthy($metadata->Make)) {
            ElementTree::SubElement($description_tiff, 'tiff:Make')->text = $metadata->Make;
        }
        if (Py::truthy($metadata->DigitalSourceType)) {
            ElementTree::SubElement($description_iptc4xmpext, 'Iptc4xmpExt:DigitalSourceType')->text = $metadata->DigitalSourceType;
        }

        if (Py::truthy($metadata->Keywords)) {
            $subject = ElementTree::SubElement($description_dc, 'dc:subject');
            $seq = ElementTree::SubElement($subject, 'rdf:Seq');
            foreach ($metadata->Keywords as $keyword) {
                ElementTree::SubElement($seq, 'rdf:li')->text = $keyword;
            }
        }

        if (Py::truthy($metadata->GPSAltitude)) {
            ElementTree::SubElement($description_exif, 'exif:GPSAltitude')->text = Py::str($metadata->GPSAltitude);
        }
        if (Py::truthy($metadata->GPSLatitude)) {
            ElementTree::SubElement($description_exif, 'exif:GPSLatitude')->text = Py::str($metadata->GPSLatitude);
        }
        if (Py::truthy($metadata->GPSLongitude)) {
            ElementTree::SubElement($description_exif, 'exif:GPSLongitude')->text = Py::str($metadata->GPSLongitude);
        }
        if (Py::truthy($metadata->GPSSpeed)) {
            ElementTree::SubElement($description_exif, 'exif:GPSSpeed')->text = Py::str($metadata->GPSSpeed);
        }
        if ($metadata->GPSTimeStamp) {
            // naive datetime (it comes from a plist): `%z` prints nothing
            ElementTree::SubElement($description_exif, 'exif:GPSTimeStamp')->text = Py::strftime($metadata->GPSTimeStamp, '%Y-%m-%dT%H:%M:%S');
        }

        if ($metadata->CreateDate) {
            ElementTree::SubElement($description_xmp, 'xmp:CreateDate')->text = Py::strftime($metadata->CreateDate, '%Y-%m-%dT%H:%M:%S%z');
            ElementTree::SubElement($description_photoshop, 'photoshop:DateCreated')->text = Py::strftime(
                $metadata->CreateDate,
                '%Y-%m-%dT%H:%M:%S%z'
            );  // Apple Photos uses this field when exporting an XMP sidecar
        }

        if (Py::truthy($metadata->Rating)) {
            ElementTree::SubElement($description_xmp, 'xmp:Rating')->text = Py::str($metadata->Rating);
        }
        if (count($description_dc) > 0) {
            $rdf->append($description_dc);
        }
        if (count($description_exif) > 0) {
            $rdf->append($description_exif);
        }
        if (count($description_iptc4xmpext) > 0) {
            $rdf->append($description_iptc4xmpext);
        }
        if (count($description_photoshop) > 0) {
            $rdf->append($description_photoshop);
        }
        if (count($description_tiff) > 0) {
            $rdf->append($description_tiff);
        }
        if (count($description_xmp) > 0) {
            $rdf->append($description_xmp);
        }

        return $xml_doc;
    }
}
