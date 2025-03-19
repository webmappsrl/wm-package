<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\Import\BaseImportJob;


class ImportLayerJob extends BaseImportJob
{

    protected function getModelKey(): string
    {
        return 'layer';
    }


    protected function processDependencies(array $data, Model $model): void
    {

        //process taxonomy_activityable relationship
        $this->geohubImportService->associateLayersWithTaxonomy('taxonomy_activity', $model);
        //process ec_track relationship by finding if the ec track has relation with the same taxonomy_theme as the layer
        $this->geohubImportService->associateLayersWithEcTrack('taxonomy_theme', $model);
        //handle overlay_layers relationship
        $this->geohubImportService->handleOverlayLayers($model);
    }
}
