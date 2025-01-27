<?php

namespace Wm\WmPackage\Models;


use Wm\WmPackage\Models\Abstracts\Taxonomy;


class TaxonomyTarget extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'targetable';
    }
}
