<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;

class FeaturesByLayerFilter extends Filter
{
    /**
     * The filter's component.
     *
     * @var string
     */
    public $component = 'select-filter';

    public function __construct(
        protected string $featureClass
    ) {}

    /**
     * Apply the filter to the given query.
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->whereLayer(Layer::find($value));
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
        return 'features_by_layer_' . class_basename($this->featureClass);
    }
}
