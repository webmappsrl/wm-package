<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;

class FeaturesByLayerFilter extends LayerFeatureFilter
{
    /**
     * Apply the filter to the given query.
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->onLayer(Layer::find($value));
    }

    /**
     * Get the filter's available options.
     *
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return Layer::all()->pluck('id', 'name')->toArray();
    }

    /**
     * Get the key for the filter.
     */
    public function key(): string
    {
        return 'features_by_layer_'.parent::key();
    }
}
