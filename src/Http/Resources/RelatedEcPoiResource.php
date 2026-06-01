<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;

class RelatedEcPoiResource extends EcPoiResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        unset($data['properties']['show_image_on_map']);

        if ($this->getMedia()->isNotEmpty()) {
            $featureImage = $data['properties']['feature_image'];
            $featureImageArray = $featureImage instanceof \Illuminate\Http\Resources\Json\JsonResource
                ? $featureImage->toArray($request)
                : (array) $featureImage;
            $featureImageArray['show_image_on_map'] = $this->resolveShowImageOnMap();
            $data['properties']['feature_image'] = $featureImageArray;
        }

        return $data;
    }
}
