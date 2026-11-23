<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\LayerService;

class CreateLayerFromTaxonomyWhere extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Crea Layer');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $apps = App::all();
        $app = null;

        if ($apps->count() === 1) {
            $app = $apps->first();
        } else {
            $appId = $fields->get('app_id');
            if (! $appId) {
                return Action::danger("Seleziona un'App.");
            }

            $app = App::find($appId);
            if (! $app) {
                return Action::danger('App non trovata.');
            }
        }

        if (! $app || empty($app->user_id)) {
            return Action::danger("L'App selezionata non ha un utente associato.");
        }

        $count = 0;

        // Nome con tutte le traduzioni, descrizione, sottotitolo e immagine dell'area: la logica
        // sta in LayerService, che la usa anche l'import delle aree di forestas (oc:9001).
        $layerService = LayerService::make();
        foreach ($models as $taxonomyWhere) {
            $layerService->createLayerFromTaxonomyWhere($taxonomyWhere, $app);
            $count++;
        }

        return Action::message("Creati {$count} layer.");
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [];

        $apps = App::all();
        if ($apps->count() > 1) {
            $fields[] = Select::make('App', 'app_id')
                ->options($apps->pluck('name', 'id')->toArray())
                ->rules('required');
        }

        return $fields;
    }
}
