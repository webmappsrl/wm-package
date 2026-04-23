<?php

namespace Wm\WmPackage\Http\Clients;

use Exception;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Http\Clients\Abstracts\JsonClient;

class OsmfeaturesClient extends JsonClient
{
    public function getWheresByGeojson(array $geojson): array
    {

        if (isset($geojson['properties'])) {
            $geojson['properties'] = []; // unused on osmfeatures computation, this decrease the payload size
        }

        // Get the admin areas intersected by the geojson for the given admin level
        $comuniGeojson = $this->getAdminAreasIntersected($geojson, 8); // COMUNE
        $regioniGeojson = $this->getAdminAreasIntersected($geojson, 4); // REGIONE

        // Merge the results
        $allFeatures = array_merge(
            $regioniGeojson['features'] ?? [],
            $comuniGeojson['features'] ?? []
        );

        // {"name": "Scalepranu/Escalaplano", "type": "boundary", "name:it": "Escalaplano", "name:sc": "Scalepranu", "website": "https://www.comune.escalaplano.ca.it/", "alt_name": "Iscalepranu", "boundary": "administrative", "wikidata": "Q179092", "ref:ISTAT": "111018", "wikipedia": "it:Escalaplano", "admin_level": "8", "postal_code": "08043", "ref:catasto": "D430", "wikipedia:sc": "Scalepranu"}
        $wheres = [];
        foreach ($allFeatures as $feature) {
            $properties = $feature['properties'];
            $whereId = $properties['osmfeatures_id'];
            $featureTags = $properties['osm_tags'];
            $adminLevel = $featureTags['admin_level'] ?? null;
            $name = null;
            foreach ($featureTags as $tagName => $tagValue) {

                if ($tagName === 'name') {
                    $name = $tagValue;
                } elseif (($pos = strpos($tagName, 'name:')) !== false) {
                    // 5 = the length of "name:"
                    // 2 = the length of the language code, eg: "it", "en"
                    $language = substr($tagName, $pos + 5, 2);
                    if (in_array($language, ['it', 'en', 'de', 'fr', 'es'])) {
                        $wheres[$whereId][$language] = $tagValue;
                    }
                }
            }

            // If translations aren't provided then use the default name as italian and english label
            if (empty($wheres[$whereId]) && $name !== null) {
                $wheres[$whereId] = [
                    'it' => $name,
                    'en' => $name,
                ];
            }

            // Save admin_level to allow sorting (regions first, then municipalities)
            if ($adminLevel !== null) {
                $wheres[$whereId]['_admin_level'] = (int) $adminLevel;
            }
        }

        return $wheres;
    }

    protected function getAdminAreasIntersected(array $geojson, int $adminLevel = 8)
    {

        $response = $this->getHttpClient()->post(
            $this->getAdminAreasIntersectsUrl(),
            [
                'geojson' => $geojson,
                'admin_level' => $adminLevel,
            ]
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
        return $this->getHost() . '/api/v1/features/admin-areas/geojson';
    }

    public function getAdminAreasIds(string $bbox, int $adminLevel): array
    {
        $bbox = $this->normalizeBboxForQuery($bbox);

        $items = [];
        $page = 1;
        do {
            $response = Http::get($this->getHost() . '/api/v2/features/admin-areas/list', [
                'bbox'        => $bbox,
                'admin_level' => $adminLevel,
                'tags'        => 'name',
                'page'        => $page,
            ]);

            if (! $response->successful()) {
                throw new Exception(
                    "OSMFeatures admin-areas/list HTTP {$response->status()}: " . $response->body()
                );
            }

            $data = $response->json('data', []);
            foreach ($data as $item) {
                $nameObj = $item['name'] ?? [];
                $name = $nameObj['it'] ?? $nameObj['en'] ?? (is_array($nameObj) ? (reset($nameObj) ?: $item['id']) : $item['id']);
                $items[] = [
                    'id'         => $item['id'],
                    'name'       => $name,
                    'updated_at' => $item['updated_at'] ?? null,
                ];
            }
            $page++;
        } while (count($data) === 1000);

        return $items;
    }

    /**
     * L'API si aspetta tipicamente "minLon,minLat,maxLon,maxLat". Se map_bbox è JSON
     * (es. salvato come array nel DB), la query string altrimenti risulta inutilizzabile e l'API risponde vuota.
     */
    protected function normalizeBboxForQuery(string $bbox): string
    {
        $trimmed = trim($bbox);
        if ($trimmed === '') {
            return $bbox;
        }

        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && count($decoded) === 4) {
                return implode(',', array_map(static fn($v) => (string) $v, $decoded));
            }
        }

        return $bbox;
    }

    public function getAdminAreaDetail(string $osmfeaturesId): array
    {
        $response = Http::get($this->getHost() . '/api/v2/features/admin-areas/' . $osmfeaturesId);
        $data = $response->json();

        return [
            'osmfeatures_id' => $osmfeaturesId,
            'name'           => $data['properties']['name'] ?? $osmfeaturesId,
            'admin_level'    => $data['properties']['admin_level'] ?? null,
            'geometry'       => isset($data['geometry']) ? json_encode($data['geometry']) : null,
        ];
    }

    protected function getHost(): string
    {
        return rtrim(config('wm-package.clients.osmfeatures.host'));
    }
}
