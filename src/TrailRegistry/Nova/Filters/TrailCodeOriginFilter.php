<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeOrigin;

/**
 * Come lo stato: le opzioni si pescano dai casi dell'enum, non dalla tabella.
 * E' il primo posto dove Forestas vede a colpo d'occhio la differenza fra i
 * codici letti da un campo e quelli dedotti da un nome.
 */
class TrailCodeOriginFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Provenienza';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('origin', $value);
    }

    public function options(NovaRequest $request): array
    {
        return collect(TrailCodeOrigin::cases())
            ->mapWithKeys(fn (TrailCodeOrigin $origin) => [$origin->value => $origin->value])
            ->all();
    }
}
