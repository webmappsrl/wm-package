<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;

class RegenerateTaxonomyWhere extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Regenerate taxonomy where';

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $models->each(function ($m) {
            Bus::chain([
                new UpdateModelWithGeometryTaxonomyWhere($m),
                new UpdateTracksOnAws($m),
            ]);
        });

        return Action::message('Models enqueued for taxonomy where regeneration!');
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
