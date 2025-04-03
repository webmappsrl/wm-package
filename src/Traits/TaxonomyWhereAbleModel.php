<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Builder;

trait TaxonomyWhereAbleModel
{
    public function scopeByWhereProperty(Builder $query, array $properties)
    {
        $whereProperty = $properties['taxonomy_where'] ?? [];
        if (count($whereProperty) === 0) {
            return;
        }

        $query
            ->where(function ($query) use ($whereProperty) { // LOGIC OPERATOR AND
                $layerWhereIdentifiers = collect($whereProperty)->keys();
                $query->orWhere(function (Builder $query) use ($layerWhereIdentifiers) { // LOGIC OPERATOR OR
                    foreach ($layerWhereIdentifiers as $key => $value) {
                        $query->whereRaw("properties->'taxonomy_where' ? '$value'");
                    }
                });
            });
    }
}
