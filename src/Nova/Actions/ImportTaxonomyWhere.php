<?php

namespace Wm\WmPackage\Nova\Actions;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Http\Clients\Osm2caiClient;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchOsm2caiSectorGeometryJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Actions\Concerns\HasTaxonomyWhereImportHelpers;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ImportTaxonomyWhere extends Action
{
    use HasTaxonomyWhereImportHelpers, InteractsWithQueue, Queueable;

    public $standalone = true;

    public function name(): string
    {
        return __('Import TaxonomyWhere');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $sourceType = $fields->get('source_type');

        if (str_starts_with((string) $sourceType, 'osmfeatures_')) {
            return $this->handleOsmfeatures($fields, (string) $sourceType);
        }

        if ($sourceType === 'osm2cai') {
            return $this->handleOsm2cai($fields);
        }

        if ($sourceType === 'geohub') {
            return $this->handleGeohub($fields);
        }

        return Action::danger('Sorgente non valida.');
    }

    private function handleOsmfeatures(ActionFields $fields, string $sourceType): mixed
    {
        $adminLevel = (int) str_replace('osmfeatures_', '', $sourceType);

        $app = $this->resolveApp($fields);
        if (is_string($app)) {
            return Action::danger($app);
        }

        $bbox = $app->map_bbox;
        if (empty($bbox)) {
            $computed = GeometryComputationService::make()->getEcTracksBboxByAppId($app->id);
            if ($computed !== null) {
                $bbox = json_encode($computed);
            }
        }

        if (empty($bbox)) {
            return Action::danger('App senza bbox utilizzabile (map_bbox vuoto e nessuna geometria dalle track).');
        }

        $client = app(OsmfeaturesClient::class);
        try {
            $items = $client->getAdminAreasIds($bbox, $adminLevel);
        } catch (Exception $e) {
            return Action::danger('Errore OSMFeatures: '.$e->getMessage());
        }

        if (count($items) === 0) {
            return Action::danger('OSMFeatures ha restituito 0 aree (verifica bbox e admin level).');
        }

        $count = 0;
        $skipped = 0;
        $skippedCollision = 0;

        foreach ($items as $item) {
            $apiUpdatedAt = isset($item['updated_at']) ? Carbon::parse($item['updated_at']) : null;

            $existing = TaxonomyWhere::whereRaw("properties->>'osmfeatures_id' = ?", [$item['id']])->first();

            if ($existing && $apiUpdatedAt) {
                $storedUpdatedAt = isset($existing->properties['source_updated_at'])
                    ? Carbon::parse($existing->properties['source_updated_at'])
                    : null;

                if ($storedUpdatedAt && $storedUpdatedAt->gte($apiUpdatedAt)) {
                    $skipped++;

                    continue;
                }
            }

            $properties = [
                'osmfeatures_id' => $item['id'],
                'admin_level' => $adminLevel,
                'source' => 'osmfeatures',
                'source_updated_at' => $apiUpdatedAt?->toIso8601String(),
            ];

            try {
                if ($existing) {
                    $existing->update([
                        'name' => $item['name'] ?? $item['id'],
                        'properties' => array_merge($existing->properties ?? [], $properties),
                    ]);
                    $this->assignTaxonomyUserFromApp($existing, $app);
                    FetchTaxonomyWhereGeometryJob::dispatch($existing->id);
                } else {
                    $taxonomyWhere = TaxonomyWhere::create([
                        'name' => $item['name'] ?? $item['id'],
                        'properties' => $properties,
                    ]);
                    $this->assignTaxonomyUserFromApp($taxonomyWhere, $app);
                    FetchTaxonomyWhereGeometryJob::dispatch($taxonomyWhere->id);
                }
            } catch (ValidationException|QueryException $e) {
                // Identifier gia' presente: si salta il singolo record invece di
                // interrompere l'intero import.
                $skippedCollision++;

                continue;
            }

            $count++;
        }

        $msg = "Creati/aggiornati {$count} record TaxonomyWhere. Geometrie in download in background.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} già aggiornati, saltati)";
        }
        $msg = $this->finalizeWithTracksSync($msg);

