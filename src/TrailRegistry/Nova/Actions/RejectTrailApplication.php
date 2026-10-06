<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Respinge l'istanza e libera il numero riservato.
 *
 * La liberazione e' sempre conseguenza di un atto, mai un'operazione a se':
 * per questo la causa (`application_rejected`) finisce nella storia del
 * codice, e per questo il registro non espone una action «Libera numero».
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
            // La guardia vive qui e non solo in canRun(): l'endpoint Nova puo'
            // essere invocato direttamente. Su un'istanza gia' approvata la
            // respinta libererebbe un numero ASSEGNATO, che resterebbe legato
            // al suo sentiero e tornerebbe proponibile a una seconda domanda —
            // la doppia assegnazione dello stesso codice. La guardia del
            // service non basta: release() accetta legittimamente anche un
            // codice assegnato, perche' serve al deaccatastamento.
            if ($application->status !== TrailApplicationStatus::UnderReview) {
                $refused++;

                continue;
            }

            $code = $application->activeCode;

            DB::transaction(function () use ($application, $code, $service, $fields) {
                if ($code !== null) {
                    $service->release($code, 'application_rejected', auth()->id());
                }

                $application->update([
                    'status' => TrailApplicationStatus::Rejected,
                    'rejection_reason' => $fields->get('rejection_reason'),
                ]);
            });

            $rejected++;
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
