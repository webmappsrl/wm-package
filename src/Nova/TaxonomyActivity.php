<?php

namespace Wm\WmPackage\Nova;

class TaxonomyActivity extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyActivity::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';
}
