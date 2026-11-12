<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Approva l'istanza dal dettaglio di Nova. Cosa vuol dire approvare — creare il
 * sentiero, attaccargli il tipo, replicare i media, rendere definitivo il codice
 * — e' in TrailRegistryService::approve(): qui c'e' solo l'interfaccia.
 */
class ApproveTrailApplication extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Solo dal dettaglio: approvare vuol dire assegnare un codice guardando la
     * mappa della singola istanza (oc:8567).
     */
    public $onlyOnDetail = true;

    public function name(): string
    {
        return __('Approve');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        $approved = 0;
        $refused = 0;

        foreach ($models as $application) {
            // La guardia sullo stato e' nel service, non solo in canRun():
            // l'autorizzazione dell'interfaccia non e' un presidio, l'endpoint
            // Nova puo' essere invocato direttamente.
            try {
                $service->approve($application, auth()->id());
                $approved++;
            } catch (InvalidTrailApplicationTransitionException) {
                $refused++;
            }
        }

        if ($approved === 0) {
            return Action::danger(__('No application approved: only applications under review can be approved.'));
        }

        if ($refused > 0) {
            return Action::message(__('Applications approved: :approved. Skipped because not under review: :refused.', [
                'approved' => $approved,
                'refused' => $refused,
            ]));
        }

        return Action::message(__('Applications approved.'));
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
