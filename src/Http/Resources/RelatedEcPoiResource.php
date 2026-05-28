<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;

class RelatedEcPoiResource extends EcPoiResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if ($this->getMedia()->isNotEmpty()) {
            $data['properties']['feature_image']['use_image_as_icon'] = $this->resolveUseImageAsIcon();
        }

        return $data;
    }
}
