<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\BooleanFilter;

class GlobalEcPoiFilter extends BooleanFilter
{
    /**
     * The filter's displayable name.
     *
     * @var string
     */
    public function name()
    {
        return __('Global');
    }

    /**
     * Apply the filter to the given query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, bool>  $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Request $request, $query, $value)
    {
        if (! empty($value['global_true']) && $value['global_true']) {
            $query->where('global', true);
        }

        if (! empty($value['global_false']) && $value['global_false']) {
            $query->orWhere('global', false);
        }

        return $query;
    }

    /**
     * Get the filter's available options.
     *
     * @return array<string, string>
     */
    public function options(Request $request)
    {
        return [
            __('Global only') => 'global_true',
            __('Non global only') => 'global_false',
        ];
    }
}
