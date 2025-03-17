<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\Import\BaseImportJob;

class ImportTaxonomyActivityJob extends BaseImportJob
{
    public function getModelKey(): string
    {
        return 'taxonomy_activity';
    }

    protected function processDependencies(array $transformedData, Model $model): void
    {
        $recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $model->id);

        foreach ($recordsToImport as $record) {
            $record->taxonomyActivity()->sync($model->id);
        }
    }
}
