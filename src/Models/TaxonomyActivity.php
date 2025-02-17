<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Taxonomy;

class TaxonomyActivity extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'activityable';
    }
}
