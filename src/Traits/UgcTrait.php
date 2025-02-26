<?php

namespace Wm\WmPackage\Traits;

use App\Nova\User;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Http\Requests\NovaRequest;

trait UgcTrait
{
    protected function ugcNovaFields(NovaRequest $request): array
    {
        return [
            BelongsTo::make('Author', 'author', User::class),
        ];
    }
}
