<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class TaxonomyWhereSourceFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Sorgente');
    }

    public function apply(Request $request, $query, $value)
    {
        return $query->whereRaw("properties->>'source' = ?", [$value]);
    }

    public function options(Request $request): array
    {
        return [
            'OSMFeatures' => 'osmfeatures',
            'OSM2CAI' => 'osm2cai',
        ];
    }
}
