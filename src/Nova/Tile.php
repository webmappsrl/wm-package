<?php

namespace Wm\WmPackage\Nova;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\Nova\Fields\IconSelect\IconSelect;

class Tile extends Resource
{
    public static $model = \Wm\WmPackage\Models\Tile::class;

    public static $title = 'attribution';

    public static $search = [
        'id',
        'attribution',
    ];

    public static function label(): string
    {
        return __('Tiles');
    }

    public static function singularLabel(): string
    {
        return __('Tile');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make(__('Attribution'), 'attribution')
                ->sortable()
                ->rules('required', 'max:255')
                ->creationRules('unique:tiles,attribution')
                ->updateRules('unique:tiles,attribution,{{resourceId}}'),
            NovaTabTranslatable::make([
                Text::make(__('Label'), 'label'),
            ]),
            IconSelect::make(__('Icon'), 'icon')
                ->loadFromIconsFile()
                ->searchPlaceholder(__('Search an icon for the tile...'))
                ->help(__('Select an icon name from icons.json')),
            Text::make(__('Server XYZ'), 'server_xyz')
                ->rules('required', 'max:255')
                ->help(__('URL template for the tile server (e.g. https://example.com/{z}/{x}/{y}.png)')),
            Text::make(__('Link'), 'link')
                ->nullable()
                ->hideFromIndex()
                ->rules('nullable', 'max:255')
                ->help(__('Link used over the attribution tag in the app (optional)')),
        ];
    }
}
