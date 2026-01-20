<?php

namespace Wm\WmPackage\Nova\Actions;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class SaveModelsAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Save Models');
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $ok = 0;
        $ko = 0;

        $models->each(function ($m) use (&$ok, &$ko) {
            try {
                $m->save();
                $ok++;
            } catch (Exception $e) {
                Log::error('Impossible save model with Save models Nova action', [
                    'model_id' => $m->id,
                    'model_class' => $m::class,
                    'message' => $e->getMessage(),
                ]);
                $ko++;
            }
        });

        return Action::message("$ok models have been saved successfully and $ko models raised an exception!");
    }

    /**
     * Get the fields available on the action.
     *
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [];
    }
}
