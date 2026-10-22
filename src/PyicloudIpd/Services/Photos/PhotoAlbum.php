<?php

namespace PyicloudIpd\Services\Photos;

use Countable;
use Foundation\Http\Response;
use Foundation\Http\Session;
use Foundation\Http\Urllib;
use Foundation\Patch;
use Foundation\Py;
use Generator;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int, PhotoAsset>
 */
class PhotoAlbum implements IteratorAggregate, Countable
{
    public int $offset = 0;

    /** @var array<string, mixed> */
    public array $_zone_id;

    /**
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>>|null $query_filter
     * @param array<string, mixed>|null $zone_id
     */
    public function __construct(
        public array $params,
        public Session $session,
        public string $service_endpoint,
        public string $name,
        public string $list_type,
        public string $obj_type,
        public ?array $query_filter = null,
        public int $page_size = 100,
        ?array $zone_id = null,
    ) {
        if ($zone_id) {
            $this->_zone_id = $zone_id;
        } else {
            $this->_zone_id = ["zoneName" => "PrimarySync"];
        }
    }

    public function title(): string
    {
        return $this->name;
    }

    /**
     * @return Generator<int, PhotoAsset>
     */
    public function getIterator(): Generator
    {
        return $this->photos();
    }

    /** `__len__` */
    public function count(): int
    {
        $url = "{$this->service_endpoint}/internal/records/query/batch?" . Urllib::urlencode($this->params);
        $request = $this->session->post(
            $url,
            [
                "data" => Py::json_dumps($this->_count_query_gen($this->obj_type)),
                "headers" => ["Content-type" => "text/plain"],
            ]
        );
        $response = $request->json();

        return (int) $response["batch"][0]["records"][0]["fields"]["itemCount"]["value"];
    }

