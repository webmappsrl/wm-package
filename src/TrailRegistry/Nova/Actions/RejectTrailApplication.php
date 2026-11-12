<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Respinge l'istanza dal dettaglio di Nova. Cosa vuol dire respingere — liberare
 * il numero riservato con la sua causa, salvare il motivo — e' in
 * TrailRegistryService::reject(): qui c'e' solo l'interfaccia e il campo del
 * motivo.
 */
class RejectTrailApplication extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Solo dal dettaglio: la motivazione riguarda la singola domanda, e una
     * frase copiata su piu' respingimenti non dice nulla a nessuno (oc:8567).
     */
    public $onlyOnDetail = true;

    public function name(): string
    {
        return __('Reject');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        $rejected = 0;
        $refused = 0;

        foreach ($models as $application) {
            // La guardia sullo stato e' nel service: l'endpoint Nova puo'
            // essere invocato direttamente, e su un'istanza gia' approvata la
            // respinta libererebbe un numero assegnato.
            try {
                $service->reject($application, (string) $fields->get('rejection_reason'), auth()->id());
                $rejected++;
            } catch (InvalidTrailApplicationTransitionException) {
                $refused++;
            }
        }

        if ($rejected === 0) {
            return Action::danger(__('No application rejected: only applications under review can be rejected.'));
        }

        if ($refused > 0) {
            return Action::message(__('Applications rejected: :rejected. Skipped because not under review: :refused.', [
                'rejected' => $rejected,
                'refused' => $refused,
            ]));
        }

        return Action::message(__('Applications rejected.'));
    }

    /**
     * Obbligatoria: un respingimento senza motivo non dice al richiedente
     * cosa correggere nella nuova domanda (oc:8567).
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Textarea::make(__('Rejection reason'), 'rejection_reason')
                ->rules('required', 'max:2000')
                ->help(__('Visible to the applicant: explain what to correct in the new application.')),
        ];
    }
}
