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
use Wm\WmPackage\Jobs\UpdateLayerTracksJob;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
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
        $oldDemData = json_decode($track->dem_data, true);
        $track->dem_data = $demData;
        $track->saveQuietly();
        try {
            if (isset($demData)) {
                foreach ($this->fields as $field) {
                    if (isset($demData[$field]) && ! empty($demData[$field]) && is_null($track->$field)) {
                        $track->$field = $this->updateFieldIfNecessary($track, $field, $demData, $oldDemData);
                    }
                }
            }

            $track->saveQuietly();
        } catch (\Exception $e) {
            Log::error('An error occurred during DEM operation: '.$e->getMessage());
        }
    }

    public function updateOsmData(EcTrack $track)
    {
        $result = ['success' => false, 'message' => '', 'track' => $track];

        try {
            $osmId = trim($track->osmid);
            $osmClient = new OsmClient;
            $geojson_content = $osmClient::getGeojson('relation/'.$osmId);
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

            $track->name = ! empty($track->name) ? $track->name : $trackname;
            $track->geometry = $geometry ?? $track->geometry;
            $track->ref = $track->ref ?? $osmData['ref'] ?? null;

            // Update additional fields only if they are null
            $oldOsmData = json_decode($track->osm_data, true);
            $track->cai_scale = $this->updateFieldIfNecessary($track, 'cai_scale', $osmData, $oldOsmData);
            $track->from = $this->updateFieldIfNecessary($track, 'from', $osmData, $oldOsmData);
            $track->to = $this->updateFieldIfNecessary($track, 'to', $osmData, $oldOsmData);
            $track->ascent = $this->updateFieldIfNecessary($track, 'ascent', $osmData, $oldOsmData);
            $track->descent = $this->updateFieldIfNecessary($track, 'descent', $osmData, $oldOsmData);
            $track->distance = $this->updateFieldIfNecessary($track, 'distance', $osmData, $oldOsmData, true);
            $track->duration_forward = $this->updateFieldIfNecessary($track, 'duration_forward', $osmData, $oldOsmData);
            $track->duration_backward = $this->updateFieldIfNecessary($track, 'duration_backward', $osmData, $oldOsmData);
            $track->osm_data = $osmData;
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
            $manualData = json_decode($track->manual_data ?? null, true);

            foreach ($dirtyFields as $field => $newValue) {
                $manualData[$field] = $newValue;
                if (is_null($newValue)) {
                    $demData = json_decode($track->dem_data, true);
                    $osmData = json_decode($track->osm_data, true);
                    if (isset($osmData[$field]) && ! is_null($osmData[$field])) {
                        $track[$field] = $osmData[$field];
                        Log::info("Updated $field with OSM value: ".$osmData[$field]);
                    } elseif (isset($demData[$field]) && ! is_null($demData[$field])) {
                        $track[$field] = $demData[$field];
                        Log::info("Updated $field with DEM value: ".$demData[$field]);
                    }
                }
            }

            $track->manual_data = $manualData;
            $track->saveQuietly();
        } catch (\Exception $e) {
            Log::error($track->id.': HandlesData: An error occurred during a store operation: '.$e->getMessage());
        }
    }

    public function updateManualData(EcTrack $track)
    {

        $manualData = null;
        $fieldsToCheck = $this->fields;
        $demData = json_decode($track->dem_data, true);
        $osmData = json_decode($track->osm_data, true);
        foreach ($fieldsToCheck as $field) {
            $osmValue = $osmData[$field] ?? null;
            $demValue = $demData[$field] ?? null;
            $trackValue = $track->{$field};

            if ($trackValue !== null && $trackValue != $osmValue && $trackValue != $demValue) {
                $manualData[$field] = $trackValue;
            }
        }

        $track->manual_data = $manualData;
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
        if ($track->{$field} === null || (! is_null($oldProperties) && isset($oldProperties[$field]) && $track->{$field} == $oldProperties[$field])) {
            if (isset($properties[$field])) {
                return $isNumeric ? str_replace(',', '.', $properties[$field]) : $properties[$field];
            }
        }

        return $track->{$field};
    }

    public function updateDataChain(EcTrack $track)
    {
        $chain = [];
        if ($track->osmid) {
            $chain[] = new UpdateEcTrackFromOsmJob($track);
        }
        $layers = $track->associatedLayers;
        // Verifica se ci sono layers associati
        if ($layers && $layers->count() > 0) {
            foreach ($layers as $layer) {
                $chain[] = new UpdateLayerTracksJob($layer);
            }
        }
        $chain[] = new UpdateEcTrackDemJob($track);
        $chain[] = new UpdateEcTrackManualDataJob($track);
        $chain[] = new UpdateEcTrackCurrentDataJob($track);
        $chain[] = new UpdateEcTrack3DDemJob($track);
        $chain[] = new UpdateEcTrackSlopeValues($track);
        $chain[] = new UpdateModelWithGeometryTaxonomyWhere($track);
        $chain[] = new UpdateEcTrackGenerateElevationChartImage($track);
        $chain[] = new UpdateEcTrackAwsJob($track);
        $chain[] = new UpdateEcTrackAppRelationsInfoJob($track);
        $chain[] = new GenerateEcTrackPBFBatch($track);
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
                $updates['activities'][$layer->app_id] = $ecTrack->getTaxonomyArray($ecTrack->taxonomyActivities);
                $updates['themes'][$layer->app_id] = $ecTrack->getTaxonomyArray($ecTrack->taxonomyThemes);
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

    // TODO: fix this function and add tests
    // public function getUpdatedAtTracks(?User $user = null): Collection
    // {
    //     if ($user) {
    //         $arr = EcTrack::where('user_id', $user->id)->pluck('updated_at', 'id');
    //     } else {

    //         $arr = DB::select('select id, updated_at from ec_tracks where user_id != 20548 and user_id != 17482');
    //         $arr = collect($arr)->pluck('updated_at', 'id');
    //     }

    //     return $arr;
    // }
}
