<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\ConservativeSyncTaxonomyWhereJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

/**
 * Riallineamento di properties.taxonomy_where alla forma vecchia (oc:8588).
 *
 * A differenza del job automatico di oc:8487, non azzera mai: calcola prima (SQL locale, poi
 * osmfeatures) e scrive solo se ha un risultato non vuoto, salvando il valore precedente in
 * properties._taxonomy_where_backup (una volta sola: le esecuzioni successive non lo toccano).
 */
class TaxonomyWhereResyncService extends BaseService
{
    public function __construct(
        private GeometryComputationService $geometry,
        private TaxonomyWhereDisplayService $display,
        private OsmfeaturesClient $osmfeatures,
    ) {}

    /**
     * Conta le taxonomy_wheres senza geometria, globalmente: la tabella non ha `app_id` (non e'
     * scopata per App), quindi non e' un dato specifico del riallineamento di una singola App.
     * Una where senza geometria non puo' intersecare nulla (`ST_Intersects` la esclude sempre),
     * quindi il sync SQL/il resync conservativo la ignorano silenziosamente: un numero alto qui
     * indica probabilmente un import ancora in corso (i job di dettaglio che scaricano la
     * geometria da osmfeatures sono ancora in coda), non un problema di questo comando (oc:8588).
     */
    public function countWheresMissingGeometry(): int
    {
        return (int) DB::table('taxonomy_wheres')->whereNull('geometry')->count();
    }

    /** @return array<string, class-string<GeometryModel>> */
    public function modelClasses(): array
    {
        $trackModel = config('wm-package.ec_track_model');

        $classes = [];
        foreach ([$trackModel, EcPoi::class, UgcPoi::class, UgcTrack::class] as $class) {
            $classes[(new $class)->getTable()] = $class;
        }

        return $classes;
    }

    public function resyncRecord(string $modelClass, int $id): bool
    {
        /** @var GeometryModel|null $model */
        $model = $modelClass::find($id);
        if ($model === null || $model->geometry === null) {
            return false;
        }

        $computed = $this->geometry->computeTaxonomyWhere($model);

        if ($computed === []) {
            $geojson = $model->getGeojson();
            if ($geojson !== null) {
                $computed = $this->display->fromOsmfeatures($this->osmfeatures->getWheresByGeojson($geojson));
            }
        }

        if ($computed === []) {
            Log::warning('oc:8588 taxonomy_where non riallineata: nessun risultato locale né da osmfeatures', [
                'table' => $model->getTable(),
                'id' => $id,
            ]);

            return false;
        }

        // '??' invece di '?': l'operatore jsonb "esiste chiave" va escapato, altrimenti il
        // driver PDO pgsql lo interpreta come segnaposto per i binding (stesso singolo '?'
        // usato più sotto per il valore e l'id).
        $table = $model->getTable();
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        DB::statement("
            UPDATE {$table}
            SET properties = jsonb_set(
                CASE
                    WHEN COALESCE(properties, '{}'::jsonb) ?? '_taxonomy_where_backup' THEN COALESCE(properties, '{}'::jsonb)
                    ELSE jsonb_set(COALESCE(properties, '{}'::jsonb), '{_taxonomy_where_backup}', COALESCE(properties->'taxonomy_where', 'null'::jsonb))
                END,
                '{taxonomy_where}',
                ?::jsonb
            )
            WHERE id = ?
        ", [json_encode((object) $computed), $id]);

        return true;
    }

    /** @return array<string, array<string, int>> */
    public function dryRunReport(int $appId): array
    {
        $selected = $this->display->selectedCategoriesForApp($appId);
        $report = [];

        foreach ($this->modelClasses() as $table => $class) {
            $counts = ['empty' => 0, 'oldest' => 0, 'legacy' => 0, 'oc8487' => 0, 'without_selected_categories' => 0];

            DB::table($table)->where('app_id', $appId)->whereNotNull('geometry')->select(['id', 'properties'])
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$counts, $selected) {
                    foreach ($rows as $row) {
                        $where = (json_decode($row->properties ?? '{}', true) ?: [])['taxonomy_where'] ?? null;
                        $counts[$this->display->classifyFormat($where)]++;
                        if ($selected !== [] && $this->display->filter(is_array($where) ? $where : [], $selected) === []) {
                            $counts['without_selected_categories']++;
                        }
                    }
                });

            $report[$table] = $counts;
        }

        return $report;
    }

    /**
     * @param  string  $queue  Coda su cui accodare il batch e il job di rigenerazione uscite
     *                         finale: deve essere letta da un worker Horizon (default 'default',
     *                         la sola coda garantita monitorata su ogni consumer — oc:8588).
     */
    public function dispatch(int $appId, bool $onlyLegacy, string $queue = 'default'): int
    {
        $jobs = [];

        foreach ($this->modelClasses() as $table => $class) {
            DB::table($table)->where('app_id', $appId)->whereNotNull('geometry')->select(['id', 'properties'])
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$jobs, $class, $onlyLegacy) {
                    foreach ($rows as $row) {
                        $where = (json_decode($row->properties ?? '{}', true) ?: [])['taxonomy_where'] ?? null;
                        if ($onlyLegacy && $this->display->classifyFormat($where) === 'legacy') {
                            continue;
                        }
                        $jobs[] = new ConservativeSyncTaxonomyWhereJob($class, (int) $row->id);
                    }
                });
        }

        if ($jobs === []) {
            RegenerateTaxonomyWhereOutputsJob::dispatch($appId)->onQueue($queue);

            return 0;
        }

        Bus::batch($jobs)
            ->name("oc8588-resync-taxonomy-where-app-{$appId}")
            ->onQueue($queue)
            ->allowFailures()
            ->finally(fn () => RegenerateTaxonomyWhereOutputsJob::dispatch($appId)->onQueue($queue))
            ->dispatch();

        return count($jobs);
    }
}
