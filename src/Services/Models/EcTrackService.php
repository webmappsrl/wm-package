<?php

namespace Wm\WmPackage\Services\Models;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackFromOsmJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\BaseService;
use Wm\WmPackage\Services\GeometryComputationService;

class EcTrackService extends BaseService
{
    public $fields = [
        'ele_min',
        'ele_max',
        'ele_from',
        'ele_to',
        'ascent',
        'descent',
        'distance',
        'duration_forward',
        'duration_backward',
    ];

    public function __construct(
        protected GeometryComputationService $geometryComputationService,
        protected DemClient $demClient
    ) {}

    public function getDemDataFields()
    {
        return $this->fields;
    }

    /**
     * Update track with DEM data.
     *
     * @return void
     */
    public function updateDemData(EcTrack $track)
    {
        $geojson = $track->getGeojson();

        // Request was successful, handle the response data here
        $responseData = $this->demClient->getTechData($geojson);
        $demData = $responseData['properties'];
        $demData['duration_forward'] = $demData['duration_forward_hiking'];
        $demData['duration_backward'] = $demData['duration_backward_hiking'];

        $oldDemData = $track->properties['dem_data'] ?? [];
        $properties = $track->properties;
        $properties['dem_data'] = $demData;
        $track->properties = $properties;

        try {
            if (isset($demData)) {
                foreach ($this->getDemDataFields() as $field) {
                    if (
                        isset($demData[$field])
                        && ! empty($demData[$field])
                        && isset($track->properties['dem_data'][$field]) && is_null($track->properties['dem_data'][$field])
                    ) {
                        $properties = $track->properties;
                        $properties['dem_data'][$field] = $this->updateFieldIfNecessary($track, $field, $demData, $oldDemData);
                        $track->properties = $properties;
                    }
                }
            }

            $track->saveQuietly();
        } catch (Exception $e) {
            Log::error('An error occurred during DEM operation: ' . $e->getMessage());
        }
    }

