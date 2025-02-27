<?php

namespace Wm\WmPackage\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;

trait UgcTrait
{
    protected function ugcNovaFields(NovaRequest $request): array
    {
        return [
            // add here specific Nova fields for UGCs
        ];
    }
}
