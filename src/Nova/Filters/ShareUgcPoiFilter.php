<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\BooleanFilter;

class ShareUgcPoiFilter extends BooleanFilter
{
    public function name()
    {
        return __('Share Ugc Poi');
    }

    /**
     * Apply the filter to the given query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  mixed  $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Request $request, $query, $value)
    {
        if (! $value['yes']) {
            return $query;
        }
        if ($value['yes']) {
            return $query->whereRaw("properties->>'share_ugcpoi' = 'yes'");
        }
    }

    /**
     * Get the filter's available options.
     *
     * @return array
     */
    public function options(Request $request)
    {
        return [
            __('Yes') => 'yes',
        ];
    }
}
