<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailCodeTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\NumberOccupiedException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Sostituisce a mano il numero prenotato da un'istanza, scegliendo un numero
 * del settore e, se serve, una variante.
 *
 * Vive sul dettaglio dell'istanza perche' e' li' che il gestore guarda la
 * mappa e si accorge che il numero proposto non e' quello giusto: mandarlo
 * nel registro dei codici gli farebbe perdere il contesto su cui decide.
 *
 * Due campi e non una lista sola: trattando «nessuna variante» come una delle
 * opzioni del secondo, la categoria «numero occupato» sparisce e resta una
 * regola sola — mostra cio' che e' libero.
 */
class ReplaceTrailCodeNumber extends Action
{
    use InteractsWithQueue, Queueable;

    public $onlyOnDetail = true;

    /**
     * Una sola istanza per volta: lo stesso numero applicato a piu' istanze
     * farebbe fallire la seconda a meta' lotto, sull'indice unico.
     */
    public $sole = true;

    public function name(): string
    {
        return __('Sostituisci numero');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        /** @var TrailApplication|null $application */
        $application = $models->first();
        $code = $application?->activeCode;

        if ($code === null) {
            return Action::danger(__('Questa istanza non ha un codice attivo da sostituire.'));
        }

        try {
            $service->replaceNumber(
                $code,
                (int) $fields->get('number'),
                (string) ($fields->get('variant') ?: '0'),
                auth()->id(),
            );
        } catch (NumberOccupiedException|InvalidTrailCodeTransitionException $e) {
            return Action::danger($e->getMessage());
        }

        return Action::message(__('Numero sostituito.'));
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make(__('Numero'), 'number')
                ->options($this->numberOptions($request))
                ->rules('required'),

            Select::make(__('Variante'), 'variant')
                ->dependsOn('number', function (Select $field, NovaRequest $request, $formData) {
                    $field->options($this->variantOptions($request, $formData->integer('number')));
                })
                ->rules('required'),
        ];
    }

    /**
     * I numeri del settore con almeno una variante libera, etichettati a tre
     * cifre — settore piu' numero, il codice come lo legge il gestore.
     *
     * @return array<int, string>
     */
    public function numberOptions(NovaRequest $request): array
    {
        $code = $this->activeCodeOf($request);

        if ($code === null) {
            return [];
        }

        // La geometria del codice in esame non sta nel registro: sta nel
        // sentiero, o nell'istanza se il codice e' solo riservato. Stesso
        // COALESCE di neighbourCodes() (oc:8570).
        $ecTracks = (string) config('wm-package.ec_track_table', 'ec_tracks');

        $wkt = DB::selectOne(
            <<<SQL
            SELECT ST_AsText(COALESCE(t.geometry, a.geometry)) AS wkt
            FROM trail_registry_codes c
            LEFT JOIN {$ecTracks} t ON t.id = c.ec_track_id
            LEFT JOIN trail_applications a ON a.id = c.trail_application_id
            WHERE c.id = ?
            SQL,
            [$code->id],
        )?->wkt;

        return collect(app(TrailRegistryService::class)->numbersWithAvailableVariants($code->fullCode, $wkt, $code->id))
            ->mapWithKeys(fn (int $number) => [
                $number => sprintf('%s%02d', $code->sector, $number),
            ])
            ->all();
    }

    /**
     * Le varianti libere del numero scelto. '0' e' «nessuna variante»: una
     * opzione come le altre, che semplicemente non compare se il numero puro
     * e' gia' preso.
     *
     * @return array<string, string>
     */
    public function variantOptions(NovaRequest $request, int $number): array
    {
        $code = $this->activeCodeOf($request);

        if ($code === null) {
            return [];
        }

        return collect(app(TrailRegistryService::class)->availableVariants($code->fullCode, $number))
            ->mapWithKeys(fn (string $variant) => [
                $variant => $variant === '0' ? __('nessuna variante') : $variant,
            ])
            ->all();
    }

    /**
     * Il codice su cui si opera si raggiunge dall'istanza, mai con un find()
     * sull'id della richiesta: da quando l'action vive sul dettaglio
     * dell'istanza, quell'id e' l'id dell'istanza, e find() restituirebbe un
     * codice qualunque senza sollevare nulla.
     *
     * Nova usa due parametri diversi per l'id dell'istanza a seconda della
     * richiesta: all'apertura del modale arriva "resourceId" (i campi si
     * risolvono con quello), mentre la PATCH che parte al cambio del primo
     * campo (ActionRequest, vedi ActionController::sync()) porta invece
     * "resources", una lista di id — qui sempre di un solo elemento, perche'
     * l'azione e' $sole = true. Senza guardare anche "resources" la seconda
     * select (Variante) restava vuota a ogni cambio del numero: e' il bug
     * verificato dal dev in Nova.
     */
    protected function activeCodeOf(NovaRequest $request): ?TrailRegistryCode
    {
        $applicationId = $request->resourceId ?? $this->applicationIdFromResources($request);

        if (! $applicationId) {
            return null;
        }

        return TrailApplication::find($applicationId)?->activeCode;
    }

    /**
     * "resources" puo' essere la stringa "all" quando l'utente ha selezionato
     * tutto dall'indice: in quel caso non c'e' una singola istanza su cui
     * risolvere il codice, quindi si torna null invece di provare un find()
     * su una stringa che non e' un id.
     */
    protected function applicationIdFromResources(NovaRequest $request): int|string|null
    {
        $resources = $request->input('resources');

        if (! is_array($resources) || $resources === []) {
            return null;
        }

        return $resources[0];
    }
}
