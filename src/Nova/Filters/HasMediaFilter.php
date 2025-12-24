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
        $hasYes = isset($value['true']) && $value['true'];
        $hasNo = isset($value['false']) && $value['false'];

        if (($hasYes && $hasNo) || (! $hasYes && ! $hasNo)) {
            return $query;
        }

        if ($hasYes) {
            return $query->whereHas('media');
        }

        if ($hasNo) {
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

