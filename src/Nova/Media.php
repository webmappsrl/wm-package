<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Media extends AbstractPointModel
{
    public static $model = \Wm\WmPackage\Models\Media::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),

            MorphTo::make(__('Model'))
                ->types([
                    UgcPoi::class,
                    UgcTrack::class,
                ])
                ->searchable(),

            Text::make('Model Type', 'model_type')->readonly(),
            Number::make('Model ID', 'model_id')->readonly(),
            Text::make('UUID', 'uuid')->readonly(),
            Text::make('Collection Name', 'collection_name')->readonly(),
            Text::make('Name', 'name')->readonly(),
            Text::make('File Name', 'file_name')->readonly(),
            Text::make('MIME Type', 'mime_type')->readonly(),
            Text::make('Disk', 'disk')->readonly(),
            Text::make('Conversions Disk', 'conversions_disk')->readonly(),
            Number::make('Size', 'size')->readonly(),
            Code::make('Manipulations', 'manipulations')->json()->readonly(),
            Code::make('Custom Properties', 'custom_properties')->json()->readonly(),
            Code::make('Generated Conversions', 'generated_conversions')->json()->readonly(),
            Code::make('Responsive Images', 'responsive_images')->json()->readonly(),
            Number::make('Order Column', 'order_column')->readonly(),
        ];
    }
}
