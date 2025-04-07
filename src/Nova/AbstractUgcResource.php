<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Laravel\Nova\Fields\BelongsTo;
use Wm\WmPackage\Nova\Actions\CopyUgc;
use Wm\WmPackage\Nova\Actions\ExportTo;
use Laravel\Nova\Http\Requests\NovaRequest;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;

abstract class AbstractUgcResource extends AbstractGeometryResource
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
            BelongsTo::make('Author', 'author', User::class),
            Images::make('Image', 'default')->onlyOnDetail(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new ExportTo(),
            new CopyUgc(),
        ];
    }
}
