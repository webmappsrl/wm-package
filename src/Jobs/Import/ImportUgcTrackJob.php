<?php

namespace Wm\WmPackage\Jobs\Import;

class ImportUgcTrackJob extends BaseUgcImportJob
{
    protected function getModelKey(): string
    {
        return 'ugc_track';
    }
}
