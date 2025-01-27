<?php

namespace Wm\WmPackage\Traits;

use Wm\WmPackage\Models\EcMedia;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait FeatureImageAbleModel
{
    public function featureImage(): BelongsTo
    {
        return $this->belongsTo(EcMedia::class, 'feature_image');
    }

    public function ecMedia(): BelongsToMany
    {
        return $this->belongsToMany(EcMedia::class);
    }
}
