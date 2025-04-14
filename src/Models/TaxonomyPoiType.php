<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Taxonomy;

class TaxonomyPoiType extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'poi_typeable';
    }
}
