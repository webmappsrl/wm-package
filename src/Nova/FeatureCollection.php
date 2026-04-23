<?php

namespace Wm\WmPackage\Nova;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Laravel\Nova\Resource;
use Wm\WmPackage\Nova\Actions\FeatureCollection\GenerateFeatureCollectionAction;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\FeatureCollectionApiLinksCard;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

class FeatureCollection extends Resource
{
    public static $trafficCop = false;

    public static $model = \Wm\WmPackage\Models\FeatureCollection::class;

    public static $title = 'name';

    public static $search = ['id', 'name'];

    public static $with = ['app', 'layers'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Panel::make(__('Base'), [
                Text::make(__('Name'), 'name')->rules('required')->sortable(),
                NovaTabTranslatable::make([
                    Text::make(__('Label'), 'label'),
                ]),
                BelongsTo::make(__('App'), 'app', App::class)->required(),
                Boolean::make(__('Enabled'), 'enabled'),
                Boolean::make(__('Default'), 'default'),
                Boolean::make(__('Clickable'), 'clickable'),
            ]),

            Panel::make(__('Source'), [
                Select::make(__('Mode'), 'mode')
                    ->options([
                        'generated' => 'Generated (from layers)',
                        'upload' => 'Upload file',
                        'external' => 'External URL',
                    ])
                    ->required()
                    ->displayUsingLabels(),

                BelongsToMany::make(__('Layers'), 'layers', Layer::class)
                    ->hideWhenCreating()
                    ->help(__('Only used in generated mode')),

                Text::make(__('External URL'), 'external_url')
                    ->nullable()
                    ->help(__('Only used in external mode')),
            ]),

            Panel::make(__('Style'), [
                Text::make(__('Fill Color'), 'fill_color')->nullable(),
                Text::make(__('Stroke Color'), 'stroke_color')->nullable(),
                Text::make(__('Stroke Width'), 'stroke_width')->nullable(),
                Textarea::make(__('Icon (SVG)'), 'icon')->nullable()->hideFromIndex(),
            ]),

            Panel::make(__('Configuration'), [
                Code::make(__('Configuration'), 'configuration')
                    ->json()
                    ->nullable()
                    ->hideFromIndex(),
            ]),

            Panel::make(__('Status'), [
                Text::make(__('File Path'), 'file_path')->readonly()->onlyOnDetail(),
                DateTime::make(__('Generated At'), 'generated_at')->readonly()->onlyOnDetail(),
            ]),

            FeatureCollectionMap::make(__('Preview'), 'geometry', fn () => null)
                ->geojsonUrl(fn () => $this->resource->getUrl())
                ->onlyOnDetail(),
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        return [
            new FeatureCollectionApiLinksCard($request->findModelOrFail()),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new GenerateFeatureCollectionAction,
        ];
    }
}
