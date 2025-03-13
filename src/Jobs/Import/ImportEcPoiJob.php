<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;

class ImportEcPoiJob extends BaseImportJob
{
    protected function getModelKey(): string
    {
        return 'ec_poi';
    }

    protected function processDependencies(array $data, Model $model): void
    {
        // no dependencies handled in ec poi job
    }
}
