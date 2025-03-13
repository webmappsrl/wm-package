<?php


namespace Wm\WmPackage\Traits;

use Wm\WmPackage\Models\Layer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;


trait EcFeatureTrait
{

    public function associatedLayers(): MorphToMany
    {
        return $this->morphToMany(Layer::class, 'layerable');
    }

    /**
     * Scope a query to only a specific layer.
     */
    public function scopeWhereLayer(Builder $query, Layer $layer): void
    {
        $query
            ->whereIn('app_id', [
                $layer->app_id,
                ...$layer->associatedApps->pluck('id')->toArray(),
            ])
            ->whereNotNull('geometry');  // Controlla che la geometria non sia null

        ### TAXONOMY WHERE - strings inside properties
        $layerWhere = $layer->properties['taxonomy_where'] ?? [];
        if (count($layerWhere) > 0)
            $query
                ->where(function ($query) use ($layerWhere) { //LOGIC OPERATOR AND
                    $layerWhereIdentifiers = collect($layerWhere)->keys();
                    $query->orWhere(function (Builder $query) use ($layerWhereIdentifiers) { // LOGIC OPERATOR OR
                        foreach ($layerWhereIdentifiers as $key => $value) {
                            $query->whereRaw("properties->'taxonomy_where' ? '$value'");
                        }
                    });
                });

        ### TAXONOMY ACTIVITY - relation
        $ids = $layer->taxonomyActivities->pluck('id')->toArray() ?? [];
        if (count($ids) > 0)
            $query
                ->has('taxonomyActivities', function ($query) use ($ids) {
                    $query->whereIn('id', $ids); //whereIn = LOGIC OPERATOR OR
                });
    }
}
