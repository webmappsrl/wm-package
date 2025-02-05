<?php

namespace Wm\WmPackage\Http\Clients;

use Exception;

use Wm\WmPackage\Http\Clients\Abstracts\JsonClient;

class OsmfeaturesClient extends JsonClient
{

    public function getWheresByGeojson(array $geojson): array
    {
        $wheresGeojson = $this->getAdminAreasIntersected($geojson);

        //{"name": "Scalepranu/Escalaplano", "type": "boundary", "name:it": "Escalaplano", "name:sc": "Scalepranu", "website": "https://www.comune.escalaplano.ca.it/", "alt_name": "Iscalepranu", "boundary": "administrative", "wikidata": "Q179092", "ref:ISTAT": "111018", "wikipedia": "it:Escalaplano", "admin_level": "8", "postal_code": "08043", "ref:catasto": "D430", "wikipedia:sc": "Scalepranu"}
        $wheres = [];
        foreach ($wheresGeojson['features'] as $feature) {
            $osmType = $feature['osm_type'];
            $osmId = $feature['osm_id'];
            $whereId = $osmType . $osmId;
            $featureTags = $feature['tags'];
            $name = null;
            foreach ($featureTags as $tagName => $tagValue) {

                if ($tagName === 'name') {
                    $name = $tagValue;
                } elseif (($pos = strpos($tagName, 'name:')) !== false) {
                    // 5 = the length of "name:"
                    // 2 = the length of the language code, eg: "it", "en"
                    $language = substr($tagName, $pos + 5, 2);
                    $wheres[$whereId][$language] = $tagValue;
                }
            }

            // If translations aren't provided then use the default name as italian and english label
            if (empty($wheres[$whereId]) && $name !== null) {
                $wheres[$whereId] = [
                    'it' => $name,
                    'en' => $name
                ];
            }
        }

        return $wheres;
    }

    protected function getAdminAreasIntersected(array $geojson)
    {
        $response = $this->getHttpClient()->post(
            $this->getAdminAreasIntersectsUrl(),
            $geojson
        );
        // Check the response
        if (! $response->successful()) {
            // Request failed, handle the error here
            $errorCode = $response->status();
            $errorBody = $response->body();
            throw new Exception("Failing during retrieving admin areas (wheres) from osmfeatures: Error {$errorCode}: {$errorBody}");
        }

        return $response->json();
    }

    protected function getAdminAreasIntersectsUrl()
    {
        return '/api/v1/features/admin-areas/geojson';
    }

    protected function getHost(): string
    {
        return rtrim(config('wm-package.clients.osmfeatures.host'));
    }
}
