<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Taxonomy;

class TaxonomyWhere extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'whereable';
    }
}