        if ($skippedCollision > 0) {
            $msg .= ' '.__(':count records skipped: identifier already in use.', [
                'count' => $skippedCollision,
            ]);
        }

        return Action::message($msg);
    }

    private function handleOsm2cai(ActionFields $fields): mixed
    {
        $app = $this->resolveApp($fields);
        if (is_string($app)) {
            return Action::danger($app);
        }

        $bbox = $app->map_bbox;
        if (empty($bbox)) {
            $computed = GeometryComputationService::make()->getEcTracksBboxByAppId($app->id);
            if ($computed !== null) {
                $bbox = json_encode($computed);
            }
        }

        if (empty($bbox)) {
            return Action::danger('App senza bbox utilizzabile (map_bbox vuoto e nessuna geometria dalle track).');
        }

        $client = app(Osm2caiClient::class);

        try {
            $sectors = $client->getSectorsList($bbox);
        } catch (Exception $e) {
            return Action::danger('Errore OSM2CAI: '.$e->getMessage());
        }

        if (count($sectors) === 0) {
            return Action::danger('OSM2CAI ha restituito 0 settori.');
        }

        $count = 0;
        $skipped = 0;
        $skippedCollision = 0;

        foreach ($sectors as $sector) {
            $apiUpdatedAt = isset($sector['updated_at']) ? Carbon::parse($sector['updated_at']) : null;

            $existing = TaxonomyWhere::whereRaw("(properties->>'osm2cai_id')::int = ?", [$sector['id']])->first();

            if ($existing && $apiUpdatedAt) {
                $storedUpdatedAt = isset($existing->properties['source_updated_at'])
                    ? Carbon::parse($existing->properties['source_updated_at'])
                    : null;

                if ($storedUpdatedAt && $storedUpdatedAt->gte($apiUpdatedAt)) {
                    $skipped++;

                    continue;
                }
            }

            $properties = [
                'osm2cai_id' => $sector['id'],
                'source' => 'osm2cai',
                'source_updated_at' => $apiUpdatedAt?->toIso8601String(),
            ];

            try {
                if ($existing) {
                    $existing->update([
                        'name' => $sector['name'],
                        'properties' => array_merge($existing->properties ?? [], $properties),
                    ]);
                    $this->assignTaxonomyUserFromApp($existing, $app);
                    FetchOsm2caiSectorGeometryJob::dispatch($existing->id);
                } else {
                    $created = TaxonomyWhere::create([
                        'name' => $sector['name'],
                        'properties' => $properties,
                    ]);
                    $this->assignTaxonomyUserFromApp($created, $app);
                    FetchOsm2caiSectorGeometryJob::dispatch($created->id);
                }
            } catch (ValidationException|QueryException $e) {
                // Identifier gia' presente: si salta il singolo record invece di
                // interrompere l'intero import.
                $skippedCollision++;

                continue;
            }

            $count++;
        }

        $msg = "Importati {$count} settori OSM2CAI.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} già aggiornati, saltati)";
        }
        $msg = $this->finalizeWithTracksSync($msg);

        if ($skippedCollision > 0) {
            $msg .= ' '.__(':count records skipped: identifier already in use.', [
                'count' => $skippedCollision,
            ]);
        }

        return Action::message($msg);
    }

    private function handleGeohub(ActionFields $fields): mixed
    {
        if (! RolesAndPermissionsService::allowsUser(auth()->user())) {
            return Action::danger('Sorgente GeoHub riservata ai super-admin.');
        }

        $app = $this->resolveApp($fields);
        if (is_string($app)) {
            return Action::danger($app);
        }

        if (empty($app->geohub_id)) {
            return Action::danger('App non collegata a GeoHub (geohub_id assente).');
        }

        $geohubApp = DB::connection('geohub')->table('apps')->where('id', $app->geohub_id)->first();
        if (! $geohubApp || empty($geohubApp->user_id)) {
            return Action::danger('Impossibile risolvere lo user GeoHub per questa App.');
        }

        $rows = DB::connection('geohub')->select(<<<'SQL'
            select distinct tw.id, tw.name, tw.identifier
            from taxonomy_wheres tw
            join taxonomy_whereables twa on tw.id = twa.taxonomy_where_id
            where tw.admin_level is null
              and (
                (twa.taxonomy_whereable_type like '%EcPoi%' and twa.taxonomy_whereable_id in (select id from ec_pois where user_id = ?))
                or (twa.taxonomy_whereable_type like '%EcTrack%' and twa.taxonomy_whereable_id in (select id from ec_tracks where user_id = ?))
                or (twa.taxonomy_whereable_type like '%Layer%' and twa.taxonomy_whereable_id in (select id from layers where app_id = ?))
              )
            SQL, [$geohubApp->user_id, $geohubApp->user_id, $app->geohub_id]);

        if (count($rows) === 0) {
            return Action::danger('GeoHub non ha restituito where senza admin_level per questa App.');
        }

        $created = 0;
        $updated = 0;
        $geometryJobs = [];

        foreach ($rows as $row) {
            $name = is_string($row->name) ? (json_decode($row->name, true) ?? $row->name) : $row->name;

            $existing = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $row->id])->first();

            if (! $existing && $row->identifier) {
                $existing = TaxonomyWhere::where('identifier', $row->identifier)->first();
            }

            $properties = [
                'geohub_id' => $row->id,
                'source' => 'geohub',
                'admin_level' => null,
            ];

            if ($existing) {
                $existing->update([
                    'name' => $name,
                    'properties' => array_merge($existing->properties ?? [], $properties),
                ]);
                $this->assignTaxonomyUserFromApp($existing, $app);
                $geometryJobs[] = new CopyTaxonomyWhereGeometryFromGeohubJob($existing->id, $row->id);
                $updated++;

                continue;
            }

            $taxonomyWhere = new TaxonomyWhere([
                'name' => $name,
                'properties' => $properties,
            ]);
            $taxonomyWhere->identifier = $row->identifier
                ? $taxonomyWhere->withCollisionCounter($row->identifier)
                : null;
            $taxonomyWhere->save();

            $this->assignTaxonomyUserFromApp($taxonomyWhere, $app);
            $geometryJobs[] = new CopyTaxonomyWhereGeometryFromGeohubJob($taxonomyWhere->id, $row->id);
            $created++;
        }

        // syncTracksTaxonomyWhere() calcola le intersezioni via ST_Intersects
        // sulle geometrie locali: se girasse subito dopo il dispatch (come per
        // le altre due sorgenti), troverebbe le geometrie ancora vuote, perche'
        // CopyTaxonomyWhereGeometryFromGeohubJob e' asincrono. Va accodato come
        // callback di un batch, cosi' parte solo a copia geometrie completata.
        Bus::batch($geometryJobs)
            ->then(function () {
                SyncTaxonomyWhereTracksJob::dispatch();
            })
            ->dispatch();

        $msg = "Creati {$created} record, aggiornati {$updated} record TaxonomyWhere da GeoHub. Geometrie in copia in background; la sincronizzazione delle track partira' automaticamente al termine.";

        return Action::message($msg);
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [];

        $fields[] = Select::make('Sorgente', 'source_type')
            ->options([
                'osmfeatures_4' => 'OSMFeatures — Regione (L4)',
                'osmfeatures_6' => 'OSMFeatures — Provincia (L6)',
                'osmfeatures_8' => 'OSMFeatures — Comune (L8)',
                'osmfeatures_9' => 'OSMFeatures — Municipio (L9)',
                'osmfeatures_10' => 'OSMFeatures — Quartiere (L10)',
                'osm2cai' => 'OSM2CAI — Settori CAI',
                'geohub' => __('GeoHub — Where senza admin_level'),
            ])
            ->rules('required');

        $apps = App::all();
        if ($apps->count() > 1) {
            $appOptions = $apps->pluck('name', 'id')->toArray();

            $fields[] = Select::make('App', 'app_id')
                ->options($appOptions)
                ->rules('required');
        }

        return $fields;
    }
}
