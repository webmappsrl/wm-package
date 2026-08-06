<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigDetail;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;

class InfoBoxItemRepeatable extends Repeatable
{
    public static function key(): string
    {
        return 'info-box-item';
    }

    /**
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            FlexibleTranslatable::simple(__('Title'), [Text::make(__('Title'), 'title')]),
            FlexibleTranslatable::richText(__('Content'), [Trix::make(__('Content'), 'content')]),
        ];
    }
}
