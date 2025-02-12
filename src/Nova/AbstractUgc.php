<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Code;
use Laravel\Nova\Http\Requests\NovaRequest;

abstract class AbstractUgc extends AbstractGeometryModel
{
    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Code::make('Properties', 'properties')->json()->rules('required', 'json'),
            //add here a way to view/edit user_id
        ];
    }
}
