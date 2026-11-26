<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Layer;

/**
 * I percorsi di tutti i cammini come FeatureCollection, per la mappa di tutti i cammini nella
 * dashboard Nova (oc:9040).
 *
 * Una feature per ogni EcTrack associata ad almeno un layer: le tracce senza layer non sono un
 * cammino. Senza quota e semplificate (`all_routes_map_simplify_degrees`, default 0.0005° ≈ 50 m),
 * perché con circa 1.300 tracce a piena risoluzione sarebbero 29 MB, semplificate 1,4 MB. Niente
 * `link` sulle feature: sarebbe un link per ognuna delle tracce, su una mappa di tutta Italia.
 *
 * In cache un'ora, senza invalidazione: la mappa li scarica una volta per apertura della dashboard,
 * e una tappa associata a un cammino compare entro un'ora (le posizioni, che cambiano, hanno un
 * endpoint e una cache a sé).
 */
class AllLayersRoutesGeojson
{
    private const CACHE_SECONDS = 3600;

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    public function get(): array
    {
        $tolerance = (float) config('wm-package.all_routes_map_simplify_degrees', 0.0005);
        $color = Layer::defaultTrackColor();

        // Il colore nella chiave: cambiando la config la mappa cambia subito, senza aspettare la
        // scadenza della cache.
        return Cache::remember(
            "layer-map:all-routes:{$tolerance}:".md5($color),
            now()->addSeconds(self::CACHE_SECONDS),
            fn () => $this->build($tolerance, $color),
        );
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function build(float $tolerance, string $color): array
    {
        $trackTable = config('wm-package.ec_track_table', 'ec_tracks');
        $trackModelClass = config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack');
        $trackMorphType = (new $trackModelClass)->getMorphClass();

        // SQL puro: le geometrie non passano dall'ORM (regola del repo).
        $rows = DB::select(<<<SQL
SELECT t.id, t.name, ST_AsGeoJSON(ST_Force2D(ST_Simplify(t.geometry::geometry, ?)), 5) AS geometry
FROM {$trackTable} t
WHERE t.geometry IS NOT NULL
  AND EXISTS (
      SELECT 1 FROM layerables l
      WHERE l.layerable_id = t.id AND l.layerable_type = ?
  )
ORDER BY t.id
SQL, [$tolerance, $trackMorphType]);

        $features = [];

        foreach ($rows as $row) {
            $geometry = json_decode($row->geometry, true);

            if (! $geometry) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'id' => (int) $row->id,
                    // Stesso nome e stesso colore di default delle tracce in Layer::getFeatureCollectionMap().
                    'tooltip' => MapFeatureName::fromJson($row->name),
                    'strokeColor' => $color,
                    'strokeWidth' => 2,
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }
}
