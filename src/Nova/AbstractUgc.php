<?php

namespace Wm\WmPackage\Nova;

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
            //add here a way to view/edit user_id
        ];
    }
}
