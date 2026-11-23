<?php

namespace Wm\WmPackage\Services\Print;

/**
 * Richiesta di rendering della mappa: shard, FeatureCollection GeoJSON e dimensioni in mm.
 *
 * Ogni feature porta le proprietà `id`, `ref`, `name`; il costruttore della collection è del chiamante.
 */
final readonly class MapRenderRequest
{
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
}
