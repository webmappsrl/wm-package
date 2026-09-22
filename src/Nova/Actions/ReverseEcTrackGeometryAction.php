<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Traits\HasDemClassification;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcTrackService;

/**
 * Inverte il verso di percorrenza della geometria di una EcTrack e rilancia l'intera data
 * chain di ricalcolo dei dati che dipendono da quel verso (DEM, dati correnti, pendenza,
 * TaxonomyWhere, immagine profilo altimetrico, tile PBF, dati serviti all'app).
 *
 * L'inversione avviene via SQL puro (GeometryComputationService::reverseGeometry()), non
 * tramite assegnazione Eloquent seguita da save(): risalvare una geometria PostGIS via ORM la
 * corrompe. Proprio perché la scrittura non passa da Eloquent, l'observer di EcTrack non si
 * attiva — il ricalcolo va quindi forzato esplicitamente con updateDataChain(forceGeometryChain: true).
 */
class ReverseEcTrackGeometryAction extends Action
{
    use HasDemClassification, InteractsWithQueue, Queueable;

    /**
     * Campi impattati dal verso di percorrenza, verificati contro eventuali override manuali.
     */
    protected const DIRECTION_DEPENDENT_FIELDS = [
        'ascent', 'descent', 'ele_from', 'ele_to', 'duration_forward', 'duration_backward',
    ];

    public function name()
    {
        return __('Reverse Track Geometry');
    }

    /**
     * Perform the action on the given models.
     *
     * ->sole() in EcTrack::actions() è un vincolo solo lato UI (disabilita la selezione
     * multipla nel pannello Nova): il server (Laravel\Nova\Actions\DispatchAction::forModels())
     * non lo applica e passerebbe comunque a handle() qualunque collection ricevuta in una
     * richiesta diretta. Iterare su $models, invece di assumere un solo elemento, evita che
     * una selezione con più risorse venga processata solo in parte mostrando comunque successo.
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $geometryService = app(GeometryComputationService::class);
        $ecTrackService = app(EcTrackService::class);

        $overriddenFieldsByTrack = [];

        foreach ($models as $ecTrack) {
            /** @var EcTrack $ecTrack */
            $geometryService->reverseGeometry($ecTrack);
            $ecTrack->refresh();

            $overriddenFields = $this->getOverriddenFields($ecTrack);
            if (! empty($overriddenFields)) {
                $overriddenFieldsByTrack[$ecTrack->id] = $overriddenFields;
            }

            $ecTrackService->updateDataChain($ecTrack, forceGeometryChain: true);
        }

        if (empty($overriddenFieldsByTrack)) {
            return Action::message(__('Track geometry reversed. Recalculation in progress. This may take a while.'));
        }

        $overriddenFieldNames = array_values(array_unique(array_merge(...array_values($overriddenFieldsByTrack))));

        // Action::danger() invece di Action::message(): quest'ultimo è reso in verde da Nova,
        // identico al messaggio di successo, e un avviso che sembra una conferma non viene letto
        // (pattern già usato nel package, es. ImportTaxonomyWhere.php).
        return Action::danger(__(
            'Track geometry reversed. Recalculation in progress. This may take a while. Warning: manual overrides present on: :fields — verify they still match the new direction.',
            ['fields' => implode(', ', $overriddenFieldNames)]
        ));
    }

    /**
     * Campi dipendenti dal verso con un override manuale attivo, secondo la stessa
     * classificazione MANUAL/OSM/DEM/EMPTY usata ovunque nel detail Nova di EcTrack.
     *
     * @return array<int, string>
     */
    protected function getOverriddenFields(EcTrack $ecTrack): array
    {
        return array_values(array_filter(
            self::DIRECTION_DEPENDENT_FIELDS,
            fn ($field) => $this->classifyField($ecTrack, $field)['indicator'] === 'MANUAL'
        ));
    }
}
