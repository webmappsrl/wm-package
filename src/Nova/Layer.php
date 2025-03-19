<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;


class Layer extends AbstractGeometryResource
{

    public static $model = \Wm\WmPackage\Models\Layer::class;

    public function fields(NovaRequest $request): array
    {
        return  [
            ID::make()->sortable(),
            Text::make('Name', 'name'),
            BelongsTo::make('App', 'appOwner', App::class),
            Code::make('Properties', $this->getPropertiesColumnName())->json(),
        ];
    }
}
