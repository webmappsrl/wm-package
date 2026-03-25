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
use Wm\WmPackage\Models\Layer;

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

        if ($apps->count() === 1) {
            $appId = null; // boot hook assegna app_id automaticamente
        } else {
            $appId = $fields->get('app_id');
            if (! $appId) {
                return Action::danger("Seleziona un'App.");
            }
        }

        $count = 0;

        foreach ($models as $taxonomyWhere) {
            $layer = Layer::create([
                'name'    => $taxonomyWhere->name,
                'app_id'  => $appId,
                'user_id' => auth()->id(),
            ]);

            $layer->taxonomyWheres()->attach($taxonomyWhere->id);
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
