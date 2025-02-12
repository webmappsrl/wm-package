<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;

class Media extends AbstractGeometryModel
{
    public static $model = \Wm\WmPackage\Models\Media::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Text::make('Model Type', 'model_type'),
            Text::make('Model ID', 'model_id'),
            Text::make('UUID', 'uuid'),
            Text::make('Collection Name', 'collection_name'),
            Text::make('Name', 'name'),
            Text::make('File Name', 'file_name'),
            Text::make('MIME Type', 'mime_type'),
            Text::make('Disk', 'disk'),
            Text::make('Conversions Disk', 'conversions_disk'),
            Number::make('Size', 'size'),
            Code::make('Manipulations', 'manipulations')->json()->rules('required', 'json'),
            Code::make('Custom Properties', 'custom_properties')->json()->rules('required', 'json'),
            Code::make('Generated Conversions', 'generated_conversions')->json()->rules('required', 'json'),
            Code::make('Responsive Images', 'responsive_images')->json()->rules('required', 'json'),
            Number::make('Order Column', 'order_column')
        ];
    }
}
