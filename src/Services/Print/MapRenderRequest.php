<?php

namespace Wm\WmPackage\Services\Print;

/**
 * Richiesta di rendering della mappa: shard, FeatureCollection GeoJSON e dimensioni in mm dell'area in
 * cui la mappa va nel PDF.
 *
 * Il disegno di ogni feature viaggia nelle sue `properties` (`stroke`, `stroke-width`, convenzione
 * simplestyle-spec): il servizio di stampa (oc:9011) legge solo quelle e butta via il resto.
 */
final readonly class MapRenderRequest
{
    /** Formati di pagina del servizio, lati in mm in verticale (`landscape` li scambia). */
    public const PAGE_FORMATS = ['a5' => [148, 210], 'a4' => [210, 297]];

    /** Decimali delle coordinate mandate al servizio: 6 sono circa 10 cm, il corpo resta sotto i 2 MiB. */
    public const COORDINATE_DECIMALS = 6;

    /**
     * @param  array<string,mixed>  $featureCollection  GeoJSON FeatureCollection
     */
    public function __construct(
        public string $shard,
        public array $featureCollection,
        public float $widthMm,
        public float $heightMm,
    ) {}

    /**
     * Crea la richiesta con lo shard preso da `wm-package.shard_name`.
     *
     * @param  array<string,mixed>  $featureCollection
     */
    public static function forShard(array $featureCollection, float $widthMm, float $heightMm): self
    {
        return new self((string) config('wm-package.shard_name'), $featureCollection, $widthMm, $heightMm);
    }

    /**
     * @return array{shard:string,feature_collection:array<string,mixed>,width_mm:float,height_mm:float}
     */
    public function toArray(): array
    {
        return [
            'shard' => $this->shard,
            'feature_collection' => $this->featureCollection,
            'width_mm' => $this->widthMm,
            'height_mm' => $this->heightMm,
        ];
    }

    /**
     * La pagina da chiedere al servizio, che rende solo pagine intere A5 o A4: l'orientamento segue
     * l'area della mappa, il formato è il più piccolo che la contiene (A4 se nessuno la contiene). La
     * view la mette nell'area con `keepaspectratio`. Esempi: 210 x 297 mm (Forestas) → A4 verticale,
     * 174 x 98 (default del package) → A5 orizzontale, 200 x 170 (mappa d'insieme) → A4 orizzontale.
     *
     * @return array{format: string, orientation: string, dpi: int}
     */
    public function page(int $dpi): array
    {
        $landscape = $this->widthMm > $this->heightMm;
        $format = 'a4';
        foreach (self::PAGE_FORMATS as $name => [$short, $long]) {
            [$width, $height] = $landscape ? [$long, $short] : [$short, $long];
            if ($this->widthMm <= $width && $this->heightMm <= $height) {
                $format = $name;
                break;
            }
        }

        return ['format' => $format, 'orientation' => $landscape ? 'landscape' : 'portrait', 'dpi' => $dpi];
    }

    /**
     * Il corpo di `POST /v1/print/map` (schema `PrintMapRequest` di oc:9011, che rifiuta i campi non
     * previsti). Delle feature passano solo geometria e `stroke`/`stroke-width`: le tracce non
     * pubblicate non escono con nome e id. Le coordinate perdono la quota e si arrotondano.
     *
     * @return array<string, mixed>
     */
    public function toServiceBody(int $dpi, string $styleId, bool $mapInfo, ?float $maxZoom = null, ?float $paddingMm = null): array
    {
        $features = [];
        foreach ((array) ($this->featureCollection['features'] ?? []) as $feature) {
            $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
            if (! is_array($geometry) || ! isset($geometry['type'], $geometry['coordinates'])) {
                continue;
            }
            $properties = (array) ($feature['properties'] ?? []);
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => $geometry['type'], 'coordinates' => self::flatten($geometry['coordinates'])],
                'properties' => array_intersect_key($properties, array_flip(['stroke', 'stroke-width'])) ?: null,
            ];
        }

        $body = [
            'geometry' => ['type' => 'FeatureCollection', 'features' => $features],
            'page' => $this->page($dpi),
            'styleId' => $styleId,
            'mapInfo' => $mapInfo,
        ];
        // Il servizio vuole il margine in pixel della pagina: i mm di carta si convertono al dpi chiesto.
        if ($maxZoom !== null) {
            $body['maxZoom'] = max(0.0, min(19.0, $maxZoom));
        }
        if ($paddingMm !== null) {
            $body['padding'] = (int) round(max(0.0, $paddingMm) / 25.4 * $dpi);
        }

        return $body;
    }

    /** Toglie la quota e arrotonda, ricorsivamente: una posizione è un array di numeri. */
    private static function flatten(mixed $coordinates): mixed
    {
        if (! is_array($coordinates)) {
            return $coordinates;
        }
        if ($coordinates !== [] && ! is_array($coordinates[array_key_first($coordinates)])) {
            return [round((float) $coordinates[0], self::COORDINATE_DECIMALS), round((float) ($coordinates[1] ?? 0), self::COORDINATE_DECIMALS)];
        }

        return array_map(self::flatten(...), array_values($coordinates));
    }
}
