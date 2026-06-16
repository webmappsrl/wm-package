<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;

class TaxonomyTheme extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyTheme::class;

    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MorphToMany::make('Tracks Associate', 'ecTracks', EcTrack::class)
                ->display('name'),
        ];
    }
}
