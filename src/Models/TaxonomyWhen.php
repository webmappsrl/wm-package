<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Taxonomy;

class TaxonomyWhen extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'whenable';
    }
}
