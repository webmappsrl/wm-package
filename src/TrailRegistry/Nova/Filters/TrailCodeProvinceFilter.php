<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

/**
 * Le opzioni sono i valori distinti presenti in tabella: l'elenco completo dei
 * valori possibili per `province` non e' un dato del package, dipende dal catasto
 * del consumer.
 */
class TrailCodeProvinceFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Provincia';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('province', $value);
    }

    public function options(NovaRequest $request): array
    {
        return TrailRegistryCode::query()
            ->select('province')
            ->distinct()
            ->orderBy('province')
            ->pluck('province')
            ->filter()
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }
}
