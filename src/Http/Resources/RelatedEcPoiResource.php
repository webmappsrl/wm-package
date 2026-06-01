<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;

class RelatedEcPoiResource extends EcPoiResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if ($this->getMedia()->isNotEmpty()) {
            $data['properties']['feature_image']['show_image_on_map'] = $this->resolveShowImageOnMap();
        }

        return $data;
    }
}
