<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class FeaturesExcludeByIds extends LayerFeatureFilter
{
    /**
     * Apply the filter to the given query.
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->whereNotIn('id', $value);
    }

    /**
     * Get the filter's available options.
     *
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return []; // To maximize performance
    }

    /**
     * Get the key for the filter.
     */
    public function key(): string
    {
        return 'features_exclude_ids_'.parent::key();
    }
}
