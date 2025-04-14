<?php

namespace Wm\WmPackage\Jobs\Import;

class ImportEcPoiJob extends BaseEcImportJob
{
    protected function getModelKey(): string
    {
        return parent::getModelKey().'poi';
    }

    protected function getGeometryType(): string
    {
        return 'POINT Z';
    }
}
