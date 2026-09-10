<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * La provenienza non e' un enum: e' una stringa libera scritta da chi deposita
 * l'istanza, quindi le opzioni sono i valori distinti gia' presenti.
 */
class TrailApplicationSourceFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Provenienza';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('source', $value);
    }

    public function options(NovaRequest $request): array
    {
        return TrailApplication::query()
            ->select('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->filter()
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }
}
