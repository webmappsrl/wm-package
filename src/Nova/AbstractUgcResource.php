<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\CopyUgc;
use Wm\WmPackage\Nova\Actions\ExportTo;
use Wm\WmPackage\Nova\Filters\SchemaFilter;
use Wm\WmPackage\Nova\Filters\UgcCreationDateFilter;

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
            NovaTabTranslatable::make([Text::make('Name', 'name')])->hide(),
            Text::make('Name', 'properties->name'),
            BelongsTo::make('Author', 'author', User::class)->searchable()->filterable(),
            Images::make('Image', 'default')->onlyOnDetail(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new ExportTo,
            new CopyUgc,
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            (new UgcCreationDateFilter),
            (new SchemaFilter($this->model())),
        ];
    }
}
