<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;

abstract class ImportTaxonomyJob extends BaseImportJob
{
    public function getModelKey(): string
    {
        return 'taxonomy_';
    }

    protected function processDependencies(array $transformedData, Model $model): void
    {
        $recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $this->entityId);

        if ($recordsToImport->isEmpty()) {
            \Log::debug("No records to import for taxonomy model: {$model->id}");

            return;
        }

        \Log::info("Processing {$recordsToImport->count()} records for taxonomy model: {$model->id}");

        foreach ($recordsToImport as $record) {
            $record->{$this->getRelationshipName()}()->syncWithoutDetaching([$model->id => $record->pivot_data ?? []]);
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
