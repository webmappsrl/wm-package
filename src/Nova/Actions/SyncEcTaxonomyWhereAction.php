<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;

class SyncEcTaxonomyWhereAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Sincronizza Taxonomy Where su EC Features';

    public $onlyOnIndex = false;

    public $standalone = true;

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        SyncTaxonomyWhereJob::dispatch();

        return Action::message('Sincronizzazione taxonomy_where su EcTrack ed EcPoi avviata.');
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

