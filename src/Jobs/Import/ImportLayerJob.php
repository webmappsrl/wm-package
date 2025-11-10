<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;

class ImportLayerJob extends BaseImportJob
{
    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600; // 10 minuti per i layer (incluso download media)

    protected function getModelKey(): string
    {
        return 'layer';
    }

    protected function processDependencies(array $data, Model $model): void
    {
        // process taxonomy_activityable relationship
        $this->geohubImportService->associateLayersWithTaxonomy('taxonomy_activity', $model);
        // process ec_track relationship by finding if the ec track has relation with the same taxonomy_theme as the layer
        $this->geohubImportService->associateLayersWithEcTrack('taxonomy_theme', $model);
        // handle overlay_layers relationship
        $this->geohubImportService->handleOverlayLayers($model);
        // get feature images from taxonomies if necessary
        $this->geohubImportService->associateLayersWithMedia($model);
    }
}
