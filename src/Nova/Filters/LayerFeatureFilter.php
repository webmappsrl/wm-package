<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;

abstract class LayerFeatureFilter extends Filter
{
    /**
     * The filter's component.
     *
     * @var string
     */
    public $component = 'select-filter';

    public function __construct(
        protected string $layerRelation
    ) {}

    /**
     * Apply the filter to the given query.
     */
    abstract public function apply(NovaRequest $request, Builder $query, mixed $value): Builder


    /**
     * Get the filter's available options.
     *
     * @return array<string, string>
     */
    abstract public function options(NovaRequest $request): array

    /**
     * Get the key for the filter.
     */
    public function key(): string
    {
        return $this->layerRelation;
    }
}
