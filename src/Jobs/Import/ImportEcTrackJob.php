<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;

class ImportEcTrackJob extends BaseEcImportJob
{
    protected function getModelKey(): string
    {
        return parent::getModelKey() . 'track';
    }

    protected function getGeometryType(): string
    {
        return 'MULTILINESTRING Z';
    }

    protected function processDependencies(array $data, Model $model): void
    {
        $ecPoiIdsWithOrder = $this->geohubImportService->getAssociatedEcPoisIDs($this->getModelKey(), $data['id']);

        $syncData = [];
        foreach ($ecPoiIdsWithOrder as $poiId => $order) {
            $syncData[$poiId] = ['order' => $order];
        }

        $model->ecPois()->sync($syncData);

        // Sincronizza le tassonomie dalle proprietà JSON
        $this->syncTaxonomiesFromProperties($model);
    }

    /**
     * Sincronizza le tassonomie dalle proprietà JSON alle relazioni del database
     */
    private function syncTaxonomiesFromProperties(Model $model): void
    {
        \Log::info("🔄 SYNC TAXONOMIES - Starting sync for track ID: {$model->id}");

        $activities = json_decode($model->properties['activities'] ?? '{}', true);

        if (empty($activities)) {
            \Log::info("🔄 SYNC TAXONOMIES - No activities found for track ID: {$model->id}");
            return;
        }

        \Log::info("🔄 SYNC TAXONOMIES - Found activities for track ID: {$model->id}", ['activities' => $activities]);

        foreach ($activities as $geohubId => $activityTypes) {
            if (!is_array($activityTypes)) {
                continue;
            }

            foreach ($activityTypes as $activityType) {
                \Log::info("🔄 SYNC TAXONOMIES - Processing activity type: {$activityType} for track ID: {$model->id}");

                // Trova la tassonomia per tipo di attività in modo dinamico
                $taxonomy = $this->findTaxonomyByActivityType($activityType);

                if (!$taxonomy) {
                    \Log::warning("🔄 SYNC TAXONOMIES - Taxonomy not found for activity type: {$activityType}");
                    continue;
                }

                \Log::info("🔄 SYNC TAXONOMIES - Found taxonomy ID: {$taxonomy->id} for activity: {$activityType}");

                // Verifica se la relazione esiste già
                $existingRelation = $model->taxonomyActivities()
                    ->where('taxonomy_activity_id', $taxonomy->id)
                    ->exists();

                if (!$existingRelation) {
                    // Crea la relazione
                    \Log::info("🔄 SYNC TAXONOMIES - Creating relation for track ID: {$model->id} with taxonomy ID: {$taxonomy->id}");
                    $model->taxonomyActivities()->attach($taxonomy->id, [
                        'duration_forward' => 0,
                        'duration_backward' => 0,
                    ]);
                    \Log::info("🔄 SYNC TAXONOMIES - Relation created successfully for track ID: {$model->id}");
                } else {
                    \Log::info("🔄 SYNC TAXONOMIES - Relation already exists for track ID: {$model->id} with taxonomy ID: {$taxonomy->id}");
                }
            }
        }

        \Log::info("🔄 SYNC TAXONOMIES - Completed sync for track ID: {$model->id}");
    }

    /**
     * Trova la tassonomia per tipo di attività in modo dinamico
     */
    private function findTaxonomyByActivityType(string $activityType): ?\Wm\WmPackage\Models\TaxonomyActivity
    {
        try {

            // Cerca la tassonomia per nome in modo dinamico
            $taxonomy = \Wm\WmPackage\Models\TaxonomyActivity::where('name', 'like', '%' . $activityType . '%')
                ->orWhere('name', 'like', '%' . ucfirst($activityType) . '%')
                ->orWhere('name', 'like', '%' . strtoupper($activityType) . '%')
                ->first();

            if (!$taxonomy) {
                // Se non trova per nome, cerca per geohub_id se disponibile
                $taxonomy = \Wm\WmPackage\Models\TaxonomyActivity::where('geohub_id', $activityType)->first();
            }

            return $taxonomy;
        } catch (\Exception $e) {
            \Log::error("Error finding taxonomy by activity type: {$e->getMessage()}");
            return null;
        }
    }
}
