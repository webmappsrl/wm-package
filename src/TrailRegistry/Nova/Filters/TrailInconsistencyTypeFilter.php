<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Inconsistencies\TrailRegistryInconsistencyTypes;

/**
 * I tipi di incongruenza catastale, che sono un registro a parte da quello
 * delle anomalie: le due famiglie vivono nella stessa tabella ma si lavorano
 * in modo opposto — un'anomalia si corregge alla fonte, un'incongruenza qui
 * dentro (oc:8989).
 *
 * Le opzioni vengono dal registro, non dalla tabella: un tipo che oggi non ha
 * righe resta una scelta legittima, e quando capitera' sara' gia' filtrabile.
 */
class TrailInconsistencyTypeFilter extends Filter
{
    public $component = 'select-filter';

    public $name = 'Tipo';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('type', $value);
    }

    /**
     * Le opzioni di Nova sono indicizzate per etichetta: due tipi con la
     * stessa etichetta collasserebbero in una voce sola, e uno dei due non
     * sarebbe piu' filtrabile. In quel caso l'etichetta porta la chiave fra
     * parentesi.
     *
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        $labels = collect(TrailRegistryInconsistencyTypes::values())
            ->mapWithKeys(fn (string $type) => [$type => TrailRegistryInconsistencyTypes::label($type)]);

        $counts = $labels->countBy();

        return $labels
            ->mapWithKeys(fn (string $label, string $type) => [
                ($counts[$label] > 1 ? "{$label} ({$type})" : $label) => $type,
            ])
            ->all();
    }
}
