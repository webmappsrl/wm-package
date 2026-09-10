<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;

/**
 * Le opzioni vengono dai casi dell'enum, non dalla tabella: un tipo che oggi
 * non ha righe (i codici illeggibili, le tracce fuori da ogni settore) resta
 * una scelta legittima — e quando capitera' sara' gia' filtrabile.
 */
class TrailAnomalyTypeFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Tipo';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('type', $value);
    }

    public function options(NovaRequest $request): array
    {
        return collect(TrailRegistryAnomalyType::cases())
            ->mapWithKeys(fn (TrailRegistryAnomalyType $type) => [$type->value => $type->value])
            ->all();
    }
}
