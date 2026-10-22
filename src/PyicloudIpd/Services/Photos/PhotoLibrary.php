<?php

namespace PyicloudIpd\Services\Photos;

use Foundation\Foundation;
use Foundation\Http\Session;
use Foundation\Http\Urllib;
use Foundation\Patch;
use Foundation\Py;
use PyicloudIpd\Exceptions\PyiCloudServiceNotActivatedException;
use PyicloudIpd\Services\Photos as PhotosModule;

/**
 * Represents a library in the user's photos.
 *
 * This provides access to all the albums as well as the photos.
 */
class PhotoLibrary
{
    /** @var array<string, mixed> */
    public const WHOLE_COLLECTION = [
        "obj_type" => "CPLAssetByAssetDateWithoutHiddenOrDeleted",
        "list_type" => "CPLAssetAndMasterByAssetDateWithoutHiddenOrDeleted",
        "query_filter" => null,
    ];
    /** @var array<string, mixed> */
    public const RECENTLY_DELETED = [
        "obj_type" => "CPLAssetDeletedByExpungedDate",
        "list_type" => "CPLAssetAndMasterDeletedByExpungedDate",
        "query_filter" => null,
    ];
    public const SMART_FOLDERS = [
        "Time-lapse" => [
            "obj_type" => "CPLAssetInSmartAlbumByAssetDate:Timelapse",
            "list_type" => "CPLAssetAndMasterInSmartAlbumByAssetDate",
            "query_filter" => [
                [
                    "fieldName" => "smartAlbum",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => "TIMELAPSE"],
                ],
            ],
        ],
        "Videos" => [
            "obj_type" => "CPLAssetInSmartAlbumByAssetDate:Video",
            "list_type" => "CPLAssetAndMasterInSmartAlbumByAssetDate",
            "query_filter" => [
                [
                    "fieldName" => "smartAlbum",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => "VIDEO"],
                ],
            ],
        ],
        "Slo-mo" => [
            "obj_type" => "CPLAssetInSmartAlbumByAssetDate:Slomo",
            "list_type" => "CPLAssetAndMasterInSmartAlbumByAssetDate",
            "query_filter" => [
                [
                    "fieldName" => "smartAlbum",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => "SLOMO"],
                ],
            ],
        ],
        "Bursts" => [
            "obj_type" => "CPLAssetBurstStackAssetByAssetDate",
            "list_type" => "CPLBurstStackAssetAndMasterByAssetDate",
            "query_filter" => null,
        ],
        "Favorites" => [
            "obj_type" => "CPLAssetInSmartAlbumByAssetDate:Favorite",
            "list_type" => "CPLAssetAndMasterInSmartAlbumByAssetDate",
            "query_filter" => [
                [
                    "fieldName" => "smartAlbum",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => "FAVORITE"],
                ],
            ],
        ],
        "Panoramas" => [
            "obj_type" => "CPLAssetInSmartAlbumByAssetDate:Panorama",
            "list_type" => "CPLAssetAndMasterInSmartAlbumByAssetDate",
            "query_filter" => [
                [
                    "fieldName" => "smartAlbum",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => "PANORAMA"],
                ],
            ],
        ],
        "Screenshots" => [
            "obj_type" => "CPLAssetInSmartAlbumByAssetDate:Screenshot",
            "list_type" => "CPLAssetAndMasterInSmartAlbumByAssetDate",
            "query_filter" => [
                [
                    "fieldName" => "smartAlbum",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => "SCREENSHOT"],
                ],
            ],
        ],
        "Live" => [
            "obj_type" => "CPLAssetInSmartAlbumByAssetDate:Live",
            "list_type" => "CPLAssetAndMasterInSmartAlbumByAssetDate",
            "query_filter" => [
                [
                    "fieldName" => "smartAlbum",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => "LIVE"],
                ],
            ],
        ],
        "Recently Deleted" => self::RECENTLY_DELETED,
        "Hidden" => [
            "obj_type" => "CPLAssetHiddenByAssetDate",
            "list_type" => "CPLAssetAndMasterHiddenByAssetDate",
            "query_filter" => null,
        ],
    ];

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $zone_id
     */
    public function __construct(
        public string $service_endpoint,
        public array $params,
        public Session $session,
        public array $zone_id,
        public string $library_type,
    ) {
        $url = "{$this->service_endpoint}/records/query?" . Urllib::urlencode($this->params);
        $json_data = Py::json_dumps(
            [
                "query" => ["recordType" => "CheckIndexingState"],
                "zoneID" => $this->zone_id,
            ]
        );

        $request = $this->session->post($url, data: $json_data, headers: ["Content-type" => "text/plain"]);
        $response = $request->json();
        $indexing_state = $response["records"][0]["fields"]["state"]["value"];
        if ($indexing_state !== "FINISHED") {
            throw new PyiCloudServiceNotActivatedException(
                "Apple iCloud Photo Library has not finished indexing yet",
                null
            );
        }
    }

    /**
     * @return array<string, PhotoAlbum>
     */
    public function albums(): array
    {
        $albums = [];
        foreach (self::SMART_FOLDERS as $name => $props) {
            $albums[$name] = new PhotoAlbum(
                $this->params,
                $this->session,
                $this->service_endpoint,
                $name,
                ...$props,
                zone_id: $this->zone_id,
            );
        }

        foreach ($this->_fetch_folders() as $folder) {
            // FIXME: Handle subfolders
            if (in_array($folder["recordName"], ["----Root-Folder----", "----Project-Root-Folder----"], true)
                || (!empty($folder["fields"]["isDeleted"]) && $folder["fields"]["isDeleted"]["value"])) {
                continue;
            }

            $folder_id = $folder["recordName"];
            $folder_obj_type = "CPLContainerRelationNotDeletedByAssetDate:{$folder_id}";
            $folder_name = Foundation::bytes_decode("utf-8")(
                PhotosModule::b64decode($folder["fields"]["albumNameEnc"]["value"])
            );
            $query_filter = [
                [
                    "fieldName" => "parentId",
                    "comparator" => "EQUALS",
                    "fieldValue" => ["type" => "STRING", "value" => $folder_id],
                ],
            ];

            $album = new PhotoAlbum(
                $this->params,
                $this->session,
                $this->service_endpoint,
                $folder_name,
                "CPLContainerRelationLiveByAssetDate",
                $folder_obj_type,
                $query_filter,
                zone_id: $this->zone_id,
            );
            $albums[$folder_name] = $album;
        }

        return $albums;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function _fetch_folders(): array
    {
        if ($p = Patch::get(__METHOD__)) {
            return $p($this);
        }
        if ($this->library_type === "shared") {
            return [];
        }
        $url = "{$this->service_endpoint}/records/query?" . Urllib::urlencode($this->params);
        $json_data = Py::json_dumps(
            [
                "query" => ["recordType" => "CPLAlbumByPositionLive"],
                "zoneID" => $this->zone_id,
            ]
        );

        $request = $this->session->post($url, data: $json_data, headers: ["Content-type" => "text/plain"]);
        $response = $request->json();

        return $response["records"];
    }

    public function all(): PhotoAlbum
    {
        return new PhotoAlbum(
            $this->params,
            $this->session,
            $this->service_endpoint,
            "",
            self::WHOLE_COLLECTION["list_type"],
            self::WHOLE_COLLECTION["obj_type"],
            query_filter: self::WHOLE_COLLECTION["query_filter"],
            zone_id: $this->zone_id,
        );
    }

    public function recently_deleted(): PhotoAlbum
    {
        return new PhotoAlbum(
            $this->params,
            $this->session,
            $this->service_endpoint,
            "",
            self::RECENTLY_DELETED["list_type"],
            self::RECENTLY_DELETED["obj_type"],
            query_filter: self::RECENTLY_DELETED["query_filter"],
            zone_id: $this->zone_id,
        );
    }
}