    public function updateOsmData(EcTrack $track)
    {
        $result = ['success' => false, 'message' => '', 'track' => $track];

        try {
            $osmId = $track->properties['osmid'] ?? null;
            if (is_null($osmId)) {
                throw new Exception('No OSM ID found');
            }
            $osmClient = new OsmClient;
            $geojson_content = $osmClient::getGeojson('relation/' . $osmId);
            $geojson_content = json_decode($geojson_content, true);
            $osmData = $geojson_content['properties'];
            if (isset($osmData['duration:forward'])) {
                $osmData['duration_forward'] = $this->convertDuration($osmData['duration:forward']);
            }
            if (isset($osmData['duration:backward'])) {
                $osmData['duration_backward'] = $this->convertDuration($osmData['duration:backward']);
            }

            if (empty($geojson_content['geometry']) || empty($osmData)) {
                throw new Exception('Wrong OSM ID');
            }

            $geojson_geometry = json_encode($geojson_content['geometry']);
            $geometry = GeometryComputationService::make()->get3dLineMergeWktFromGeojson($geojson_geometry);

            $name_array = [];
            if (array_key_exists('ref', $osmData) && ! empty($osmData['ref'])) {
                array_push($name_array, $osmData['ref']);
            }
            if (array_key_exists('name', $osmData) && ! empty($osmData['name'])) {
                array_push($name_array, $osmData['name']);
            }

            $trackname = ! empty($name_array) ? implode(' - ', $name_array) : null;
            $trackname = str_replace('"', '', $trackname);

            $properties = $track->properties;
            $track->name = ! empty($track->name) ? $track->name : $trackname;
            $properties['name'] = $track->name;
            $track->geometry = $geometry ?? $track->geometry;
            $properties['ref'] = $properties['ref'] ?? $osmData['ref'] ?? null;

            // Update additional fields only if they are null
            $oldOsmData = isset($track->properties['osm_data']) ? (
                is_array($track->properties['osm_data'])
                ? $track->properties['osm_data']
                : json_decode($track->properties['osm_data'], true)
            ) : [];

            $properties['cai_scale'] = $this->updateFieldIfNecessary($track, 'cai_scale', $osmData, $oldOsmData);
            $properties['from'] = $this->updateFieldIfNecessary($track, 'from', $osmData, $oldOsmData);
            $properties['to'] = $this->updateFieldIfNecessary($track, 'to', $osmData, $oldOsmData);
            $properties['ascent'] = $this->updateFieldIfNecessary($track, 'ascent', $osmData, $oldOsmData);
            $properties['descent'] = $this->updateFieldIfNecessary($track, 'descent', $osmData, $oldOsmData);
            $properties['distance'] = $this->updateFieldIfNecessary($track, 'distance', $osmData, $oldOsmData, true);
            $properties['duration_forward'] = $this->updateFieldIfNecessary($track, 'duration_forward', $osmData, $oldOsmData);
            $properties['duration_backward'] = $this->updateFieldIfNecessary($track, 'duration_backward', $osmData, $oldOsmData);
            $properties['osm_data'] = $osmData;
            $track->properties = $properties;
            $track->saveQuietly();

            $result['success'] = true;
        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    public function updateCurrentData(EcTrack $track)
    {
        try {
            $dirtyFields = $track->getDirty();
            $demDataFields = array_flip($track->getDemDataFields());
            $dirtyFields = array_intersect_key($dirtyFields, $demDataFields);
            $manualData = isset($track->properties['manual_data']) ? (
                is_array($track->properties['manual_data'])
                ? $track->properties['manual_data']
                : json_decode($track->properties['manual_data'], true)
            ) : null;

            $properties = $track->properties;
            foreach ($dirtyFields as $field => $newValue) {
                $manualData[$field] = $newValue;
                if (is_null($newValue)) {
                    $demData = isset($properties['dem_data']) ? (
                        is_array($properties['dem_data'])
                        ? $properties['dem_data']
                        : json_decode($properties['dem_data'], true)
                    ) : [];
                    $osmData = isset($properties['osm_data']) ? (
                        is_array($properties['osm_data'])
                        ? $properties['osm_data']
                        : json_decode($properties['osm_data'], true)
                    ) : [];
                    if (isset($osmData[$field]) && ! is_null($osmData[$field])) {
                        $properties[$field] = $osmData[$field];
                        Log::info("Updated $field with OSM value: " . $osmData[$field]);
                    } elseif (isset($demData[$field]) && ! is_null($demData[$field])) {
                        $properties[$field] = $demData[$field];
                        Log::info("Updated $field with DEM value: " . $demData[$field]);
                    }
                }
            }

            $properties['manual_data'] = $manualData;
            $track->properties = $properties;
            $track->saveQuietly();
        } catch (Exception $e) {
            Log::error($track->id . ': HandlesData: An error occurred during a store operation: ' . $e->getMessage());
        }
    }

    public function updateManualData(EcTrack $track)
    {
        $manualData = null;
        $fieldsToCheck = $this->getDemDataFields();

        $demData = isset($track->properties['dem_data']) ? (
            is_array($track->properties['dem_data']) ?
            $track->properties['dem_data']
            : json_decode($track->properties['dem_data'], true)
        )
            : [];
        $osmData = isset($track->properties['osm_data']) ? (
            is_array($track->properties['osm_data']) ?
            $track->properties['osm_data']
            : json_decode($track->properties['osm_data'], true)
        )
            : [];
        $properties = $track->properties;
        foreach ($fieldsToCheck as $field) {
            $osmValue = $osmData[$field] ?? null;
            $demValue = $demData[$field] ?? null;
            $trackValue = $properties[$field] ?? null;

            // Check if the track value is different from both OSM and DEM values
            if (! in_array($trackValue, [null, $osmValue, $demValue])) {
                $manualData[$field] = $trackValue;
            }
        }

        $properties = $track->properties;
        $properties['manual_data'] = $manualData;
        $track->properties = $properties;
        $track->saveQuietly();
    }

    /**
     * Converts the given duration to a specific format.
     *
     * @param  int  $duration  The duration to be converted.
     * @return string The converted duration.
     */
    protected function convertDuration($duration)
    {
        if ($duration === null) {
            return null;
        }

        $duration = str_replace(['.', ',', ';'], ':', $duration);
        $parts = explode(':', $duration);

        return ($parts[0] * 60) + $parts[1];
    }

    /**
     * Check if the current field value matches the value in dem_data.
     *
     * @param  string  $field
     * @return bool
     */
    protected function matchesDemData(EcTrack $track, $field)
    {
        $demData = $track->dem_data;
        if (isset($demData[$field])) {
            return $track->{$field} == $demData[$field];
        }

        return false;
    }

    /**
     * Update a field if necessary.
     *
     * @param  string  $field
     * @param  array  $properties
     * @param  bool  $isNumeric
     * @return mixed
     */
    protected function updateFieldIfNecessary(EcTrack $track, $field, $properties, $oldProperties, $isNumeric = false)
    {
        $trackProperties = $track->properties;
        if (
            isset($properties[$field]) // se esiste una nuova proprietà da salvare
            && // E
            (
                ! isset($trackProperties[$field]) // se non esiste la proprietà su track
                || $trackProperties[$field] === null // se la proprietà esistente è null
                || ( // o se esiste una vecchia proprietà e è uguale a quella salvata su track->properties
                    isset($oldProperties[$field])
                    && $trackProperties[$field] == $oldProperties[$field])
            )
        ) {
            // allora restituisci il nuovo campo
            return $isNumeric ? str_replace(',', '.', $properties[$field]) : $properties[$field];
        }

        // altrimenti la proprietà rimane invariata (niente cambia in track)
        return $trackProperties[$field] ?? null;
    }

    public function createDataChain(EcTrack $track)
    {
        $chain = [];
        if (isset($track->properties['osmid']) && $track->properties['osmid']) {
            $chain[] = new UpdateEcTrackFromOsmJob($track);
        }
        $chain[] = new UpdateEcTrackDemJob($track);
        $chain[] = new UpdateEcTrackManualDataJob($track);
        $chain[] = new UpdateEcTrackCurrentDataJob($track);
        $chain[] = new UpdateEcTrack3DDemJob($track);
        $chain[] = new UpdateEcTrackSlopeValues($track);
        $chain[] = new UpdateModelWithGeometryTaxonomyWhere($track);
        $chain[] = new UpdateEcTrackGenerateElevationChartImage($track);
        $chain[] = new UpdateEcTrackAwsJob($track);
        $chain[] = new UpdateEcTrackOrderRelatedPoi($track);

        Bus::chain($chain)->dispatch();
    }

    public function updateDataChain(EcTrack $track)
    {
        $chain = [];
        if (isset($track->properties['osmid']) && $track->properties['osmid']) {
            $chain[] = new UpdateEcTrackFromOsmJob($track);
        }
        // $layers = $track->associatedLayers;
        // // Verifica se ci sono layers associati
        // if ($layers && $layers->count() > 0) {
        //     foreach ($layers as $layer) {
        //         $chain[] = new UpdateLayerTracksJob($layer);
        //     }
        // }
        if ($track->wasChanged('geometry')) {
            $chain[] = new UpdateEcTrackDemJob($track);
            $chain[] = new UpdateEcTrackManualDataJob($track);
            $chain[] = new UpdateEcTrackCurrentDataJob($track);
            $chain[] = new UpdateEcTrack3DDemJob($track);
            $chain[] = new UpdateEcTrackSlopeValues($track);
            $chain[] = new UpdateModelWithGeometryTaxonomyWhere($track);
            $chain[] = new UpdateEcTrackGenerateElevationChartImage($track);
            $chain[] = new GenerateEcTrackPBFBatch($track);
        }

        $chain[] = new UpdateEcTrackAwsJob($track);
        $chain[] = new UpdateEcTrackAppRelationsInfoJob($track);
        $chain[] = new UpdateEcTrackOrderRelatedPoi($track);

        Bus::chain($chain)->dispatch();
    }

    // IT GETS data from ec TRACK and compute the proper order filling outputData['related_pois_order'] array
    public function getRelatedPoisOrder(EcTrack $ecTrack)
    {
        $geojson = $ecTrack->getGeojson();
        // CHeck if TRACK has related POIS
        if (! isset($geojson['ecTrack']['properties']['related_pois'])) {
            // SKIP;
            return;
        }
        $related_pois = $geojson['ecTrack']['properties']['related_pois'];
        $track_geometry = $geojson['ecTrack']['geometry'];

        $oredered_pois = [];
        foreach ($related_pois as $poi) {
            $poi_geometry = $poi['geometry'];
            $oredered_pois[$poi['properties']['id']] = $this->geometryComputationService
                ->getLineLocatePointFloat(json_encode($track_geometry), json_encode($poi_geometry));
        }
        asort($oredered_pois);

        return array_keys($oredered_pois);
    }

    public function updateTrackAppRelationsInfo(EcTrack $ecTrack)
    {
        $updates = null;
        $ecTrackLayers = $ecTrack->associatedLayers;
        foreach ($ecTrackLayers as $layer) {
            if (! empty($layer)) {
                $updates['layers'][$layer->app_id] = $layer->id;
                $updates['activities'][$layer->app_id] = $this->getTaxonomyArray($ecTrack->taxonomyActivities);
                $updates['searchable'][$layer->app_id] = $ecTrack->getSearchableString($layer->app_id);
            }
        }
        if ($updates) {
            EcTrack::withoutEvents(function () use ($updates, $ecTrack) {
                $ecTrack->update($updates);
            });
        }
    }

    /**
     * Retrieves the $limit most viewed ec tracks
     *
     * @param  App  $app  the reference app
     * @param  int  $limit  the max number of tracks to respond
     * @return array the geojson feature collection
     */
    // TODO: select the most viewed tracks from a real analytic value and not randomly
    public static function getMostViewed(App $app, int $limit = 5): array
    {
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];

        $validTrackIds = null;

        if ($app->app_id !== 'it.webmapp.webmapp') {
            $validTrackIds = $app->ecTracks->pluck('id')->toArray() ?? [];
        }

        $tracks = is_null($validTrackIds)
            ? EcTrack::limit($limit)->get()
            : EcTrack::whereIn('id', $validTrackIds)->limit($limit)->get();

        foreach ($tracks as $track) {
            $featureCollection['features'][] = $track->getGeojson();
        }

        return $featureCollection;
    }

    public function getUpdatedAtTracks(?int $app_id = null): Collection
    {
        if ($app_id) {
            $arr = EcTrack::where('app_id', $app_id)->pluck('updated_at', 'id');
        } else {
            // Recupera il nome della tabella dal modello
            $tableName = config('wm-package.ec_track_table_name');
            $arr = DB::select("select id, updated_at from {$tableName}");
            $arr = collect($arr)->pluck('updated_at', 'id');
        }

        return $arr;
    }

    public function getTaxonomyArray($taxonomyCollection)
    {
        return $taxonomyCollection->count() > 0 ? $taxonomyCollection->pluck('identifier')->toArray() : [];
    }

    public function getTaxonomyWheres(EcTrack $track)
    {
        return $track->properties['taxonomy_where'] ?? [];
    }

    public function getTaxonomyIcons(EcTrack $track)
    {
        $taxonomyIcons = [];

        // Ottieni le attività della track
        $activities = $track->taxonomyActivities;

        foreach ($activities as $activity) {
            $activityIdentifier = $activity->identifier;

            // Crea la struttura per ogni attività
            $taxonomyIcons[$activityIdentifier] = [
                'label' => $activity->getTranslations('name'),
                'icon_name' => $activity->icon,
            ];
        }

        return $taxonomyIcons;
    }
}
