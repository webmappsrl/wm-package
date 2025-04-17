<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;
use Wm\WmPackage\Services\GeoJsonService;
use Illuminate\Http\Resources\Json\JsonResource;

class EcPoiResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $geojson = $this->getGeojson();

        $geojson['properties'] = [
            ...GeoJsonService::make()->removeInvalidProperties($geojson['properties']),
            'name' => $this->getTranslations('name'),
            'feature_image' => new MediaResource($this->getMedia()->first()),
            'image_gallery' => MediaResource::collection($this->getMedia()),
        ];

        $fileTypes = ['geojson', 'gpx', 'kml'];
        foreach ($fileTypes as $fileType) {
            $geojson['properties'][$fileType . '_url'] = route('default.api.ec.poi.download.' . $fileType, ['id' => $this->id]);
        }

        return $geojson;
    }
}
