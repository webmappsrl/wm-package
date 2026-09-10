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

    public function options(NovaRequest $request): array
    {
        return collect(TrailApplicationStatus::cases())
            ->mapWithKeys(fn (TrailApplicationStatus $status) => [$status->value => $status->value])
            ->all();
    }
}
