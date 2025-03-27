<?php

namespace Wm\WmPackage\Nova;

use Wm\WmPackage\Nova\EcPoi;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Text;
use Wm\WmPackage\Nova\UgcPoi;
use Wm\WmPackage\Nova\EcTrack;
use Laravel\Nova\Fields\Number;
use Wm\WmPackage\Nova\UgcTrack;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Traits\PointResourceTrait;

class Media extends AbstractGeometryResource
{
    use PointResourceTrait {
        fields as protected fieldsFromTrait;
    }

    public static $model = \Wm\WmPackage\Models\Media::class;

    public function fields(NovaRequest $request): array
    {

        return [
            ...$this->fieldsFromTrait($request),
            MorphTo::make(__('Model'))
                ->types([
                    UgcPoi::class,
                    UgcTrack::class,
                    EcPoi::class,
                    EcTrack::class,
                ])
                ->searchable(),
            Text::make('UUID', 'uuid'),
            Text::make('Collection Name', 'collection_name'),
            Text::make('Name', 'name'),
            Text::make('File Name', 'file_name'),
            Text::make('MIME Type', 'mime_type'),
            Text::make('Disk', 'disk'),
            Text::make('Conversions Disk', 'conversions_disk'),
            Number::make('Size', 'size'),
            Code::make('Manipulations', 'manipulations')->json()->rules('required', 'json'),
            Code::make('Generated Conversions', 'generated_conversions')->json()->rules('required', 'json'),
            Code::make('Responsive Images', 'responsive_images')->json()->rules('required', 'json'),
            Number::make('Order Column', 'order_column'),
        ];
    }
}
