<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

trait TaxonomyWhereAbleModel
{
    public function scopeByWhereProperty(Builder $query, array $properties)
    {
        $whereProperty = $properties['taxonomy_where'] ?? [];
        if (count($whereProperty) === 0) {
            $query->where('id', '=', 0); // return empty collection if no where property is set

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
        //Log::info($query->toSql());
    }
}
