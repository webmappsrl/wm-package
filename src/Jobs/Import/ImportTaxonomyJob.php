<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Jobs\Import\BaseImportJob;

abstract class ImportTaxonomyJob extends BaseImportJob
{
    public function getModelKey(): string
    {
        return 'taxonomy_';
    }

    protected function processDependencies(array $transformedData, Model $model): void
    {
        $recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $model->id);

        foreach ($recordsToImport as $record) {
            // Check if the relationship already exists before attaching
            if (!$record->{$this->getRelationshipName()}()->where($this->getForeignKey(), $model->id)->exists()) {
                $record->{$this->getRelationshipName()}()->attach($model->id, $record->pivot_data);
            } else {
                // Update pivot data if relationship exists
                $record->{$this->getRelationshipName()}()->updateExistingPivot($model->id, $record->pivot_data);
            }
        }
    }

    /**
     * Get the foreign key for the relationship.
     */
    abstract protected function getForeignKey(): string;

    /**
     * Get the relationship method name.
     */
    abstract protected function getRelationshipName(): string;
}
