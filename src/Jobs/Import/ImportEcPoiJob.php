<?php

namespace Wm\WmPackage\Jobs\Import;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;

class ImportEcPoiJob extends BaseImportJob
{

    protected function getModelKey(): string
    {
        return 'ec_poi';
    }


    protected function transformData(array $data): array
    {
        $transformedData = $this->geohubImportService->transformFields($data, $this->getModelKey());
        $transformedData['properties'] = $this->geohubImportService->transformProperties($data, $this->getModelKey());
        $transformedData['app_id'] = $this->data['app_id'];

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        // no dependencies handled in ec poi job
    }
}
