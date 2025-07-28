<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Services\GeometryComputationService;

class EcTrackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $geojson = $this->getGeojson();
        $geometryComputationService = GeometryComputationService::make();

        // force linestring
        $geometryLinestring = $geometryComputationService->getModelLineMergeGeojson($this->resource);
        $geojson['geometry'] = json_decode($geometryLinestring, true);

        $geojson['properties'] = [
            ...GeoJsonService::make()->removeInvalidProperties($geojson['properties']),
            'name' => $this->getTranslations('name'),
            'roundtrip' => $geojson['properties']['dem_data']['round_trip'] ?? $geometryComputationService->isRoundtrip($geojson['geometry']['coordinates']),
            'feature_image' => new MediaResource($this->getMedia()->first()),
            'image_gallery' => MediaResource::collection($this->getMedia()),
            'related_pois' => $this->getRelatedPois(),
        ];

        return $geojson;
    }

    /**
     * Gestisce la relazione ecPois con fallback per modelli diversi
     * Se la tabella pivot non esiste, ritorna array vuoto
     */
    private function getRelatedPois()
    {
        try {
            return $this->ecPois->map(function (EcPoi $ecPoi) {
                return EcPoiResource::make($ecPoi);
            });
        } catch (\Exception $e) {
            // Se la relazione fallisce (es: tabella pivot non esiste), ritorna array vuoto
            return [];
        }
    }
}
