<?php

namespace PyicloudIpd\Services\Photos;

use Foundation\Py;
use PyicloudIpd\Services\Photos as PhotosModule;
use PyicloudIpd\Session\PyiCloudSession;

/**
 * The 'Photos' iCloud service.
 *
 * This also acts as a way to access the user's primary library.
 */
class PhotosService extends PhotoLibrary
{
    public string $_service_root;

    /** @var array<string, PhotoLibrary>|null */
    public ?array $_private_libraries;
    /** @var array<string, PhotoLibrary>|null */
    public ?array $_shared_libraries;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(string $service_root, PyiCloudSession $session, array $params)
    {
        $this->session = $session;
        $this->params = $params;
        $this->_service_root = $service_root;

        $this->_private_libraries = null;
        $this->_shared_libraries = null;

        $this->params = array_merge($this->params, ["remapEnums" => true, "getCurrentSyncToken" => true]);

        // Initialize as primary library
        $service_endpoint = $this->get_service_endpoint("private");
        $zone_id = ["zoneName" => "PrimarySync"];
        parent::__construct($service_endpoint, $this->params, $this->session, $zone_id, "private");

        // TODO: Does syncToken ever change?
        // self.params.update({
        //     'syncToken': response['syncToken'],
        //     'clientInstanceId': self.params.pop('clientId')
        // })

        // self._photo_assets = {}
    }

    /**
     * @return array<string, PhotoLibrary>
     */
    public function private_libraries(): array
    {
        if (!$this->_private_libraries) {
            $this->_private_libraries = $this->_fetch_libraries("private");
        }

        return $this->_private_libraries;
    }

    /**
     * @return array<string, PhotoLibrary>
     */
    public function shared_libraries(): array
    {
        if (!$this->_shared_libraries) {
            $this->_shared_libraries = $this->_fetch_libraries("shared");
        }

        return $this->_shared_libraries;
    }

    /**
     * @return array<string, PhotoLibrary>
     */
    public function _fetch_libraries(string $library_type): array
    {
        try {
            $libraries = [];
            $service_endpoint = $this->get_service_endpoint($library_type);
            $url = "{$service_endpoint}/zones/list";
            $request = $this->session->post($url, data: "{}", headers: ["Content-type" => "text/plain"]);
            $response = $request->json();
            foreach ($response["zones"] as $zone) {
                if (empty($zone["deleted"])) {
                    $zone_name = $zone["zoneID"]["zoneName"];
                    $service_endpoint = $this->get_service_endpoint($library_type);
                    $libraries[$zone_name] = new PhotoLibrary(
                        $service_endpoint,
                        $this->params,
                        $this->session,
                        zone_id: $zone["zoneID"],
                        library_type: $library_type,
                    );
                    // obj_type='CPLAssetByAssetDateWithoutHiddenOrDeleted',
                    // list_type="CPLAssetAndMasterByAssetDateWithoutHiddenOrDeleted",
                    // direction="ASCENDING", query_filter=None,
                    // zone_id=zone['zoneID'])
                }
            }
        } catch (\Throwable $e) {
            PhotosModule::logger()->error("library exception: " . Py::str($e));
        }
        return $libraries;
    }

    public function get_service_endpoint(string $library_type): string
    {
        return "{$this->_service_root}/database/1/com.apple.photos.cloud/production/{$library_type}";
    }
}
