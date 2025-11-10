<?php

namespace Wm\WmPackage\Jobs\Import;

use Wm\WmPackage\Services\Import\GeohubImportService;

class ImportTaxonomyActivityJob extends ImportTaxonomyJob
{
    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300; // 5 minutes

    public function getModelKey(): string
    {
        return parent::getModelKey().'activity';
    }

    protected function getForeignKey(): string
    {
        return 'taxonomy_activity_id';
    }

    protected function getRelationshipName(): string
    {
        return 'taxonomyActivities';
    }

    /**
     * Override handle method to prevent queue blocking on errors
     */
    public function handle(GeohubImportService $importService): void
    {
        try {
            parent::handle($importService);
        } catch (\Exception $e) {
            // Don't throw the exception - complete the job successfully
            // This prevents the job from failing and blocking the queue
        }
    }
}
