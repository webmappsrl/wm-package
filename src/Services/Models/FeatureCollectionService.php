<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\StorageService;

class FeatureCollectionService
{
    public function generate(FeatureCollection $fc): array
    {
        $features = [];

        foreach ($fc->layers as $layer) {
            $wheres = $layer->taxonomyWheres;

            foreach ($wheres as $where) {
                $geometry = DB::selectOne(
                    'SELECT ST_AsGeoJSON(geometry) as geojson FROM taxonomy_wheres WHERE id = ?',
                    [$where->id]
                );

                if (! $geometry || ! $geometry->geojson) {
                    continue;
                }

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => json_decode($geometry->geojson, true),
                    'properties' => [
                        'layer_id' => $layer->id,
                        'clickable' => $fc->clickable,
                    ],
                ];
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    public function generateAndStore(FeatureCollection $fc): bool
    {
        $geojson = $this->generate($fc);
        $contents = json_encode($geojson);

        $path = StorageService::make()->storeFeatureCollection(
            $fc->app_id,
            $fc->id,
            $contents
        );

        if ($path === false) {
            return false;
        }

        $fc->update([
            'file_path' => $path,
            'generated_at' => now(),
        ]);

        return true;
    }
}
