<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Cards;

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Card;
use Laravel\Nova\Nova;
use Wm\WmPackage\TrailRegistry\Inconsistencies\TrailRegistryInconsistencyTypes;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailInconsistencyTypeFilter;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryInconsistency;

/**
 * Quante incongruenze catastali ci sono, per tipo (oc:8989).
 *
 * Le incongruenze stanno nella tabella delle anomalie, distinte dal `source`:
 * e' la colonna che esiste apposta per la provenienza, e che il registro
 * catastale usa allo stesso modo. Restano pero' un'altra famiglia — un'anomalia
 * si corregge alla fonte e sparisce al giro dopo, un'incongruenza si risolve
 * qui inserendo il dato che manca — e per questo si contano a parte.
 *
 * Un numero alto il primo giorno non e' un allarme: e' la lista di lavoro. Ed
 * e' una lista: ogni riga porta all'elenco delle anomalie gia' filtrato su quel
 * tipo, perche' un numero da solo dice che c'e' del lavoro e non quale.
 */
class TrailRegistryInconsistenciesCard extends Card
{
    public const SOURCE = 'inconsistency';

    public $component = 'trail-registry-inconsistencies-card';

    public $width = '1/2';

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $types = TrailRegistryInconsistencyTypes::values();

        if ($types === []) {
            return array_merge(parent::jsonSerialize(), ['items' => []]);
        }

        $counts = DB::table('trail_registry_anomalies')
            ->where('source', self::SOURCE)
            ->whereIn('type', $types)
            ->groupBy('type')
            // L'alias serve: senza, `pluck` cerca una colonna «count(*)».
            ->selectRaw('type, count(*) as total')
            ->pluck('total', 'type');

        $items = [];
        foreach ($types as $type) {
            $items[] = [
                'type' => $type,
                'label' => TrailRegistryInconsistencyTypes::label($type),
                'description' => TrailRegistryInconsistencyTypes::definition($type)?->description(),
                'count' => (int) ($counts[$type] ?? 0),
                'url' => self::urlFor($type),
            ];
        }

        return array_merge(parent::jsonSerialize(), ['items' => $items]);
    }

    /**
     * L'elenco delle incongruenze filtrato su un tipo.
     *
     * I filtri di Nova viaggiano nella query string come un JSON codificato in base64, con la
     * classe del filtro per chiave. Il prefisso lo da' `Nova::path()`: scritto a mano, un
     * pannello montato sotto un percorso diverso da `/nova` avrebbe link che non portano da
     * nessuna parte.
     */
    private static function urlFor(string $type): string
    {
        $key = TrailRegistryInconsistency::uriKey();
        $filter = base64_encode((string) json_encode([[TrailInconsistencyTypeFilter::class => $type]]));

        return Nova::path()."/resources/{$key}?{$key}_filter={$filter}";
    }
}
