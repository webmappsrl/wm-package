<?php

namespace Wm\WmPackage\Jobs\Import;

class ImportTaxonomyActivityJob extends ImportTaxonomyJob
{
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
        return 'taxonomyActivity';
    }
}
