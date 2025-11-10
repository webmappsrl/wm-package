<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;

class TaxonomyActivity extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyActivity::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MorphToMany::make('Tracks Associate', 'ecTracks', EcTrack::class)
            ->display('name')
            ->help('Punti di interesse associati a questa tassonomia'),
        ];
    }
}
