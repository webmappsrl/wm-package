<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Models\UgcMedia;

trait UgcAbleModel
{
    public function ugc_media(): BelongsToMany
    {
        return $this->belongsToMany(UgcMedia::class);
    }

    // TODO: refactor as it is on EcPoi model
    public function taxonomy_wheres(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyWhere::class);
    }
}
