<?php

namespace Wm\WmPackage\Nova;

class TaxonomyPoiType extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyPoiType::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';
}
