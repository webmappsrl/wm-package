<?php

namespace Wm\WmPackage\Nova\Actions\FeatureCollection;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;

class GenerateFeatureCollectionAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Rigenera';

    public function handle(ActionFields $fields, Collection $models): void
    {
        foreach ($models as $fc) {
            if ($fc->mode === 'generated') {
                GenerateFeatureCollectionJob::dispatch($fc->id);
            }
        }
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
