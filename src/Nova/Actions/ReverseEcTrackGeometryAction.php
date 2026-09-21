<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Traits\HasDemClassification;

/**
 * Inverte il verso di percorrenza della geometria di una EcTrack e rilancia il ricalcolo
 * dei dati DEM (ascent, descent, ele_from, ele_to, durate) che dipendono da quel verso.
 *
 * L'inversione avviene via SQL puro (ST_Reverse), non tramite assegnazione Eloquent seguita
 * da save(): risalvare una geometria PostGIS via ORM la corrompe. Proprio perché la scrittura
 * non passa da Eloquent, l'observer di EcTrack non si attiva — il ricalcolo va quindi
 * dispatchato esplicitamente qui.
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
        $overriddenFieldsByTrack = [];

        foreach ($models as $ecTrack) {
            /** @var EcTrack $ecTrack */

            // La colonna è di tipo geography, non geometry: ST_Reverse non ha un overload per
            // geography (PostGIS lancia "function st_reverse(geography) does not exist"), quindi
            // serve il cast esplicito. Il risultato torna a geography tramite il cast implicito
            // di PostgreSQL su INSERT/UPDATE.
            DB::statement(
                'UPDATE '.$ecTrack->getTable().' SET geometry = ST_Reverse(geometry::geometry) WHERE id = ?',
                [$ecTrack->id]
            );

            $ecTrack->refresh();

            $overriddenFields = $this->getOverriddenFields($ecTrack);
            if (! empty($overriddenFields)) {
                $overriddenFieldsByTrack[$ecTrack->id] = $overriddenFields;
            }

            // Nova esegue l'intera handle() dentro una transazione DB (Actions\Transaction::run()).
            // Le connessioni di coda del progetto hanno after_commit=false, quindi un dispatch
            // "nudo" pusha il job subito: un worker potrebbe leggere la geometria pre-inversione
            // perché la transazione non ha ancora fatto commit. ->afterCommit() forza questo
            // dispatch specifico ad attendere il commit, indipendentemente dalla config globale.
            UpdateEcTrackDemJob::dispatch($ecTrack)->afterCommit();
        }

        if (empty($overriddenFieldsByTrack)) {
            return Action::message(__('Track geometry reversed. Recalculation in progress.'));
        }

        $overriddenFieldNames = array_values(array_unique(array_merge(...array_values($overriddenFieldsByTrack))));

        return Action::message(__(
            'Track geometry reversed. Recalculation in progress. Warning: manual overrides present on: :fields — verify they still match the new direction.',
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
