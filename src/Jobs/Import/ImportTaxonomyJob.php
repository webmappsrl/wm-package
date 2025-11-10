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
        $recordsToImport = $this->geohubImportService->getTaxonomyMorphableRecords($this->getModelKey(), $model->id);

        if ($recordsToImport->isEmpty()) {
            \Log::debug("No records to import for taxonomy model: {$model->id}");
            return;
        }

        \Log::info("Processing {$recordsToImport->count()} records for taxonomy model: {$model->id}");

        // Prepare sync data for batch operation
        $syncData = [];
        foreach ($recordsToImport as $record) {
            $pivotData = $record->pivot_data ?? [];
            $syncData[$record->id] = $pivotData;
        }

        // Use sync for efficient batch operation
        foreach ($recordsToImport as $record) {
            $record->{$this->getRelationshipName()}()->sync([$model->id => $record->pivot_data ?? []]);
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
