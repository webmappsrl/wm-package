<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\Import\BaseEcImportJob;

class ImportEcMediaJob extends BaseEcImportJob
{
    protected function getModelKey(): string
    {
        return parent::getModelKey() . 'media';
    }

    protected function getGeometryType(): string
    {
        return 'POINT Z';
    }

    protected function processDependencies(array $data, Model $model): void {}
}
