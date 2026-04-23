<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;
use Wm\WmPackage\Models\Layer;

trait TaxonomyWhereAbleModel
{
    public function dispatchFeatureCollectionRegeneration(): void
    {
        if ($this instanceof Layer) {
            $this->featureCollections()
                ->where('mode', 'generated')
                ->where('enabled', true)
                ->each(function ($fc) {
                    GenerateFeatureCollectionJob::dispatch($fc->id);
                });
        }
    }

    public function scopeByWhereProperty(Builder $query, array $properties)
    {
        $whereProperty = $properties['taxonomy_where'] ?? [];
        if (count($whereProperty) === 0) {
            // $query->where('id', '=', 0); // return empty collection if no where property is set

            return;
        }

        $query
            ->where(function ($query) use ($whereProperty) { // LOGIC OPERATOR AND
                $layerWhereIdentifiers = collect($whereProperty)->keys();
                $query->orWhere(function (Builder $query) use ($layerWhereIdentifiers) { // LOGIC OPERATOR OR
                    foreach ($layerWhereIdentifiers as $value) {
                        $query->whereNotNull("properties->taxonomy_where->$value");
                    }
                });
            });
        // Log::info($query->toSql());
    }
}
