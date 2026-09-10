<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

/**
 * Le opzioni sono i valori distinti presenti in tabella: l'elenco completo dei
 * valori possibili per `area` non e' un dato del package, dipende dal catasto
 * del consumer.
 */
class TrailCodeAreaFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Area';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('area', $value);
    }

    public function options(NovaRequest $request): array
    {
        return TrailRegistryCode::query()
            ->select('area')
            ->distinct()
            ->orderBy('area')
            ->pluck('area')
            ->filter()
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }
}
