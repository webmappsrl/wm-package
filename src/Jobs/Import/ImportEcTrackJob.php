<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\Import\BaseEcImportJob;

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
    }
}
