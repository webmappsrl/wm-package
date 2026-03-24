<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\RetryTaxonomyWhereGeometryFetch;
use Wm\WmPackage\Nova\Actions\SyncTracksTaxonomyWhereAction;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

class TaxonomyWhere extends AbstractTaxonomyResource
{
    public static $model = \Wm\WmPackage\Models\TaxonomyWhere::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    public static function label(): string
    {
        return 'Taxonomies Where';
    }

    public static function singularLabel(): string
    {
        return 'Taxonomy Where';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('name'),
            Text::make('osmfeatures_id')->readonly(),
            Number::make('admin_level')->readonly(),
            FeatureCollectionMap::make('Geometry', 'geometry')->onlyOnDetail(),
            Boolean::make('Geometry', function () {
                /** @var \Wm\WmPackage\Models\TaxonomyWhere $model */
                $model = $this->resource;

                return ! is_null($model->geometry);
            })->onlyOnIndex(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new RetryTaxonomyWhereGeometryFetch,
            new SyncTracksTaxonomyWhereAction,
        ];
    }
}
