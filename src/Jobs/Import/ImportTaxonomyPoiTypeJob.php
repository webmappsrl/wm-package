<?php

namespace Wm\WmPackage\Jobs\Import;

class ImportTaxonomyPoiTypeJob extends ImportTaxonomyJob
{
    public function getModelKey(): string
    {
        return parent::getModelKey().'poi_types';
    }

    protected function getForeignKey(): string
    {
        return 'taxonomy_poi_type_id';
    }

    protected function getRelationshipName(): string
    {
        return 'taxonomyPoiTypes';
    }
}
