<?php

namespace Wm\WmPackage\Jobs\Import;

class ImportUgcPoiJob extends BaseUgcImportJob
{
    protected function getModelKey(): string
    {
        return 'ugc_poi';
    }
}
