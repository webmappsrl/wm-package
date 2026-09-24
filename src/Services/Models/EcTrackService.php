<?php

namespace Wm\WmPackage\Services\Models;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use Wm\WmPackage\Facades\OsmClient;
use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
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
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity;
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
     * La risposta grezza del servizio DEM per una traccia.
     *
     * Separata da updateDemData() perche' la usa anche l'istanza del Catasto
     * Sentieri, che salva il risultato in SQL e non via Eloquent.
     */
    public function fetchDemTechData(array $geojson): array
    {
        return $this->demClient->getTechData($geojson);
    }

    /**
     * I valori DEM nella forma che si salva in `properties['dem_data']`: le
     * durate "correnti" sono quelle per l'escursionismo.
     */
    public function normalizeDemData(array $properties): array
    {
        $properties['duration_forward'] = $properties['duration_forward_hiking'] ?? null;
        $properties['duration_backward'] = $properties['duration_backward_hiking'] ?? null;

        return $properties;
    }

    /**
     * Update track with DEM data.
     *
     * @return void
     */
    public function updateDemData(EcTrack $track)
    {
        $geojson = $track->getGeojson();

        $responseData = $this->fetchDemTechData($geojson);
        $demData = $this->normalizeDemData($responseData['properties']);

        $oldDemData = $track->properties['dem_data'] ?? [];
        $properties = $track->properties;
        $properties['dem_data'] = $demData;
        $track->properties = $properties;

        try {
            if ($demData !== []) {
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
            Log::error('An error occurred during DEM operation: '.$e->getMessage());
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

            $properties = $track->properties;
            $track->name = ! empty($track->name) ? $track->name : $trackname;
            $properties['name'] = $track->name;
            $track->geometry = $geometry;
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
                        Log::info("Updated $field with OSM value: ".$osmData[$field]);
                    } elseif (isset($demData[$field]) && ! is_null($demData[$field])) {
                        $properties[$field] = $demData[$field];
                        Log::info("Updated $field with DEM value: ".$demData[$field]);
                    }
                }
            }

            $properties['manual_data'] = $manualData;
            $track->properties = $properties;
            $track->saveQuietly();
        } catch (Exception $e) {
            Log::error($track->id.': HandlesData: An error occurred during a store operation: '.$e->getMessage());
        }
    }

    public function updateManualData(EcTrack $track)
    {
        // Si parte dai manuali gia' presenti: quelli scritti dal tab DEM vivono
        // solo in manual_data, e ricostruirlo dal primo livello li cancellerebbe
        // (oc:8571). Il primo livello resta una sorgente in piu', per il flusso
        // OSM/GeoHub che lo scrive ancora (eliminazione in oc:8642).
        $existing = $track->properties['manual_data'] ?? [];
        $manualData = is_array($existing) ? $existing : (json_decode((string) $existing, true) ?: []);
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
        $properties['manual_data'] = $manualData === [] ? null : $manualData;
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
        $chain[] = new SyncModelTaxonomyWhereJob($track);
        $chain[] = new UpdateEcTrackGenerateElevationChartImage($track);
        $chain[] = new UpdateEcTrackAwsJob($track);
        $chain[] = new UpdateEcTrackOrderRelatedPoi($track);

        Bus::chain($chain)->dispatch();
    }

    /**
     * I job del ricalcolo che dipendono dalla geometria, nell'ordine in cui vanno accodati.
     *
     * È la lista comune a updateDataChain() e reverse(): un job aggiunto qui entra anche nella
     * catena dell'inversione, a meno che reverse() non lo escluda di proposito (oc:8543).
     *
     * @param  array<int, class-string>  $except  classi da togliere dalla lista
     * @return array<int, object>
     */
    public function geometryDependentJobs(EcTrack $track, array $except = []): array
    {
        $jobs = [
            new UpdateEcTrackDemJob($track),
            new UpdateEcTrackManualDataJob($track),
            new UpdateEcTrackCurrentDataJob($track),
            new UpdateEcTrack3DDemJob($track),
            new UpdateEcTrackSlopeValues($track),
            new SyncModelTaxonomyWhereJob($track),
            new UpdateEcTrackGenerateElevationChartImage($track),
            new GenerateEcTrackPBFBatch($track),
        ];

        return array_values(array_filter(
            $jobs,
            fn ($job) => ! in_array($job::class, $except, true)
        ));
    }

    /**
     * I job che portano la traccia ad app, mappa e relazioni: la coda di ogni catena di update.
     *
     * @return array<int, object>
     */
    public function publicationJobs(EcTrack $track): array
    {
        return [
            new UpdateEcTrackAwsJob($track),
            new UpdateEcTrackAppRelationsInfoJob($track),
            new UpdateEcTrackOrderRelatedPoi($track),
        ];
    }

    /**
     * Coppie di dati che dipendono dal verso di percorrenza (oc:8543).
     * chiave => [contenitore, primo campo, secondo campo, etichetta primo, etichetta secondo];
     * contenitore `manual_data` = properties.manual_data, `null` = primo livello di properties.
     * distance, ele_min ed ele_max non dipendono dal verso e non compaiono.
     */
    public const REVERSE_SWAP_PAIRS = [
        'ascent_descent' => ['manual_data', 'ascent', 'descent', 'Ascent', 'Descent'],
        'ele_from_ele_to' => ['manual_data', 'ele_from', 'ele_to', 'Starting Point Elevation', 'Ending Point Elevation'],
        'duration_forward_duration_backward' => ['manual_data', 'duration_forward', 'duration_backward', 'Duration Forward', 'Duration Backward'],
        'from_to' => [null, 'from', 'to', 'Departure', 'Arrival'],
    ];

    /**
     * Job del blocco geometria che l'inversione non accoda (oc:8543):
     * - UpdateEcTrackManualDataJob: i manuali li decide l'utente con gli scambi; il job li
     *   ricalcolerebbe dal primo livello di properties, sovrascrivendo lo scambio (oc:8642);
     * - UpdateEcTrackCurrentDataJob: in coda non fa nulla (getDirty() è vuoto su un modello
     *   riletto dal DB, e la riga di getDemDataFields() va comunque in errore);
     * - UpdateEcTrack3DDemJob: la quota di ogni punto non cambia invertendo il verso;
     * - SyncModelTaxonomyWhereJob: dipende dalla forma della traccia, non dal verso.
     */
    public const REVERSE_EXCLUDED_JOBS = [
        UpdateEcTrackManualDataJob::class,
        UpdateEcTrackCurrentDataJob::class,
        UpdateEcTrack3DDemJob::class,
        SyncModelTaxonomyWhereJob::class,
    ];

    /**
     * Le tracce OSM sono in sola lettura per l'inversione: il verso si corregge su OSM, e al primo
     * salvataggio UpdateEcTrackFromOsmJob riscriverebbe comunque la geometria (oc:8543).
     */
    public function isOsmTrack(EcTrack $track): bool
    {
        return $track->osmid !== null || ! empty($track->properties['osmid'] ?? null);
    }

    /**
     * Le coppie che dipendono dal verso e hanno almeno un valore sulla traccia.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function directionPairs(EcTrack $track): array
    {
        $properties = $this->decodeArray($track->properties);
        $pairs = [];
        foreach (self::REVERSE_SWAP_PAIRS as $key => [$container, $first, $second]) {
            $bag = $container === null ? $properties : $this->decodeArray($properties[$container] ?? null);
            $firstValue = $this->presentValue($bag[$first] ?? null);
            $secondValue = $this->presentValue($bag[$second] ?? null);
            if ($firstValue !== null || $secondValue !== null) {
                $pairs[$key] = [$firstValue, $secondValue];
            }
        }

        return $pairs;
    }

    /**
     * Inverte il verso di una traccia: la geometria se richiesto, e le sole coppie scelte.
     * Richiamabile da Nova, artisan o API. Dopo il commit accoda la catena dedicata e
     * reindicizza la traccia (oc:8543).
     *
     * @param  array<int, string>  $swaps  chiavi di REVERSE_SWAP_PAIRS da scambiare
     * @return array<int, string> le chiavi effettivamente scambiate
     *
     * @throws InvalidArgumentException traccia OSM, oppure nessuna operazione scelta
     */
    public function reverse(EcTrack $track, bool $geometry, array $swaps): array
    {
        if ($this->isOsmTrack($track)) {
            throw new InvalidArgumentException("Track {$track->id} comes from OpenStreetMap: correct its direction there.");
        }

        $swaps = array_values(array_intersect(array_keys(self::REVERSE_SWAP_PAIRS), $swaps));
        if (! $geometry && $swaps === []) {
            throw new InvalidArgumentException('Nothing to do: reverse the geometry or swap at least one pair.');
        }

        $swapped = DB::transaction(function () use ($track, $geometry, $swaps) {
            if ($geometry) {
                $this->geometryComputationService->reverseGeometry($track);
            }

            $swapped = $this->swapPairs($track, $swaps);

            // updated_at si aggiorna a mano: le scritture sono SQL mirato, e app ed export
            // incrementali scelgono le tracce da riscaricare proprio con questa data.
            if ($geometry || $swapped !== []) {
                DB::table($track->getTable())->where('id', $track->id)->update(['updated_at' => now()]);
            }

            return $swapped;
        });

        if (! $geometry && $swapped === []) {
            return [];
        }

        $jobs = $geometry
            ? [...$this->geometryDependentJobs($track, self::REVERSE_EXCLUDED_JOBS), ...$this->publicationJobs($track)]
            // Solo scambi: bastano le tile (duration_forward da manual_data) e il JSON su AWS
            // (EcTrackResource passa da classifyField()); verificato il 24/09.
            : [new GenerateEcTrackPBFBatch($track), new UpdateEcTrackAwsJob($track)];

        // Prima la catena, poi l'indice: un errore di Elasticsearch non deve impedire il
        // ricalcolo né arrivare a Nova come errore su un'inversione già scritta.
        DB::afterCommit(function () use ($track, $jobs) {
            Bus::chain($jobs)->dispatch();
            $this->reindexForSearch($track);
        });

        return $swapped;
    }

    /**
     * Scambia le coppie scelte scrivendo la sola colonna properties: niente save(), che farebbe
     * partire l'observer, e niente saveQuietly(), che farebbe transitare la geometria dall'ORM.
     *
     * @param  array<int, string>  $swaps
     * @return array<int, string>
     */
    protected function swapPairs(EcTrack $track, array $swaps): array
    {
        if ($swaps === []) {
            return [];
        }

        $properties = $this->decodeArray(
            DB::table($track->getTable())->where('id', $track->id)->lockForUpdate()->value('properties')
        );

        $swapped = [];
        foreach ($swaps as $key) {
            [$container, $first, $second] = self::REVERSE_SWAP_PAIRS[$key];
            $bag = $container === null ? $properties : $this->decodeArray($properties[$container] ?? null);
            $firstValue = $this->presentValue($bag[$first] ?? null);
            $secondValue = $this->presentValue($bag[$second] ?? null);
            if ($firstValue === null && $secondValue === null) {
                continue;
            }

            unset($bag[$first], $bag[$second]);
            if ($secondValue !== null) {
                $bag[$first] = $secondValue;
            }
            if ($firstValue !== null) {
                $bag[$second] = $firstValue;
            }

            if ($container === null) {
                $properties = $bag;
            } else {
                $properties[$container] = $bag === [] ? null : $bag;
            }
            $swapped[] = $key;
        }

        if ($swapped !== []) {
            DB::table($track->getTable())
                ->where('id', $track->id)
                ->update(['properties' => json_encode($properties)]);
        }

        return $swapped;
    }

    protected function reindexForSearch(EcTrack $track): void
    {
        try {
            // fresh(): l'update mirato ha scritto sul DB, non sul modello in memoria.
            $track->fresh()?->searchable();
        } catch (Throwable $e) {
            Log::error("Reindicizzazione della traccia {$track->id} dopo l'inversione fallita: {$e->getMessage()}");
        }
    }

    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }

    private function presentValue(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : $value;
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
            array_push($chain, ...$this->geometryDependentJobs($track));
        }

        array_push($chain, ...$this->publicationJobs($track));

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
            /** @var Layer $layer */
            $updates['layers'][$layer->app_id] = $layer->id;
            $updates['activities'][$layer->app_id] = $this->getTaxonomyArray($ecTrack->taxonomyActivities);
            $updates['searchable'][$layer->app_id] = $ecTrack->getSearchableString($layer->app_id);
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
            $validTrackIds = $app->ecTracks->pluck('id')->toArray();
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
            /** @var TaxonomyActivity $activity */
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
