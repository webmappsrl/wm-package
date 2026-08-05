<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Filters\AppFilter;

class UgcMedia extends AbstractGeometryResource
{
    public static $model = \Wm\WmPackage\Models\UgcMedia::class;

    public static function label(): string
    {
        return __('Media');
    }

    public static function singularLabel(): string
    {
        return __('UGC Media');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make(__('App'), 'app', App::class)->filterable(),
            BelongsTo::make(__('Author'), 'author', User::class)->searchable()->filterable(),
            Text::make(__('Name'), 'name'),
            Text::make(__('Photo'), 'relative_url')
                ->displayUsing(fn ($value) => $value ? \Illuminate\Support\Facades\Storage::url($value) : null)
                ->onlyOnDetail(),
            DateTime::make(__('Created at'), 'created_at')->onlyOnDetail(),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new AppFilter,
        ];
    }
}
