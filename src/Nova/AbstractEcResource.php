<?php

namespace Wm\WmPackage\Nova;

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;

abstract class AbstractEcResource extends AbstractGeometryResource
{
    /**
     * Build an "index" query for the given resource.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            return $query->where('user_id', $user->id);
        }

        return $query;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Images::make('Image', 'default'),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new FeaturesByLayerFilter($this->model()::class),
        ];
    }

    /**
     * Determine if this resource uses Laravel Scout.
     * https://nova.laravel.com/docs/v5/search/scout-integration
     *
     * @return bool
     */
    public static function usesScout()
    {
        return false;
    }
}
