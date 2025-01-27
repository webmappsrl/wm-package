<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wm\WmPackage\Models\Abstracts\Taxonomy;

/**
 * Class TaxonomyWhere
 *
 *
 * @property string import_method
 * @property int    id
 */
class TaxonomyWhere extends Taxonomy
{
    protected $table = 'taxonomy_wheres';

    protected function getRelationKey(): string
    {
        return 'whereable';
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
