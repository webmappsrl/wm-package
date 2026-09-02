<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Services\Import\GeohubImportService;

class ImportEcPoiJob extends BaseEcImportJob
{
    /**
     * Maps each taxonomy model key to the local Eloquent relation used to attach it.
     *
     * @var array<string, string>
     */
    private const TAXONOMY_RELATIONS = [
        'taxonomy_theme' => 'taxonomyThemes',
        'taxonomy_poi_types' => 'taxonomyPoiTypes',
        'taxonomy_activity' => 'taxonomyActivities',
    ];

    protected function getModelKey(): string
    {
        return parent::getModelKey().'poi';
    }

    protected function getGeometryType(): string
    {
        return 'POINT Z';
    }

    protected function processDependencies(array $data, Model $model): void
    {
        if (! config('wm-geohub-import.child_side_taxonomy_sync.enabled', true)) {
            return;
        }

        foreach (self::TAXONOMY_RELATIONS as $taxonomyModelKey => $relationName) {
            $this->syncTaxonomyFromGeohubPivot($model, $taxonomyModelKey, $relationName);
        }
    }

    /**
     * Sync one taxonomy type's pivot for this EcPoi by querying the Geohub pivot table
     * directly for this record's own geohub_id — eliminates the dispatch-order race with the
     * dedicated taxonomy import jobs (see oc:8094). Errors are logged and swallowed: a Geohub
     * network blip on this sync must not fail the whole EcPoi import job.
     */
    private function syncTaxonomyFromGeohubPivot(Model $model, string $taxonomyModelKey, string $relationName): void
    {
        $logger = $this->importLogger();

        try {
            // $model->properties['geohub_id'], non $this->entityId: stesso ID entro la stessa
            // handle() con il codice attuale, ma è l'ID autoritativo per query sul pivot Geohub
            // di un record già importato — convenzione stabilita in oc:8041 per lo stesso identico
            // pattern (getTaxonomyMorphableRecords()), dopo un bug reale legato a un possibile
            // disallineamento in scenari di re-import.
            $taxonomies = $this->geohubImportService->getTaxonomyRecordsForMorphable(
                $taxonomyModelKey,
                GeohubImportService::GEOHUB_MORPHABLE_TYPES['ec_poi'],
                (int) $model->properties['geohub_id']
            );

            foreach ($taxonomies as $taxonomy) {
                $model->{$relationName}()->syncWithoutDetaching([$taxonomy->id => $taxonomy->pivot_data ?? []]);
                $logger->info("[child_side_sync] Attached {$taxonomyModelKey} ID {$taxonomy->id} to EcPoi ID {$model->id}");
            }
        } catch (\Throwable $e) {
            $logger->error("[child_side_sync] Failed to sync {$taxonomyModelKey} for EcPoi ID {$model->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }
}
