<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;

class SyncTracksTaxonomyWhereAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Sincronizza Taxonomy Where su Tracks';

    public $onlyOnIndex = false;

    public $standalone = true;

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $count = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );

        return Action::message("taxonomy_where aggiornata su {$count} tracks.");
    }

    public function fields(\Laravel\Nova\Http\Requests\NovaRequest $request): array
    {
        return [];
    }
}
