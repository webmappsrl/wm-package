<?php

namespace Wm\WmPackage\Nova;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Nova\Actions\DownloadEcTrackAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\TranslateModelAction;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
use Wm\WmPackage\Nova\Fields\TrackColor\src\TrackColor;
use Wm\WmPackage\Nova\Actions\UploadTrackFile;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;
use Wm\WmPackage\Nova\Filters\FeaturesExcludeByIds;
use Wm\WmPackage\Nova\Filters\FeaturesIncludeByIds;
use Wm\WmPackage\Nova\Traits\MultiLinestringResourceTrait;

class EcTrack extends AbstractEcResource
{
    use MultiLinestringResourceTrait {
        fields as protected fieldsTrait;
    }

    public static function label(): string
    {
        return __('Tracks');
    }

    public static function singularLabel(): string
    {
        return __('EC Track');
    }

    public static $model = \Wm\WmPackage\Models\EcTrack::class;

    public static $title = 'name';

    public function title()
    {
        return $this->getTranslation('name', 'it');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...$this->fieldsTrait($request),
            Tab::group(__('Details'), [
                Tab::make(__('Info'), $this->getInfoTabFields()),
                Tab::make(__('Style'), $this->getStyleTabFields()),
                Tab::make(__('DEM'), $this->getDemTabFields()),
            ]),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class)
                ->searchable()
                ->collapsedByDefault(),
            MorphToMany::make('Layers', 'layers', Layer::class)
                ->collapsedByDefault(),
            MorphToMany::make('Activities', 'taxonomyActivities', TaxonomyActivity::class)
                ->display('name')
                ->collapsedByDefault(),

        ];
    }

    public function filters(NovaRequest $request): array
    {
        // Needed filters for the custom field on layers
        if (str_contains($request->getUri(), '/nova-api/')) {
            $layerRelationName = $this->resource->getLayerRelationName();

            return [
                new FeaturesByLayerFilter($layerRelationName),
                new FeaturesIncludeByIds($layerRelationName),
                new FeaturesExcludeByIds($layerRelationName),
            ];
        }

        return [];
    }

    /**
     * Get the actions available for the resource.
     */
    public function actions(NovaRequest $request): array
    {
        return [
            new Actions\ReindexSearchableAction,
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Update Tracks on AWS')),
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new UpdateModelWithGeometryTaxonomyWhere($ecTrack),
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Regenerate Taxonomy Where')),
            new ExecuteEcTrackDataChainAction,
            new DownloadEcTrackAction,
            (new UploadTrackFile)->standalone(),
            new TranslateModelAction,
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        return [
            new EcTrackApiLinksCard($request->findModelOrFail()),
        ];
    }

    public function getInfoTabFields(): array
    {
        return [
            Boolean::make('Not Accessible', 'properties->not_accessible')
                ->help('Enable this option to indicate that the track is not accessible. The reason can be specified below.'),
            NovaTabTranslatable::make([
                Textarea::make(__('Not Accessible Message'), 'properties->not_accessible_message'),
            ]),

        ];
    }

    protected function getStyleTabFields(): array
    {
        return [
            TrackColor::make(__('Color'), 'properties->color')->hideFromIndex(),
        ];
    }
}
