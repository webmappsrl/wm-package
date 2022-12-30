<?php

namespace Wm\WmPackage\Http;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Exceptions\OsmClientException;
use Wm\WmPackage\Exceptions\OsmClientExceptionNodeHasNoLat;
use Wm\WmPackage\Exceptions\OsmClientExceptionNodeHasNoLon;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoElements;
use Wm\WmPackage\Exceptions\OsmClientExceptionNoTags;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasNoNodes;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasNoRelationElement;
use Wm\WmPackage\Exceptions\OsmClientExceptionRelationHasNoWays;
use Wm\WmPackage\Exceptions\OsmClientExceptionWayHasNoNodes;

/**
 * General purpose OpenStreetMap http client.
 *
 * Based on OSM V0.6 API: https://wiki.openstreetmap.org/wiki/API_v0.6
 * This service can be used to obtain geojson format for node, way and relation from
 * OpenStreetMap.
 *
 *
 * Useful examples:
 * NODE:
 * OSM: https://openstreetmap.org/node/770561143
 * XML: https://api.openstreetmap.org/api/0.6/node/770561143
 * JSON: https://api.openstreetmap.org/api/0.6/node/770561143.json
 *
 * WAY:
 * OSM: https://openstreetmap.org/way/145096288
 * XML: https://api.openstreetmap.org/api/0.6/way/145096288
 * XMLFULL: https://api.openstreetmap.org/api/0.6/way/145096288/full
 * JSON: https://api.openstreetmap.org/api/0.6/way/145096288.json
 * JSONFULL: https://api.openstreetmap.org/api/0.6/way/145096288/full.json
 *
 * RELATION:
 * OSM: https://openstreetmap.org/relation/12312405
 * XML: https://api.openstreetmap.org/api/0.6/relation/12312405
 * XMLFULL: https://api.openstreetmap.org/api/0.6/relation/12312405/full
 * JSON: https://api.openstreetmap.org/api/0.6/relation/12312405.json
 * JSONFULL: https://api.openstreetmap.org/api/0.6/relation/12312405/full.json
 *
 * TODO: Exception remove all generic relation (throw new OsmClientException) with specific Exception and
 *       update test with specific Exception
 *
 * ROADMAP:
 *
 * BACKLOG:
 * osmclient_relation_224.2 Eccezioni per integrità della geometria (deve essere linestring)
 * osmclient_relation_224.3 Result from linear cases (impostazione test con caso semplice e casi reale)
 * osmclient_relation_224.4 Result from roundtrip cases (impostazione test con caso semplice e casi reale)
 *
 * DONE:
 * osmclient_relation_224.1 Impostazione funzionamento per la relation (eccezioni di base e costruzione struttura interna)
 *
 *
 * TRY ON TINKER
 * $s = OsmClient::getGeojson('node/770561143');
 * $s = OsmClient::getGeojson('way/145096288 ');
 */
class OsmClient
{
    /**
     * Undocumented function
     *
     * @param  string  $osmid Osmid string with type: node/[id], way/[id], relation/[id]
     * @param  bool  $retun_array set it as true if you want return value as array
     */
    public function getGeojson(string $osmid): string
    {
        if (! $this->checkOsmId($osmid)) {
            throw new OsmClientException('Invalid osmid '.$osmid);
        }

        $geojson = [];
        $geojson['version'] = 0.6;
        $geojson['generator'] = 'Laravel OsmClient by WEBMAPP';
        $geojson['_osmid'] = $osmid;
        $geojson['type'] = 'Feature';

        $geojson['_api_url'] = $this->getFullOsmApiUrlByOsmId($osmid);

        $props_and_geom = $this->getPropertiesAndGeometry($osmid);
        $geojson['properties'] = $props_and_geom[0];
        $geojson['geometry'] = $props_and_geom[1];

        return json_encode($geojson);
    }

    /**
     * Returns the URL OSM v06 JSON API string (full form way and relation)
     *
     * @param [type] $osmid
     * @return string
     */
    public function getFullOsmApiUrlByOsmId($osmid): string
    {
        $url = 'https://api.openstreetmap.org/api/0.6/'.$osmid;
        if (preg_match('/node/', $osmid)) {
            $url = $url.'.json';
        } else {
            // way and relation directly call full.json
            $url = $url.'/full.json';
        }

        return $url;
    }

    /**
     * Return true if osmid is valid: node/[id], way/[id], relation/[id]
     *
     * @param  string  $osmid
     * @return bool true if is valid false otherwise
     */
    public function checkOsmId(string $osmid): bool
    {
        if (preg_match('#^node/\d+$#', $osmid) == 1) {
            return true;
        }
        if (preg_match('#^way/\d+$#', $osmid) == 1) {
            return true;
        }
        if (preg_match('#^relation/\d+$#', $osmid) == 1) {
            return true;
        }

        return false;
    }

    public function getPropertiesAndGeometry($osmid): array
    {
        $url = $this->getFullOsmApiUrlByOsmId($osmid);
        $json = Http::get($url)->json();
        if (! array_key_exists('elements', $json)) {
            throw new OsmClientExceptionNoElements("Response from OSM has something wrong: check it out with $url.", 1);
        }
        if (preg_match('/node/', $osmid)) {
            return $this->getPropertiesAndGeometryForNode($json);
        } elseif (preg_match('/way/', $osmid)) {
            return $this->getPropertiesAndGeometryForWay($json);
        } elseif (preg_match('/relation/', $osmid)) {
            return $this->getPropertiesAndGeometryForRelation($json);
        } else {
            throw new OsmClientException('OSMID has not vali type (node,way,relation) '.$osmid);
        }

        return [];
    }

