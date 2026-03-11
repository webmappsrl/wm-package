<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class HasMediaFilter extends Filter
{
    /**
     * The filter's component.
     *
     * @var string
     */
    public $component = 'select-filter';

    public function __construct()
    {
        $this->name = __('Has Media');
    }

    /**
     * Apply the filter to the given query.
     *
     * @param  Builder  $query
     * @param  mixed  $value
     * @return Builder
     */
    public function apply(Request $request, $query, $value)
    {
        if ($value === 'true') {
            return $query->whereHas('media');
        }

        if ($value === 'false') {
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
