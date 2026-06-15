<?php

namespace Wm\WmPackage\Jobs\Import;

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
}
