<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Nova\Traits\HasDemClassification;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Services\GeometryComputationService;

class EcTrackResource extends JsonResource
{
    use HasDemClassification;

    private const DEM_FIELDS = [
        'distance', 'ascent', 'descent',
        'ele_max', 'ele_min', 'ele_from', 'ele_to',
        'duration_forward', 'duration_backward',
    ];

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

        $properties = [
            ...GeoJsonService::make()->removeInvalidProperties($geojson['properties']),
        ];

        // Applica priorità MANUAL > OSM > DEM per i 9 campi principali e rimuove le sorgenti raw
        $properties = $this->applyDemFields($properties, $this->resource);

        $properties['name'] = $this->getTranslations('name');
        $properties['roundtrip'] = $properties['round_trip'] ?? $geometryComputationService->isRoundtrip($geojson['geometry']['coordinates']);
        $properties['related_pois'] = $this->getRelatedPois();

        $media = $this->getMedia();
        if ($media->isNotEmpty()) {
            $properties['feature_image'] = new MediaResource($media->first());
            $properties['image_gallery'] = MediaResource::collection($media);
        }

        $geojson['properties'] = $properties;

        return $geojson;
    }

    /**
     * Applica la tabella di priorità MANUAL > OSM > DEM ai 9 campi principali.
     * Rimuove le sorgenti raw (manual_data, dem_data, osm_data) dall'array risultante.
     */
    protected function applyDemFields(array $properties, object $model): array
    {
        foreach (self::DEM_FIELDS as $field) {
            $classified = $this->classifyField($model, $field);
            if ($classified['currentValue'] !== null) {
                $properties[$field] = $classified['currentValue'];
            } else {
                unset($properties[$field]);
            }
        }

        unset($properties['manual_data'], $properties['dem_data'], $properties['osm_data']);

        return $properties;
    }

    /**
     * Gestisce la relazione ecPois con fallback per modelli diversi
     * Se la tabella pivot non esiste, ritorna array vuoto
     */
    private function getRelatedPois()
    {
        try {
            return $this->ecPois
                ->whereNull('osmfeatures_id') // TODO: da rimuovere, aggiunto per osm2cai, evita che i poi presi da osm vengano aggiunti ai related
                ->map(function (EcPoi $ecPoi) {
                    return EcPoiResource::make($ecPoi);
                })
                ->toArray();
        } catch (\Exception $e) {
            // Se la relazione fallisce (es: tabella pivot non esiste), ritorna array vuoto
            return [];
        }
    }
}