    private function getPropertiesAndGeometryForNode(array $json): array
    {
        if (! isset($json['elements'][0]['tags'])) {
            throw new OsmClientExceptionNoTags('JSON from OSM has no tags', 1);
        }
        if (! isset($json['elements'][0]['lat'])) {
            throw new OsmClientExceptionNodeHasNoLat('JSON from OSM has no lat', 1);
        }
        if (! isset($json['elements'][0]['lon'])) {
            throw new OsmClientExceptionNodeHasNoLon('JSON from OSM has no lon', 1);
        }
        $properties = $json['elements'][0]['tags'];
        $geometry = [
            'type' => 'Point',
            'coordinates' => [
                $json['elements'][0]['lon'],
                $json['elements'][0]['lat'],
            ],
        ];
        $properties['_updated_at'] = $this->getUpdatedAt($json);

        return [$properties, $geometry];
    }

    private function getPropertiesAndGeometryForWay(array $json): array
    {
        $nodes_full = [];
        $nodes = [];
        $properties = [];
        $geometry = [];
        $coordinates = [];

        // Loop on elements
        foreach ($json['elements'] as $element) {
            if ($element['type'] == 'node') {
                if (! array_key_exists('lon', $element)) {
                    throw new OsmClientExceptionNodeHasNoLon('No lon (longitude) found', 1);
                }
                if (! array_key_exists('lat', $element)) {
                    throw new OsmClientExceptionNodeHasNoLat('No lat (latitude) found', 1);
                }
                $nodes_full[$element['id']] = [
                    $element['lon'],
                    $element['lat'],
                ];
            } elseif ($element['type'] == 'way') {
                if (! array_key_exists('tags', $element)) {
                    throw new OsmClientExceptionNoTags('No tags found in way', 1);
                }
                $properties = $element['tags'];
                if (! array_key_exists('nodes', $element)) {
                    throw new OsmClientExceptionWayHasNoNodes('No nodes found in way', 1);
                }
                $nodes = $element['nodes'];
            }
        }

        // Build Geometry
        foreach ($nodes as $id) {
            $coordinates[] = $nodes_full[$id];
        }
        $geometry['type'] = 'LineString';
        $geometry['coordinates'] = $coordinates;
        $properties['_updated_at'] = $this->getUpdatedAt($json);

        return [$properties, $geometry];
    }

    /**
     * Check $json consinstency and builds proper properies and geometry (MultiLineString)
     *
     * 
     * The following example is the minimal working version (two nodes)
     * (json format)
     * @param  array  $json relation coming from Osm v0.6 full API (https://api.openstreetmap.org/api/0.6/relation/12312405/full.json)
     * 
     * {
     *    "elements": [
     *         { "type": "node", "id": 11, "lon": 11.1, "lat": 11.2, "timestamp": "2020-01-01T01:01:01Z" },
     *         { "type": "node", "id": 12, "lon": 12.1, "lat": 12.2, "timestamp": "2020-02-02T02:02:02Z" },
     *         { "type": "way", "id": 31, "timestamp": "2020-01-01T01:01:01Z", "nodes": [11,12] },
     *         { "type": "relation", "id": 31, "timestamp": "2020-01-01T01:01:01Z",
     *           "members": [
     *                         { "type": "way", "ref": 11, "role": "" }
     *                      ],
     *           "tags": { "key1": "val1", "key2": "val2" }
     *         }
     *       ]
     * }
     * @return array
     */
    private function getPropertiesAndGeometryForRelation(array $json): array
    {
        $properties = [];
        $geometry = [];
        $nodes = [];
        $ways = [];
        $relation = [];

        foreach($json['elements'] as $element) {
            if ($element['type']=='node') {
                $nodes[$element['id']]=$element;
            }
            else if ($element['type']=='way') {
                $ways[$element['id']]=$element;
            }
            else if ($element['type']=='relation') {
                $relation=$element;
            }
        }

        // Check input
        if(count($nodes)==0) {
            throw new OsmClientExceptionRelationHasNoNodes("It seems that relation has no nodes in elements");
        }
        if(count($ways)==0) {
            throw new OsmClientExceptionRelationHasNoWays("It seems that relation has no ways in elements");
        }
        if(count($relation)==0) {
            throw new OsmClientExceptionRelationHasNoRelationElement("It seems that relation has no nodes in elements");
        }
        


        return [$properties, $geometry];
    }

    /**
     * It returns the REAL updated_at time for a OSM feature. Where real means the most recent element
     * that builds up the feature. For example if a relation has timestamp value 01-01-2000 and one of
     * way member has timestamp 01-01-2001 the return value will be 01-01-2001 and NOT 01-01-2000
     *
     * @param  array  $json Json array response from node/way/relation full API (v06)
     * @return string
     */
    public function getUpdatedAt(array $json): string
    {
        if (! array_key_exists('elements', $json)) {
            throw new OsmClientException('Json ARRAY has not elements key, something is wrong.', 1);
        }
        $updated_at = [];
        foreach ($json['elements'] as $element) {
            if (! array_key_exists('timestamp', $element)) {
                throw new OsmClientException('An element has no TIMESTAMP key', 1);
            }
            $updated_at[] = strtotime($element['timestamp']);
        }

        return date('Y-m-d H:i:s', max($updated_at));
    }
}
