<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMapTrait;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $osmfeatures_id
 * @property int|null $admin_level
 * @property string|null $geometry
 * @property array|null $properties
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TaxonomyWhere extends Taxonomy
{
    use FeatureCollectionMapTrait;

    protected $fillable = ['name', 'osmfeatures_id', 'admin_level', 'geometry', 'properties'];

    protected function getRelationKey(): string
    {
        return 'whereable';
    }

    public function layers(): MorphToMany
    {
        return $this->morphedByMany(Layer::class, 'taxonomy_whereable', 'taxonomy_whereables', 'taxonomy_where_id')
            ->using(TaxonomyWhereable::class);
    }

    public function ecTracks(): MorphToMany
    {
        $ecTrackModel = config('wm-package.ec_track_model', EcTrack::class);

        return $this->morphedByMany($ecTrackModel, 'taxonomy_whereable', 'taxonomy_whereables', 'taxonomy_where_id')
            ->using(TaxonomyWhereable::class);
    }

    public function getFeatureCollectionMap(): array
    {
        $tooltip = is_array($this->name)
            ? ($this->name[app()->getLocale()] ?? $this->name['it'] ?? $this->name['en'] ?? (reset($this->name) ?: 'Taxonomy Where'))
            : ($this->name ?: 'Taxonomy Where');

        return $this->getFeatureCollectionMapFromTrait([
            'tooltip' => $tooltip,
            'strokeColor' => 'rgba(37, 99, 235, 1)',
            'strokeWidth' => 2,
            'fillColor' => 'rgba(37, 99, 235, 0.2)',
        ]);
    }
}
