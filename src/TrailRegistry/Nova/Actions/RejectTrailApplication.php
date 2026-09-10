<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
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

    public function name(): string
    {
        return __('Respingi');
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

            DB::transaction(function () use ($application, $code, $service) {
                if ($code !== null) {
                    $service->release($code, 'application_rejected', auth()->id());
                }

                $application->update(['status' => TrailApplicationStatus::Rejected]);
            });

            $rejected++;
        }

        if ($rejected === 0) {
            return Action::danger(__('Nessuna istanza respinta: solo le istanze in istruttoria possono essere respinte.'));
        }

        if ($refused > 0) {
            return Action::message(__('Istanze respinte: :rejected. Saltate perche\' non in istruttoria: :refused.', [
                'rejected' => $rejected,
                'refused' => $refused,
            ]));
        }

        return Action::message(__('Istanze respinte.'));
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
