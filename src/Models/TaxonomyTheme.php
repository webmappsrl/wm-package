<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Taxonomy;

class TaxonomyTheme extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'themeable';
    }
}
