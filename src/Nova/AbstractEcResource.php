<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\BelongsTo;
use App\Models\User;
use Wm\WmPackage\Models\EcTrack;

abstract class AbstractEcResource extends AbstractGeometryResource
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
        ];
    }
}
