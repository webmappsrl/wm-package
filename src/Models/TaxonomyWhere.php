<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Observers\TaxonomyWhereObserver;
use Wm\WmPackage\Traits\FeatureImageAbleModel;

/**
 * Class TaxonomyWhere
 *
 *
 * @property string import_method
 * @property int    id
 */
class TaxonomyWhere extends GeometryModel
{
    use FeatureImageAbleModel, HasFactory, HasTranslations;

    public array $translatable = ['name', 'description', 'excerpt'];

    protected $table = 'taxonomy_wheres';

    protected $fillable = [
        'name',
        'import_method',
    ];

    protected static function boot()
    {
        App::observe(TaxonomyWhereObserver::class);
    }

    /**
     * All the taxonomy where imported using a sync command are not editable
     */
    public function isEditableByUserInterface(): bool
    {
        return ! $this->isImportedByExternalData();
    }

    /**
     * Check if the current taxonomy where is imported from an external source
     */
    public function isImportedByExternalData(): bool
    {
        return ! is_null($this->import_method);
    }

    public function ugc_pois(): BelongsToMany
    {
        return $this->belongsToMany(UgcPoi::class);
    }

    public function ugc_tracks(): BelongsToMany
    {
        return $this->belongsToMany(UgcTrack::class);
    }

    public function ugc_media(): BelongsToMany
    {
        return $this->belongsToMany(UgcMedia::class);
    }

    public function ecTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'taxonomy_whereable');
    }

    public function ecPois(): MorphToMany
    {
        return $this->morphedByMany(EcPoi::class, 'taxonomy_whereable');
    }

    public function layers(): MorphToMany
    {
        return $this->morphedByMany(Layer::class, 'taxonomy_whereable');
    }

    /**
     * Return the json version of the taxonomy where, avoiding the geometry
     */
    public function getJson(): array
    {
        $array = $this->toArray();

        $propertiesToClear = ['geometry'];
        foreach ($array as $property => $value) {
            if (
                in_array($property, $propertiesToClear)
                || is_null($value)
                || (is_array($value) && count($value) === 0)
            ) {
                unset($array[$property]);
            }
        }

        return $array;
    }
}
