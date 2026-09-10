<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * A differenza degli altri tre filtri, le opzioni dello stato non si pescano
 * dalla tabella ma dai casi dell'enum: uno stato che non compare ancora in
 * nessuna riga resta comunque una scelta legittima.
 */
class TrailCodeStatusFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Stato';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('status', $value);
    }

    public function options(NovaRequest $request): array
    {
        return collect(TrailCodeStatus::cases())
            ->mapWithKeys(fn (TrailCodeStatus $status) => [$status->value => $status->value])
            ->all();
    }
}
