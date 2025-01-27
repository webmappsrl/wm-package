<?php

namespace Wm\WmPackage\Traits;

use Wm\WmPackage\Models\UgcMedia;
use Wm\WmPackage\Models\TaxonomyWhere;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
