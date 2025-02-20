<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

class App extends Resource
{
    public static $model = \Wm\WmPackage\Models\App::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            HasMany::make('Media', 'ugc_medias', Media::class),
            HasMany::make('UgcPois', 'ugc_pois', UgcPoi::class),
            HasMany::make('UgcTracks', 'ugc_tracks', UgcTrack::class),
            // TODO: implement fields
        ];
    }
}
