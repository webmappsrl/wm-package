<?php

namespace Wm\WmPackage\Nova\Actions;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Http\Clients\Osm2caiClient;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchOsm2caiSectorGeometryJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

class ImportTaxonomyWhere extends Action
{
    use InteractsWithQueue, Queueable;

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

        return Action::danger('Sorgente non valida.');
    }

    private function handleOsmfeatures(ActionFields $fields, string $sourceType): mixed
    {
        $adminLevel = (int) str_replace('osmfeatures_', '', $sourceType);

        $apps = App::all();
        if ($apps->count() === 1) {
            $app = $apps->first();
        } else {
            $appId = $fields->get('app_id');
            if (! $appId) {
                return Action::danger("Seleziona un'App.");
            }
            $app = App::find($appId);
            if (! $app) {
                return Action::danger('App non trovata.');
            }
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
            return Action::danger('Errore OSMFeatures: ' . $e->getMessage());
        }

        if (count($items) === 0) {
            return Action::danger('OSMFeatures ha restituito 0 aree (verifica bbox e admin level).');
        }

        $count   = 0;
        $skipped = 0;

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
                'osmfeatures_id'   => $item['id'],
                'admin_level'      => $adminLevel,
                'source'           => 'osmfeatures',
                'source_updated_at' => $apiUpdatedAt?->toIso8601String(),
            ];

            if ($existing) {
                $existing->update([
                    'name'       => $item['name'],
                    'properties' => array_merge($existing->properties ?? [], $properties),
                ]);
                $this->assignTaxonomyUserFromApp($existing, $app);
                FetchTaxonomyWhereGeometryJob::dispatch($existing->id);
            } else {
                $taxonomyWhere = TaxonomyWhere::create([
                    'name'       => $item['name'],
                    'properties' => $properties,
                ]);
                $this->assignTaxonomyUserFromApp($taxonomyWhere, $app);
                FetchTaxonomyWhereGeometryJob::dispatch($taxonomyWhere->id);
            }

            $count++;
        }

        $msg = "Creati/aggiornati {$count} record TaxonomyWhere. Geometrie in download in background.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} già aggiornati, saltati)";
        }
        $tracksSynced = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );
        $msg .= " Sync taxonomy_where su {$tracksSynced} tracks avviata.";

        return Action::message($msg);
    }

    private function handleOsm2cai(ActionFields $fields): mixed
    {
        $apps = App::all();
        if ($apps->count() === 1) {
            $app = $apps->first();
        } else {
            $appId = $fields->get('app_id');
            if (! $appId) {
                return Action::danger("Seleziona un'App.");
            }
            $app = App::find($appId);
            if (! $app) {
                return Action::danger('App non trovata.');
            }
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
            return Action::danger('Errore OSM2CAI: ' . $e->getMessage());
        }

        if (count($sectors) === 0) {
            return Action::danger('OSM2CAI ha restituito 0 settori.');
        }

        $count   = 0;
        $skipped = 0;

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
                'osm2cai_id'       => $sector['id'],
                'source'           => 'osm2cai',
                'source_updated_at' => $apiUpdatedAt?->toIso8601String(),
            ];

            if ($existing) {
                $existing->update([
                    'name'       => $sector['name'],
                    'properties' => array_merge($existing->properties ?? [], $properties),
                ]);
                $this->assignTaxonomyUserFromApp($existing, $app);
                FetchOsm2caiSectorGeometryJob::dispatch($existing->id);
            } else {
                $created = TaxonomyWhere::create([
                    'name'       => $sector['name'],
                    'properties' => $properties,
                ]);
                $this->assignTaxonomyUserFromApp($created, $app);
                FetchOsm2caiSectorGeometryJob::dispatch($created->id);
            }

            $count++;
        }

        $msg = "Importati {$count} settori OSM2CAI.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} già aggiornati, saltati)";
        }
        $tracksSynced = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );
        $msg .= " Sync taxonomy_where su {$tracksSynced} tracks avviata.";

        return Action::message($msg);
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [];

        $fields[] = Select::make('Sorgente', 'source_type')
            ->options([
                'osmfeatures_4'  => 'OSMFeatures — Regione (L4)',
                'osmfeatures_6'  => 'OSMFeatures — Provincia (L6)',
                'osmfeatures_8'  => 'OSMFeatures — Comune (L8)',
                'osmfeatures_9'  => 'OSMFeatures — Municipio (L9)',
                'osmfeatures_10' => 'OSMFeatures — Quartiere (L10)',
                'osm2cai'        => 'OSM2CAI — Settori CAI',
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

    private function assignTaxonomyUserFromApp(TaxonomyWhere $taxonomyWhere, App $app): void
    {
        if (! Schema::hasColumn($taxonomyWhere->getTable(), 'user_id')) {
            return;
        }

        if (empty($app->user_id)) {
            return;
        }

        $taxonomyWhere->forceFill(['user_id' => $app->user_id])->saveQuietly();
    }
}
