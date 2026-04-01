<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

trait TaxonomyWhereAbleModel
{
    protected function dispatchFeatureCollectionRegeneration(): void
    {
        if ($this instanceof \Wm\WmPackage\Models\Layer) {
            $this->featureCollections()
                ->where('mode', 'generated')
                ->where('enabled', true)
                ->each(function ($fc) {
                    \Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob::dispatch($fc->id);
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
