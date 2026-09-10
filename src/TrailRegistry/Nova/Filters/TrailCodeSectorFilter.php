<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

/**
 * Le opzioni sono i valori distinti presenti in tabella: l'elenco completo dei
 * valori possibili per `sector` non e' un dato del package, dipende dal catasto
 * del consumer.
 */
class TrailCodeSectorFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Settore';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('sector', $value);
    }

    public function options(NovaRequest $request): array
    {
        return TrailRegistryCode::query()
            ->select('sector')
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector')
            ->filter()
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }
}
