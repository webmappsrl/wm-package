<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\BooleanFilter;

class HasMediaFilter extends BooleanFilter
{
    public function __construct()
    {
        $this->name = __('Has Media');
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
        $hasYes = !empty($value['true']);
        $hasNo  = !empty($value['false']);

        if ($hasYes && !$hasNo) {
            return $query->whereHas('media');
        }
    
        if ($hasNo && !$hasYes) {
            return $query->whereDoesntHave('media');
        }

        return $query;
    }

    /**
     * Get the filter's available options.
     *
     * @return array
     */
    public function options(Request $request)
    {
        return [
            __('Yes') => 'true',
            __('No') => 'false',
        ];
    }
}