    // Perform the request in a separate method so that we
    // can mock it to test session errors.
    public function photos_request(): Response
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this);
        }
        $url = "{$this->service_endpoint}/records/query?" . Urllib::urlencode($this->params);
        return $this->session->post(
            $url,
            [
                "data" => Py::json_dumps($this->_list_query_gen($this->offset, $this->list_type, $this->query_filter)),
                "headers" => ["Content-type" => "text/plain"],
            ]
        );
    }

    /**
     * @return Generator<int, PhotoAsset>
     */
    public function photos(): Generator
    {
        while (true) {
            $request = $this->photos_request();

            //            url = ('%s/records/query?' % self.service_endpoint) + \
            //                urlencode(self.service.params)
            //            request = self.service.session.post(
            //                url,
            //                data=json.dumps(self._list_query_gen(
            //                    offset, self.list_type, self.direction,
            //                    self.query_filter)),
            //                headers={'Content-type': 'text/plain'}
            //            )

            $response = $request->json();

            $asset_records = [];
            $master_records = [];
            foreach ($response["records"] as $rec) {
                if ($rec["recordType"] === "CPLAsset") {
                    $master_id = $rec["fields"]["masterRef"]["value"]["recordName"];
                    $asset_records[$master_id] = $rec;
                } elseif ($rec["recordType"] === "CPLMaster") {
                    $master_records[] = $rec;
                }
            }

            $master_records_len = count($master_records);
            if ($master_records_len) {
                foreach ($master_records as $master_record) {
                    $record_name = $master_record["recordName"];
                    yield new PhotoAsset($master_record, $asset_records[$record_name]);
                    $this->increment_offset(1);
                }
            } else {
                break;
            }
        }
    }

    public function increment_offset(int $value): void
    {
        $this->offset += $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function _count_query_gen(string $obj_type): array
    {
        $query = [
            "batch" => [
                [
                    "resultsLimit" => 1,
                    "query" => [
                        "filterBy" => [
                            "fieldName" => "indexCountID",
                            "fieldValue" => ["type" => "STRING_LIST", "value" => [$obj_type]],
                            "comparator" => "IN",
                        ],
                        "recordType" => "HyperionIndexCountLookup",
                    ],
                    "zoneWide" => true,
                    "zoneID" => $this->_zone_id,
                ],
            ],
        ];

        return $query;
    }

    /**
     * @param array<int, array<string, mixed>>|null $query_filter
     * @return array<string, mixed>
     */
    public function _list_query_gen(int $offset, string $list_type, ?array $query_filter = null): array
    {
        $query = [
            "query" => [
                "filterBy" => [
                    [
                        "fieldName" => "startRank",
                        "fieldValue" => ["type" => "INT64", "value" => $offset],
                        "comparator" => "EQUALS",
                    ],
                    [
                        "fieldName" => "direction",
                        "fieldValue" => ["type" => "STRING", "value" => "ASCENDING"],
                        "comparator" => "EQUALS",
                    ],
                ],
                "recordType" => $list_type,
            ],
            "resultsLimit" => $this->page_size * 2,
            "desiredKeys" => [
                "resJPEGFullWidth",
                "resJPEGFullHeight",
                "resJPEGFullFileType",
                "resJPEGFullFingerprint",
                "resJPEGFullRes",
                "resJPEGLargeWidth",
                "resJPEGLargeHeight",
                "resJPEGLargeFileType",
                "resJPEGLargeFingerprint",
                "resJPEGLargeRes",
                "resJPEGMedWidth",
                "resJPEGMedHeight",
                "resJPEGMedFileType",
                "resJPEGMedFingerprint",
                "resJPEGMedRes",
                "resJPEGThumbWidth",
                "resJPEGThumbHeight",
                "resJPEGThumbFileType",
                "resJPEGThumbFingerprint",
                "resJPEGThumbRes",
                "resVidFullWidth",
                "resVidFullHeight",
                "resVidFullFileType",
                "resVidFullFingerprint",
                "resVidFullRes",
                "resVidMedWidth",
                "resVidMedHeight",
                "resVidMedFileType",
                "resVidMedFingerprint",
                "resVidMedRes",
                "resVidSmallWidth",
                "resVidSmallHeight",
                "resVidSmallFileType",
                "resVidSmallFingerprint",
                "resVidSmallRes",
                "resSidecarWidth",
                "resSidecarHeight",
                "resSidecarFileType",
                "resSidecarFingerprint",
                "resSidecarRes",
                "itemType",
                "dataClassType",
                "filenameEnc",
                "originalOrientation",
                "resOriginalWidth",
                "resOriginalHeight",
                "resOriginalFileType",
                "resOriginalFingerprint",
                "resOriginalRes",
                "resOriginalAltWidth",
                "resOriginalAltHeight",
                "resOriginalAltFileType",
                "resOriginalAltFingerprint",
                "resOriginalAltRes",
                "resOriginalVidComplWidth",
                "resOriginalVidComplHeight",
                "resOriginalVidComplFileType",
                "resOriginalVidComplFingerprint",
                "resOriginalVidComplRes",
                "isDeleted",
                "isExpunged",
                "dateExpunged",
                "remappedRef",
                "recordName",
                "recordType",
                "recordChangeTag",
                "masterRef",
                "adjustmentRenderType",
                "assetDate",
                "addedDate",
                "isFavorite",
                "isHidden",
                "orientation",
                "duration",
                "assetSubtype",
                "assetSubtypeV2",
                "assetHDRType",
                "burstFlags",
                "burstFlagsExt",
                "burstId",
                "captionEnc",
                "locationEnc",
                "locationV2Enc",
                "locationLatitude",
                "locationLongitude",
                "adjustmentType",
                "timeZoneOffset",
                "vidComplDurValue",
                "vidComplDurScale",
                "vidComplDispValue",
                "vidComplDispScale",
                "keywordsEnc",
                "extendedDescEnc",
                "adjustedMediaMetaDataEnc",
                "adjustmentSimpleDataEnc",
                "vidComplVisibilityState",
                "customRenderedValue",
                "containerId",
                "itemId",
                "position",
                "isKeyAsset",
            ],
            "zoneID" => $this->_zone_id,
        ];

        if ($query_filter) {
            array_push($query["query"]["filterBy"], ...$query_filter);
        }

        return $query;
    }

    public function __unicode__(): string
    {
        return $this->title();
    }

    public function __toString(): string
    {
        $as_unicode = $this->__unicode__();
        return $as_unicode;
    }

    public function __repr__(): string
    {
        return "<" . substr(strrchr(static::class, "\\"), 1) . ": '{$this}'>";
    }
}
