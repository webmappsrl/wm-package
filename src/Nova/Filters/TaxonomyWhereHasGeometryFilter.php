<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class TaxonomyWhereHasGeometryFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Geometria');
    }

    public function apply(Request $request, $query, $value)
    {
        return $value === 'yes'
            ? $query->whereNotNull('geometry')
            : $query->whereNull('geometry');
    }

    public function options(Request $request): array
    {
        return [
            __('Presente') => 'yes',
            __('Assente') => 'no',
        ];
    }
}
