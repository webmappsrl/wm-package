<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Anomalies\TrailRegistryAnomalyTypes;

/**
 * Le opzioni vengono dal registro dei tipi — i casi dell'enum del catasto
 * piu' quelli che uno shard dichiara — non dalla tabella: un tipo che oggi
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

    /**
     * Le opzioni di Nova sono indicizzate per etichetta: due tipi con la
     * stessa etichetta (uno shard che ne riusa una del catasto, o due tipi
     * dello shard) collasserebbero in una voce sola, e uno dei due non
     * sarebbe piu' filtrabile. In quel caso l'etichetta porta la chiave fra
     * parentesi.
     */
    public function options(NovaRequest $request): array
    {
        $labels = collect(TrailRegistryAnomalyTypes::values())
            ->mapWithKeys(fn (string $type) => [$type => TrailRegistryAnomalyTypes::label($type)]);

        $counts = $labels->countBy();

        return $labels
            ->mapWithKeys(fn (string $label, string $type) => [
                ($counts[$label] > 1 ? "{$label} ({$type})" : $label) => $type,
            ])
            ->all();
    }
}
