<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;

class TrailApplicationStatusFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Stato istruttoria';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('status', $value);
    }

    /**
     * La chiave e' cio' che l'operatore legge, il valore e' cio' su cui si
     * filtra: la traduzione la da' l'enum, cosi' il prossimo stato la eredita
     * senza passare di qui (oc:8960).
     */
    public function options(NovaRequest $request): array
    {
        return collect(TrailApplicationStatus::cases())
            ->mapWithKeys(fn (TrailApplicationStatus $status) => [$status->label() => $status->value])
            ->all();
    }
}
