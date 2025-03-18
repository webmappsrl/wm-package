<?php

namespace Wm\WmPackage\Jobs\Import;

use Wm\WmPackage\Jobs\Import\ImportTaxonomyJob;
use Illuminate\Database\Eloquent\Model;


class ImportTaxonomyActivityJob extends ImportTaxonomyJob
{
    public function getModelKey(): string
    {
        return parent::getModelKey() . 'activity';
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
