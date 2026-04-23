<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMapTrait;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $geometry
 * @property array|null $properties
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaxonomyWhere extends Taxonomy
{
    use FeatureCollectionMapTrait;

    protected $fillable = ['name', 'geometry', 'properties'];

    public function getOsmfeaturesId(): ?string
    {
        return $this->properties['osmfeatures_id'] ?? null;
    }

    public function getAdminLevel(): ?int
    {
        $v = $this->properties['admin_level'] ?? null;

        return $v !== null ? (int) $v : null;
    }

    public function getSource(): ?string
    {
        return $this->properties['source'] ?? null;
    }

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
