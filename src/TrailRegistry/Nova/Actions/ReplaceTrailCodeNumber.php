<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Sostituisce a mano il numero di un codice ancora riservato, scegliendo fra i
 * liberi del settore. Disponibile solo sui codici riservati: un numero
 * assegnato e' gia' stato comunicato al richiedente.
 */
class ReplaceTrailCodeNumber extends Action
{
    use InteractsWithQueue, Queueable;

    public $onlyOnDetail = true;

    public function name(): string
    {
        return __('Sostituisci numero');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        foreach ($models as $code) {
            $service->replaceNumber($code, (int) $fields->get('number'), auth()->id());
        }

        return Action::message(__('Numero sostituito.'));
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make(__('Numero'), 'number')
                ->options($this->availableNumbers($request))
                ->rules('required'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function availableNumbers(NovaRequest $request): array
    {
        // Nel listing delle action (index) non c'e' un resourceId: il campo
        // e' comunque richiesto da Nova, ma senza opzioni.
        if (! $request->resourceId) {
            return [];
        }

        $code = TrailRegistryCode::find($request->resourceId);

        if ($code === null) {
            return [];
        }

        return collect(app(TrailRegistryService::class)->availableNumbers($code->fullCode))
            ->mapWithKeys(fn (int $number) => [$number => sprintf('%02d', $number)])
            ->all();
    }
}
