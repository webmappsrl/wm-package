<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class FeaturesIncludeByIds extends LayerFeatureFilter
{
    /**
     * Apply the filter to the given query.
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->whereIn('id', $value);
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
        return 'features_include_ids_'.parent::key();
    }
}
