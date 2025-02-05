<?php

namespace Wm\WmPackage\Http\Clients;

use Exception;
use Wm\WmPackage\Http\Clients\Abstracts\JsonClient;

class DemClient extends JsonClient
{
    public function getElevation($x, $y)
    {
        $response = $this->getHttpClient()->get($this->getElevationUrl($x, $y));

        return $response->json()['ele'];
    }

    private function getElevationUrl($x, $y)
    {
        return $this->getHost() . '/' . rtrim(config('wm-package.clients.dem.ele_api'), '/') . "/$x/$y";
    }

    public function getTechData($geojson)
    {
        $response = $this->getHttpClient()->post(
            $this->getTechDataUrl(),
            $geojson
        );
        // Check the response
        if (! $response->successful() || empty($responseData['geometry'])) {
            // Request failed, handle the error here
            $errorCode = $response->status();
            $errorBody = $response->body();
            throw new Exception("UpdateEcTrack3DDemJob: FAILED: Error {$errorCode}: {$errorBody}");
        }

        return $response->json();
    }

    private function getTechDataUrl()
    {
        return $this->getHost() . '/' . rtrim(config('wm-package.clients.dem.tech_data_api'), '/');
    }

    protected function getHost(): string
    {
        return rtrim(config('wm-package.clients.dem.host'));
    }
}
