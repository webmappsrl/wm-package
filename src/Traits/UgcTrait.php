<?php

namespace Wm\WmPackage\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;

trait UgcTrait
{
    protected function ugcNovaFields(NovaRequest $request): array
    {
        return [
            // add here specific Nova fields for UGCs
            Images::make('Images', 'media')
                ->hideFromIndex()
        ];
    }
}
