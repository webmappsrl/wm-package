<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Code;
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
            Text::make('Model Type', 'model_type')->required(),
            Number::make('Model ID', 'model_id')->required(),
            Text::make('UUID', 'uuid'),
            Text::make('Collection Name', 'collection_name')->required(),
            Text::make('Name', 'name')->required(),
            Text::make('File Name', 'file_name')->required(),
            Text::make('MIME Type', 'mime_type'),
            Text::make('Disk', 'disk')->required(),
            Text::make('Conversions Disk', 'conversions_disk'),
            Number::make('Size', 'size')->required(),
            Code::make('Manipulations', 'manipulations')->json()->rules('required', 'json'),
            Code::make('Custom Properties', 'custom_properties')->json()->rules('required', 'json'),
            Code::make('Generated Conversions', 'generated_conversions')->json()->rules('required', 'json'),
            Code::make('Responsive Images', 'responsive_images')->json()->rules('required', 'json'),
            Number::make('Order Column', 'order_column'),
        ];
    }
}
